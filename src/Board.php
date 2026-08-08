<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — Quadro kanban (Etapa 4d).
 *
 * Camada única de dados/ações da tela Quadro, usada pelo endpoint
 * ajax/board.php e pelo front/board.php. Mesmo padrão do resto do
 * plugin: payload()/handle() testáveis por harness, endpoint FINO.
 *
 * MODELO (decisão de produto nº 8 + decisão de montagem da sessão 9):
 *
 *  - Colunas = ordem canônica do usuário: as 4 fases de SISTEMA
 *    (Atrasadas · Para hoje · Pendentes · Concluídas) + as fases dos
 *    setores em que ele é MEMBRO (união, cada uma com a tag do setor).
 *    Fases legadas "sem setor" (groups_id = 0) ficam FORA do quadro —
 *    são transitórias da 4c e só o admin as vê, em Configurações.
 *
 *  - Cards = SÓ rotinas + avulsas (chamados e tarefas de projeto ficam
 *    fora — decisão nº 8). Mesmo conjunto da tela Hoje: dia atual +
 *    atrasadas, sem puladas e sem excluídas.
 *
 *  - "Guardar a fase de origem" resolve EM CÁLCULO, SEM coluna nova:
 *    Atrasadas, Pendentes e Concluídas são estados CALCULADOS
 *    (data/hora vencida, pendência ativa, is_done) que apenas SOBREPÕEM
 *    a coluna exibida. `plugin_taskplus_phases_id` guarda a fase de
 *    trabalho e NUNCA é apagada por atraso/pendência/conclusão — ao
 *    ganhar nova data, encerrar a pendência ou desfazer a conclusão, o
 *    card volta sozinho à fase gravada. Zero mudança de schema.
 *
 *  - Regras de movimento (validadas AQUI, por ação — o JS só espelha):
 *      · soltar em fase customizada ou "Para hoje" → set_phase: grava a
 *        fase; desfaz conclusão se vinha de Concluídas; encerra a
 *        pendência se vinha de Pendentes; RECUSA card atrasado (atraso
 *        se resolve concluindo, pendenciando ou dando nova data);
 *      · soltar em Concluídas → done: conclui (mesma gravação de autor e
 *        hora do 1 clique), encerrando pendência ativa antes. Nativa
 *        (4d-3, decisão nº 11): grava no GLPI via objeto nativo — tarefa
 *        de chamado vira "Feita" (sem resolver o chamado), tarefa de
 *        projeto vira 100% (vale para a equipe toda), sem texto;
 *      · soltar em Pendentes → pending: o JS abre o modal de motivo/
 *        data/hora e a gravação é a MESMA da Etapa 4b (Pending::set);
 *        concluída não fica pendente;
 *      · Atrasadas NUNCA recebe solte — o sistema move sozinho no
 *        vencimento (cálculo), e a volta é automática.
 */
class Board
{
    // =====================================================================
    // Payload da tela Quadro
    // =====================================================================

    /**
     * Tudo que a tela Quadro precisa para renderizar:
     *
     *   [
     *     'date'    => 'Y-m-d' (hoje),
     *     'columns' => [colunas na ordem canônica; ver Phase::boardColumns],
     *     'cards'   => [itens da tela Hoje SEM os nativos, cada um com
     *                   'column' = id da fase onde o card aparece],
     *   ]
     *
     * ATENÇÃO safeData(): chave nova aqui precisa entrar no board.js.
     */
    public static function payload(int $usersId): array
    {
        $memberGroups = Access::memberGroups($usersId);
        $columns      = Phase::boardColumns(array_keys($memberGroups));

        // Reusa o payload da tela Hoje: as regras de dia/atraso/pendência
        // já moram lá e os dois payloads NUNCA podem divergir.
        $data = Occurrence::payload($usersId);

        $cards = [];
        foreach (array_merge($data['today'] ?? [], $data['overdue'] ?? []) as $item) {
            // 4d-2: nativas ENTRAM no quadro — ficam em "Para hoje" (ou
            // Pendentes). 4d-3: além de Pendentes, vão para Concluídas
            // (gravando no GLPI); concluída nativa some do payload na
            // rodada seguinte, então não há coluna Concluídas para ela.
            $item['column'] = self::resolveColumn($item, $columns);

            // Chave única do card: id de chamado/projeto PODE colidir com
            // id de ocorrência — o arrasto no JS identifica pelo par
            // tipo:id, nunca pelo id cru.
            $type             = !empty($item['is_native'])
                ? (string) ($item['pending_type'] ?? 'Native')
                : 'Occurrence';
            $item['card_key'] = $type . ':' . (int) ($item['id'] ?? 0);

            $cards[] = $item;
        }

        return [
            'date'    => (string) ($data['date'] ?? date('Y-m-d')),
            'columns' => $columns,
            'cards'   => $cards,
        ];
    }

    /**
     * Em qual coluna o card aparece. Os estados calculados SOBREPÕEM a
     * fase gravada, nesta ordem: concluída · pendente · atrasada. Fora
     * deles, vale a fase gravada — se ela for visível neste quadro
     * ("Para hoje" ou customizada de setor do usuário); fase de setor
     * alheio ou excluída cai na padrão, sem sumir com o card.
     */
    private static function resolveColumn(array $item, array $columns): int
    {
        $byKey     = [];
        $customIds = [];
        $defaultId = 0;

        foreach ($columns as $col) {
            if (!empty($col['is_system'])) {
                $byKey[(string) $col['system_key']] = (int) $col['id'];
            } else {
                $customIds[] = (int) $col['id'];
            }
            if (!empty($col['is_default'])) {
                $defaultId = (int) $col['id'];
            }
        }
        if ($defaultId === 0) {
            $defaultId = (int) ($byKey['today'] ?? 0);
        }

        if (!empty($item['is_native'])) {
            // Nativa não tem onde gravar fase (nada é escrito no GLPI):
            // ou está pendente, ou fica na coluna padrão.
            if (!empty($item['is_pending'])) {
                return (int) ($byKey['pending'] ?? $defaultId);
            }
            return $defaultId;
        }

        if (!empty($item['is_done'])) {
            return (int) ($byKey['done'] ?? $defaultId);
        }
        if (!empty($item['is_pending'])) {
            return (int) ($byKey['pending'] ?? $defaultId);
        }
        if (!empty($item['is_late'])) {
            return (int) ($byKey['late'] ?? $defaultId);
        }

        $phaseId = (int) ($item['phases_id'] ?? 0);
        if ($phaseId > 0 && ($phaseId === $defaultId || in_array($phaseId, $customIds, true))) {
            return $phaseId;
        }

        return $defaultId;
    }

    // =====================================================================
    // Ações do endpoint ajax
    // =====================================================================

    /**
     * Despacha a ação vinda do POST. Sempre devolve
     * ['success' => bool, 'message' => string] — o endpoint completa com
     * o token CSRF novo e o payload atualizado do quadro.
     */
    public static function handle(string $action, array $input, int $usersId): array
    {
        switch ($action) {
            case 'set_phase':
                return self::setPhase($input, $usersId);
            case 'done':
                return self::done($input, $usersId);
            case 'pending':
                return self::pending($input, $usersId);
            case 'unpending':
                return self::unpending($input, $usersId);
            case 'update':
                return self::update($input, $usersId);
            case 'list':
                // Só quer o payload atualizado (o endpoint já o inclui)
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    /**
     * Editar pelo card (4d-2): delega para a MESMA regra da tela Hoje
     * (Occurrence::update) — ocorrência de rotina muda só o dia, data
     * bloqueada, avulsa edita tudo. Nativa não edita nada por aqui.
     */
    private static function update(array $input, int $usersId): array
    {
        if (((string) ($input['itemtype'] ?? 'Occurrence')) !== 'Occurrence') {
            return ['success' => false, 'message' => __('Item do GLPI se edita na tela dele', 'taskplus')];
        }

        return Occurrence::handle('update', $input, $usersId);
    }

    /**
     * Soltar em "Para hoje" ou em fase customizada: grava a fase de
     * trabalho. Desfaz conclusão / encerra pendência quando o card vinha
     * dessas colunas — é o gesto natural de "voltar ao fluxo".
     */
    private static function setPhase(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (((string) ($input['itemtype'] ?? 'Occurrence')) !== 'Occurrence') {
            // Nativa não tem onde gravar fase — e o id dela pode até
            // coincidir com o de uma ocorrência: a guarda evita mover a
            // tarefa errada.
            return ['success' => false, 'message' => __('Item do GLPI não entra nas fases do quadro', 'taskplus')];
        }

        $row = Occurrence::findOwn((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        $columns  = Phase::boardColumns(array_keys(Access::memberGroups($usersId)));
        $targetId = (int) ($input['phases_id'] ?? 0);

        $target = null;
        foreach ($columns as $col) {
            if ((int) $col['id'] === $targetId) {
                $target = $col;
                break;
            }
        }
        if ($target === null) {
            // Fase de setor alheio, excluída ou inventada no POST: o
            // quadro do usuário não a tem, então ela não recebe card.
            return ['success' => false, 'message' => __('Fase inválida para o seu quadro', 'taskplus')];
        }

        // Colunas de ESTADO não se gravam por set_phase: cada uma tem a
        // sua ação própria (done / pending) — e Atrasadas, nenhuma.
        if (!empty($target['is_system']) && ($target['system_key'] ?? '') !== 'today') {
            return ['success' => false, 'message' => __('Esta coluna não recebe tarefas assim', 'taskplus')];
        }

        $isDone    = ((int) ($row['is_done'] ?? 0)) === 1;
        $isPending = self::hasActivePending((int) $row['id'], $usersId);

        // Atrasada (e não concluída/pendente): mover para fase de
        // trabalho não resolve o atraso — o card continuaria em
        // Atrasadas e o solte pareceria "não ter funcionado".
        if (!$isDone && !$isPending && self::isLateRow($row)) {
            return [
                'success' => false,
                'message' => __('Tarefa atrasada: conclua, marque como pendente ou dê nova data na tela Hoje', 'taskplus'),
            ];
        }

        if ($isPending) {
            Pending::clear(Pending::TYPE_OCCURRENCE, (int) $row['id'], $usersId);
        }

        $fields = [
            'plugin_taskplus_phases_id' => $targetId,
            'date_mod'                  => date('Y-m-d H:i:s'),
        ];
        if ($isDone) {
            // Sair de Concluídas = desfazer a conclusão (mesmo efeito do
            // clique no check da tela Hoje).
            $fields['is_done']       = 0;
            $fields['done_date']     = null;
            $fields['users_id_done'] = 0;
        }

        $DB->update(
            Occurrence::TABLE,
            $fields,
            [Occurrence::TABLE . '.id' => (int) $row['id']]
        );

        if ($isDone && self::isLateRow($row)) {
            // Desfez a conclusão de tarefa com data vencida: ela reaparece
            // em Atrasadas (o atraso é calculado), não na fase solta — o
            // toast avisa para o solte não parecer "não ter funcionado".
            return [
                'success' => true,
                'message' => __('Conclusão desfeita — a tarefa venceu e voltou para Atrasadas', 'taskplus'),
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(__('Tarefa movida para "%s"', 'taskplus'), (string) $target['name']),
        ];
    }

    /**
     * Soltar em Concluídas. A fase gravada FICA — desfazer a conclusão
     * devolve o card ao lugar de onde ele saiu.
     */
    private static function done(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $itemtype = (string) ($input['itemtype'] ?? 'Occurrence');

        // 4d-3 (decisão nº 11): nativa conclui GRAVANDO NO GLPI, sempre
        // via objeto nativo e sem texto de solução. Chamado: tarefa vira
        // "Feita" (o chamado NÃO se resolve). Projeto: 100% para todos.
        if ($itemtype === Pending::TYPE_TICKET_TASK || $itemtype === Pending::TYPE_PROJECT_TASK) {
            $itemsId = (int) ($input['id'] ?? 0);
            $result  = ($itemtype === Pending::TYPE_TICKET_TASK)
                ? Native::completeTicketTask($itemsId, $usersId)
                : Native::completeProjectTask($itemsId, $usersId);

            if (!empty($result['success'])) {
                // Concluir encerra a espera — mesma regra da própria.
                // Sem pendência ativa, o clear só devolve "não achei" e
                // é ignorado de propósito.
                Pending::clear($itemtype, $itemsId, $usersId);
            }

            return $result;
        }

        if ($itemtype !== 'Occurrence') {
            return ['success' => false, 'message' => __('Ação desconhecida para este item', 'taskplus')];
        }

        $row = Occurrence::findOwn((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        if (((int) ($row['is_done'] ?? 0)) === 1) {
            return ['success' => true, 'message' => __('Tarefa já estava concluída', 'taskplus')];
        }

        if (self::hasActivePending((int) $row['id'], $usersId)) {
            // Concluir encerra a espera: pendência ativa em tarefa
            // concluída seria uma linha zumbi na trilha do Histórico.
            Pending::clear(Pending::TYPE_OCCURRENCE, (int) $row['id'], $usersId);
        }

        $DB->update(
            Occurrence::TABLE,
            [
                'is_done'       => 1,
                'done_date'     => date('Y-m-d H:i:s'),
                'users_id_done' => $usersId,
                'date_mod'      => date('Y-m-d H:i:s'),
            ],
            [Occurrence::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Tarefa concluída', 'taskplus')];
    }

    /**
     * Soltar em Pendentes (depois do modal): mesma gravação da 4b, e
     * vale para QUALQUER origem — a pendência mora em tabela do plugin,
     * nada é escrito no GLPI. Só a tarefa PRÓPRIA passa pela checagem de
     * dono: item nativo já chega filtrado pela consulta do Native.
     */
    private static function pending(array $input, int $usersId): array
    {
        $itemtype = (string) ($input['itemtype'] ?? Pending::TYPE_OCCURRENCE);
        $itemsId  = (int) ($input['id'] ?? 0);

        if ($itemtype === Pending::TYPE_OCCURRENCE) {
            $row = Occurrence::findOwn($itemsId, $usersId);
            if ($row === null) {
                return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
            }
            if (((int) ($row['is_done'] ?? 0)) === 1) {
                return ['success' => false, 'message' => __('Tarefa concluída não pode ficar pendente', 'taskplus')];
            }
        }

        return Pending::set($itemtype, $itemsId, $usersId, $input);
    }

    /**
     * Nativa pendente arrastada de volta para "Para hoje": encerra a
     * pendência (para a própria, o set_phase já faz isso no caminho).
     */
    private static function unpending(array $input, int $usersId): array
    {
        return Pending::clear(
            (string) ($input['itemtype'] ?? Pending::TYPE_OCCURRENCE),
            (int) ($input['id'] ?? 0),
            $usersId
        );
    }

    // =====================================================================
    // Auxiliares
    // =====================================================================

    /** A ocorrência tem pendência ATIVA (e não vencida) deste usuário? */
    private static function hasActivePending(int $occurrenceId, int $usersId): bool
    {
        try {
            $map = Pending::activeMap($usersId);
        } catch (\Throwable $e) {
            return false;
        }

        return isset($map[Pending::TYPE_OCCURRENCE . ':' . $occurrenceId]);
    }

    /**
     * A linha está atrasada AGORA? Mesma regra do Occurrence::format:
     * dia passado, ou dia de hoje com horário-limite estourado.
     */
    private static function isLateRow(array $row): bool
    {
        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');
        $date    = (string) ($row['date'] ?? $today);
        $limit   = $row['time_limit'] ?? null;

        return $date < $today
            || ($date === $today && $limit !== null && $limit !== '' && $limit < $nowTime);
    }
}
