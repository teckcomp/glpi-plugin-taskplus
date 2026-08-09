<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — tela "Equipe" (Etapa 5a: leitura para o gestor).
 *
 * Camada única de dados E ações da tela Equipe, usada pelo
 * front/team.php e pelo ajax/team.php (5b-1). O payload inteiro
 * (placar + tarefas de cada técnico) vai embutido na página; as ações
 * do gestor chegam por POST no endpoint e passam por handle(), que
 * valida o ESCOPO por ação (técnico membro de setor gerido, nunca item
 * nativo — decisão nº 12) antes de delegar a escrita à Occurrence, que
 * reverifica posse/estado na hora (T18).
 *
 * MODELO (decisões da abertura da Etapa 5):
 *
 *  - Quem vê: admin (direito nativo `config` UPDATE) vê TODOS os
 *    setores; gestor (`plugin_taskplus_manage` + `is_manager` em pelo
 *    menos um grupo) vê SÓ os setores que administra — a régua é
 *    Access::managedGroups(), a MESMA das fases (4c-2), nunca a de
 *    membro (que é a leitura do Quadro).
 *
 *  - Técnicos = MEMBROS dos setores administrados (qualquer vínculo em
 *    glpi_groups_users conta, gestor incluso — ele também tem dia).
 *    Usuário em mais de um setor aparece UMA vez, com todos os chips.
 *
 *  - O dia de cada técnico vem de Occurrence::payload($techId) — o
 *    MESMO payload da tela Hoje e do Quadro. Reusar em vez de
 *    reconsultar garante que gestor e técnico veem números idênticos
 *    (KPIs, pendências expirando, nativas, tudo). Falha no payload de
 *    UM técnico não derruba a tela: ele aparece zerado com aviso.
 *
 *  - Nativas (chamado/projeto) aparecem na lista expandida como
 *    leitura + link, como na tela Hoje (decisão nº 12): o gestor NUNCA
 *    age sobre item nativo de outro técnico — a decisão nº 3 / lição
 *    T18 exige que quem grava seja o próprio dono (users_id_tech).
 */
class Team
{
    // =====================================================================
    // Payload da tela Equipe
    // =====================================================================

    /**
     * Tudo que a tela Equipe precisa para renderizar:
     *
     *   [
     *     'date'   => 'Y-m-d' (hoje),
     *     'groups' => [nomes dos setores administrados, ordenados],
     *     'techs'  => [por técnico: id, label, groups, kpis, items],
     *   ]
     *
     * ATENÇÃO safeData(): chave nova aqui precisa entrar no team.js.
     */
    public static function payload(int $usersId, ?string $from = null, ?string $to = null): array
    {
        // 5b-2 p2: MESMA normalização das outras telas (uma régua só) —
        // o eco daqui e o recorte de cada técnico nunca divergem.
        [$from, $to]  = Occurrence::periodRange($from, $to);
        $periodActive = ($from !== null || $to !== null);

        $isAdmin = Access::isPhaseAdmin();
        $groups  = Access::managedGroups($usersId, $isAdmin);

        $members = self::members(array_keys($groups), $groups);

        $techs = [];
        foreach ($members as $techId => $info) {
            // O dia de um técnico com dado ruim não pode esconder o
            // resto da equipe: ele entra zerado, marcado com load_error,
            // e o JS mostra o aviso na linha dele.
            try {
                $occ = Occurrence::payload($techId, $from, $to);
                $techs[] = self::techEntry($techId, $info['label'], $info['groups'], $occ);
            } catch (\Throwable $e) {
                $entry               = self::techEntry($techId, $info['label'], $info['groups'], []);
                $entry['load_error'] = true;
                $techs[]             = $entry;
            }
        }

        // Ordem estável e previsível: alfabética pelo nome exibido;
        // empate (homônimos) resolve por id.
        usort($techs, static function (array $a, array $b): int {
            return [mb_strtolower($a['label']), $a['id']]
                <=> [mb_strtolower($b['label']), $b['id']];
        });

        $groupNames = array_values($groups);
        sort($groupNames, SORT_FLAG_CASE | SORT_NATURAL);

        return [
            'date'    => date('Y-m-d'),
            'groups'  => $groupNames,
            // 5c-3: setores administrados COM id — alimenta o seletor de
            // setor do modal de criação (destino "todos do setor").
            // Chave nova → safeData do team.js.
            'sectors' => self::sectorOptions($groups),
            'techs'   => $techs,
            // Eco do recorte normalizado (5b-2 p2). Chave nova → safeData.
            'period' => [
                'from'   => $from ?? '',
                'to'     => $to ?? '',
                'active' => $periodActive,
            ],
        ];
    }

    // =====================================================================
    // Ações do gestor (5b-1) — endpoint ajax/team.php
    // =====================================================================

    /**
     * Despacha a ação do gestor. Mesmo contrato do Occurrence::handle:
     * devolve ['success' => bool, 'message' => string] e o endpoint
     * completa com o token CSRF novo e o payload atualizado da Equipe.
     *
     * 5b-1: `toggle` (concluir/desfazer). 5b-2: `update` (editar),
     * `pending` (marcar) e `unpending` (liberar). `list` existe para o
     * JS pedir só o re-render. Toda ação passa por managedTech() —
     * escopo revalidado a cada POST (T18) — e delega a escrita à
     * Occurrence, que reverifica posse na hora. Item nativo nunca tem
     * caminho até aqui (decisão nº 12).
     */
    public static function handle(string $action, array $input, int $usersId): array
    {
        switch ($action) {
            case 'toggle':
                return self::toggleTech($input, $usersId);
            case 'update':
                return self::updateTech($input, $usersId);
            case 'pending':
                return self::pendingTech($input, $usersId);
            case 'unpending':
                return self::unpendingTech($input, $usersId);
            case 'create':
                return self::createTech($input, $usersId);
            case 'create_routine':
                return self::createRoutineTech($input, $usersId);
            case 'create_group':
                return self::createGroup($input, $usersId);
            case 'create_group_routine':
                return self::createGroupRoutine($input, $usersId);
            case 'list':
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    /**
     * Validação de ESCOPO comum a todas as ações do gestor (reexecutada
     * a cada POST — T18, nada herdado do carregamento da tela):
     *  1. o gestor ainda administra pelo menos um setor;
     *  2. o técnico é MEMBRO (ativo, não excluído) de setor gerido —
     *     a MESMA régua members() que monta a tela, para a ação nunca
     *     alcançar quem a tela não mostra.
     *
     * Devolve ['tech_id' => int, 'label' => string] ou a MENSAGEM de
     * erro (string) — mesmo padrão do cleanFields da Occurrence.
     */
    private static function managedTech(array $input, int $usersId): array|string
    {
        $isAdmin = Access::isPhaseAdmin();
        $groups  = Access::managedGroups($usersId, $isAdmin);
        if ($groups === []) {
            return __('Você não administra nenhum setor', 'taskplus');
        }

        $techId = (int) ($input['tech_id'] ?? 0);
        if ($techId <= 0) {
            return __('Técnico inválido', 'taskplus');
        }

        $members = self::members(array_keys($groups), $groups);
        if (!isset($members[$techId])) {
            return __('Técnico fora dos setores que você administra', 'taskplus');
        }

        return ['tech_id' => $techId, 'label' => $members[$techId]['label']];
    }

    /**
     * Concluir/desfazer tarefa PRÓPRIA do Task+ de um técnico da equipe.
     * Posse/estado validados dentro de Occurrence::toggleFor, que só
     * enxerga a tabela de ocorrências. Auditoria: users_id_done =
     * GESTOR (autor), users_id = técnico (dono) — o payload exibe
     * "pelo gestor <nome>" quando diferem.
     */
    private static function toggleTech(array $input, int $usersId): array
    {
        $tech = self::managedTech($input, $usersId);
        if (is_string($tech)) {
            return ['success' => false, 'message' => $tech];
        }

        $done = ((int) ($input['done'] ?? 0)) === 1;

        $result = Occurrence::toggleFor((int) ($input['id'] ?? 0), $tech['tech_id'], $usersId, $done);
        if ($result['success']) {
            $result['message'] = $done
                ? sprintf(__('Tarefa de %s concluída', 'taskplus'), $tech['label'])
                : sprintf(__('Conclusão de %s desfeita', 'taskplus'), $tech['label']);
        }
        return $result;
    }

    /**
     * Editar tarefa própria do Task+ do técnico (5b-2). As regras são
     * as MESMAS da tela Hoje dele (Occurrence::updateFor): ocorrência
     * de rotina edita só o dia e a DATA fica de fora.
     */
    private static function updateTech(array $input, int $usersId): array
    {
        $tech = self::managedTech($input, $usersId);
        if (is_string($tech)) {
            return ['success' => false, 'message' => $tech];
        }

        $result = Occurrence::updateFor($input, $tech['tech_id']);
        if ($result['success']) {
            $result['message'] = sprintf(__('Tarefa de %s atualizada', 'taskplus'), $tech['label']);
        }
        return $result;
    }

    /**
     * Marcar pendência na tarefa própria do técnico (5b-2). A pendência
     * é DO TÉCNICO (aparece e expira na tela Hoje dele); o gestor entra
     * como AUTOR (users_id_creator) — trilha + badge "pelo gestor".
     * Motivo/data/hora obrigatórios como sempre (regra da 4b, validada
     * em Pending::set). O JS da Equipe nunca envia itemtype: aqui é
     * SEMPRE ocorrência própria (decisão nº 12).
     */
    private static function pendingTech(array $input, int $usersId): array
    {
        $tech = self::managedTech($input, $usersId);
        if (is_string($tech)) {
            return ['success' => false, 'message' => $tech];
        }

        // Trava explícita: mesmo que um POST forjado mande itemtype de
        // nativa, a Equipe só marca pendência em ocorrência própria.
        unset($input['itemtype']);

        $result = Occurrence::setPendingFor($input, $tech['tech_id'], $usersId);
        if ($result['success']) {
            $result['message'] = sprintf(__('Tarefa de %s marcada como pendente', 'taskplus'), $tech['label']);
        }
        return $result;
    }

    /**
     * Liberar (encerrar) a pendência da tarefa própria do técnico —
     * ela volta ao fluxo normal do dia dele.
     */
    private static function unpendingTech(array $input, int $usersId): array
    {
        $tech = self::managedTech($input, $usersId);
        if (is_string($tech)) {
            return ['success' => false, 'message' => $tech];
        }

        unset($input['itemtype']);

        $result = Occurrence::clearPendingFor($input, $tech['tech_id']);
        if ($result['success']) {
            $result['message'] = sprintf(__('Pendência de %s encerrada', 'taskplus'), $tech['label']);
        }
        return $result;
    }

    /**
     * Criar AVULSA para o técnico (5c-1). O escopo é o mesmo de toda
     * ação da Equipe (managedTech, revalidado a cada POST — T18); os
     * CAMPOS passam pelo cleanFields de sempre dentro da Occurrence. A
     * tarefa nasce DO TÉCNICO (users_id) com o gestor como AUTOR
     * (users_id_creator) — badge "criada pelo gestor" nas duas pontas.
     * Rotina para o técnico fica para o 5c-2.
     */
    private static function createTech(array $input, int $usersId): array
    {
        $tech = self::managedTech($input, $usersId);
        if (is_string($tech)) {
            return ['success' => false, 'message' => $tech];
        }

        $result = Occurrence::addFor($input, $tech['tech_id'], $usersId);
        if ($result['success']) {
            $result['message'] = sprintf(__('Tarefa criada para %s', 'taskplus'), $tech['label']);
        }
        return $result;
    }

    /**
     * Criar ROTINA para o técnico (5c-2). Mesmo escopo do create
     * (managedTech a cada POST — T18); campos validados pelo
     * cleanFields da Routine (frequência, dias, XOR do mensal). A
     * rotina nasce DO TÉCNICO com controle total dele (decisão da
     * abertura); o gestor fica na autoria — badge na tela Rotinas e,
     * via generateForDate, em cada ocorrência gerada.
     */
    private static function createRoutineTech(array $input, int $usersId): array
    {
        $tech = self::managedTech($input, $usersId);
        if (is_string($tech)) {
            return ['success' => false, 'message' => $tech];
        }

        $result = Routine::addFor($input, $tech['tech_id'], $usersId);
        if ($result['success']) {
            $result['message'] = sprintf(__('Rotina criada para %s', 'taskplus'), $tech['label']);
        }
        return $result;
    }

    // =====================================================================
    // Criação em lote para o setor (5c-3)
    // =====================================================================

    /**
     * Mapa [groups_id => nome] → lista ordenada [['id','name'], ...]
     * para o seletor de setor do modal. Pura (testável): a ordenação é
     * a mesma dos chips (natural, sem caixa), e a chave numérica vira
     * campo explícito — JSON de mapa com chave inteira viraria objeto
     * com chaves-string no JS e a ordem se perderia.
     */
    public static function sectorOptions(array $groups): array
    {
        $options = [];
        foreach ($groups as $gid => $name) {
            $options[] = ['id' => (int) $gid, 'name' => (string) $name];
        }
        usort($options, static function (array $a, array $b): int {
            return [mb_strtolower($a['name']), $a['id']]
                <=> [mb_strtolower($b['name']), $b['id']];
        });
        return $options;
    }

    /**
     * Validação de ESCOPO do destino "todos do setor" — o espelho do
     * managedTech, reexecutada a cada POST (T18): o setor enviado tem
     * que estar entre os que o gestor administra AGORA (a MESMA régua
     * managedGroups da tela e do seletor).
     *
     * Devolve ['group_id' => int, 'name' => string] ou a MENSAGEM de
     * erro (string).
     */
    private static function managedSector(array $input, int $usersId): array|string
    {
        $isAdmin = Access::isPhaseAdmin();
        $groups  = Access::managedGroups($usersId, $isAdmin);
        if ($groups === []) {
            return __('Você não administra nenhum setor', 'taskplus');
        }

        $groupId = (int) ($input['group_id'] ?? 0);
        if ($groupId <= 0 || !isset($groups[$groupId])) {
            return __('Setor fora dos que você administra', 'taskplus');
        }

        return ['group_id' => $groupId, 'name' => $groups[$groupId]];
    }

    /**
     * Destinatários da criação em lote: os MEMBROS do setor (a mesma
     * régua members() da tela — ativo, não excluído), com o gestor
     * regido pelo checkbox "incluir a mim" (decisão da abertura 5c-3):
     *
     *  - marcado: o gestor SEMPRE entra (membro ou não — admin que
     *    administra sem ser membro também pode se incluir);
     *  - desmarcado: o gestor sai da lista mesmo sendo membro.
     *
     * Devolve a lista de users_id (pode ficar vazia — quem chama trata).
     */
    private static function groupRecipients(int $groupId, string $groupName, int $usersId, bool $includeMe): array
    {
        $ids = array_keys(self::members([$groupId], [$groupId => $groupName]));

        if ($includeMe) {
            if (!in_array($usersId, $ids, true)) {
                $ids[] = $usersId;
            }
        } else {
            $ids = array_values(array_filter($ids, static function (int $id) use ($usersId): bool {
                return $id !== $usersId;
            }));
        }

        return $ids;
    }

    /**
     * Criar AVULSA para TODOS do setor (5c-3): uma CÓPIA por membro —
     * o modelo de um dono por tarefa fica intacto, cada técnico vê e
     * conclui a sua. Os campos são validados pelo cleanFields dentro do
     * primeiro addFor; como o input é IDÊNTICO para todos, erro de
     * campo aborta na primeira chamada com ZERO inserts (o addFor
     * valida antes de gravar).
     */
    private static function createGroup(array $input, int $usersId): array
    {
        $sector = self::managedSector($input, $usersId);
        if (is_string($sector)) {
            return ['success' => false, 'message' => $sector];
        }

        $includeMe  = ((int) ($input['include_me'] ?? 0)) === 1;
        $recipients = self::groupRecipients($sector['group_id'], $sector['name'], $usersId, $includeMe);
        if ($recipients === []) {
            return ['success' => false, 'message' => __('Nenhum destinatário no setor', 'taskplus')];
        }

        $created = 0;
        foreach ($recipients as $ownerId) {
            $result = Occurrence::addFor($input, $ownerId, $usersId);
            if (!$result['success']) {
                // Só acontece na PRIMEIRA volta (validação de campos,
                // idêntica para todos): devolve o erro com nada gravado.
                if ($created === 0) {
                    return $result;
                }
                continue;
            }
            $created++;
        }

        return [
            'success' => true,
            'message' => sprintf(
                __('Tarefa criada para %d técnico(s) do setor %s', 'taskplus'),
                $created,
                $sector['name']
            ),
        ];
    }

    /**
     * Criar ROTINA para TODOS do setor (5c-3): N rotinas INDEPENDENTES
     * (decisão da abertura) — cada técnico é dono da sua e a pausa,
     * edita ou exclui sem afetar os colegas; o gestor fica na autoria
     * de todas. A geração da ocorrência de hoje (regra do 5c-2) roda
     * UMA vez ao fim do loop, não uma por membro — por isso o addFor
     * recebe $generate = false aqui.
     */
    private static function createGroupRoutine(array $input, int $usersId): array
    {
        $sector = self::managedSector($input, $usersId);
        if (is_string($sector)) {
            return ['success' => false, 'message' => $sector];
        }

        $includeMe  = ((int) ($input['include_me'] ?? 0)) === 1;
        $recipients = self::groupRecipients($sector['group_id'], $sector['name'], $usersId, $includeMe);
        if ($recipients === []) {
            return ['success' => false, 'message' => __('Nenhum destinatário no setor', 'taskplus')];
        }

        $created = 0;
        foreach ($recipients as $ownerId) {
            $result = Routine::addFor($input, $ownerId, $usersId, false);
            if (!$result['success']) {
                if ($created === 0) {
                    return $result;
                }
                continue;
            }
            $created++;
        }

        // Rotina devida hoje aparece NA HORA para toda a equipe — uma
        // varredura só, idempotente (mesma regra do 5c-2, decisão nº 15).
        Routine::generateForDate();

        return [
            'success' => true,
            'message' => sprintf(
                __('Rotina criada para %d técnico(s) do setor %s', 'taskplus'),
                $created,
                $sector['name']
            ),
        ];
    }

    /**
     * Entrada de UM técnico no payload, a partir do payload da tela Hoje
     * dele. Método público e puro (sem DB, sem sessão) de propósito: é o
     * coração testável da tela — o harness o exercita direto.
     */
    public static function techEntry(int $techId, string $label, array $groupNames, array $occ): array
    {
        $kpis = is_array($occ['kpis'] ?? null) ? $occ['kpis'] : [];

        // 5b-2 p2: a data-base do payload (hoje) desce até o itemRow —
        // no modo período, item ABERTO de outro dia ganha "de DD/MM" no
        // detalhe (sem isso, uma tarefa futura pareceria "para hoje").
        $payloadDate = (string) ($occ['date'] ?? '');

        $items = [];
        // Atrasadas vêm da lista própria do payload; o flag de origem
        // garante o status mesmo se is_late vier inesperadamente falso.
        foreach ((array) ($occ['overdue'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = self::itemRow($item, true, $payloadDate);
            }
        }
        foreach ((array) ($occ['today'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = self::itemRow($item, false, $payloadDate);
            }
        }

        // Ordem de leitura do gestor = ordem dos KPIs: Atrasadas ·
        // Para hoje · Pendentes · Concluídas. Dentro do grupo preserva a
        // ordem que a tela Hoje já calculou (usort estável no PHP 8).
        usort($items, static function (array $a, array $b): int {
            return $a['weight'] <=> $b['weight'];
        });

        return [
            'id'         => $techId,
            'label'      => $label,
            'groups'     => array_values($groupNames),
            'load_error' => false,
            'kpis'       => [
                'late'    => (int) ($kpis['late'] ?? 0),
                'today'   => (int) ($kpis['today'] ?? 0),
                'pending' => (int) ($kpis['pending'] ?? 0),
                'done'    => (int) ($kpis['done'] ?? 0),
            ],
            'items'      => $items,
        ];
    }

    /**
     * Item do payload da tela Hoje → linha enxuta da lista expandida.
     * Pura (testável). O status segue a MESMA precedência do Quadro
     * (Board::resolveColumn): pendente · concluída · atrasada · do dia.
     */
    public static function itemRow(array $item, bool $fromOverdue, string $payloadDate = ''): array
    {
        $status = 'today';
        if (!empty($item['is_pending'])) {
            $status = 'pending';
        } elseif (!empty($item['is_done'])) {
            $status = 'done';
        } elseif ($fromOverdue || !empty($item['is_late'])) {
            $status = 'late';
        }

        $detail = '';
        switch ($status) {
            case 'pending':
                // Ex.: "volta em 12/08 14:30" — já formatado pela 4b
                $detail = (string) ($item['pending_label'] ?? '');
                break;
            case 'done':
                $doneTime = (string) ($item['done_time'] ?? '');
                if (!empty($item['was_overdue'])) {
                    // De dia anterior, concluída hoje (4d-2)
                    $detail = sprintf(
                        __('de %s · concluída %s', 'taskplus'),
                        (string) ($item['date_label'] ?? ''),
                        $doneTime
                    );
                } elseif ($doneTime !== '') {
                    $detail = sprintf(__('concluída %s', 'taskplus'), $doneTime);
                } else {
                    $detail = __('concluída', 'taskplus');
                }
                break;
            case 'late':
                $detail = sprintf(__('de %s', 'taskplus'), (string) ($item['date_label'] ?? ''));
                if (!empty($item['time_limit'])) {
                    $detail .= ' · ' . (string) $item['time_limit'];
                }
                break;
            default:
                // Aberta de OUTRO dia (modo período): a data entra no
                // detalhe. $payloadDate vazio (chamada legada) mantém o
                // comportamento antigo — só o horário-limite.
                $itemDate = (string) ($item['date'] ?? '');
                if ($payloadDate !== '' && $itemDate !== '' && $itemDate !== $payloadDate
                    && !empty($item['date_label'])) {
                    $detail = sprintf(__('de %s', 'taskplus'), (string) $item['date_label']);
                }
                if (!empty($item['time_limit'])) {
                    $limit  = sprintf(__('até %s', 'taskplus'), (string) $item['time_limit']);
                    $detail = ($detail !== '') ? $detail . ' · ' . $limit : $limit;
                }
        }

        $weights = ['late' => 0, 'today' => 1, 'pending' => 2, 'done' => 3];

        $isNative = !empty($item['is_native']);
        $ownId    = $isNative ? 0 : (int) ($item['id'] ?? 0);
        $isOwn    = !$isNative && $ownId > 0;

        return [
            // Id da OCORRÊNCIA (5b-1): é o que os botões de ação enviam
            // ao endpoint. Zero nas nativas — elas nem ganham botão.
            'id'          => $ownId,
            'name'        => (string) ($item['name'] ?? ''),
            'status'      => $status,
            'weight'      => $weights[$status],
            'detail'      => $detail,
            // Ações do gestor, DECIDIDAS NO SERVIDOR (o JS só obedece).
            // Todas exigem tarefa PRÓPRIA do Task+ (decisão nº 12):
            //  - can_act (5b-1): concluir/desfazer — fora de pendência
            //    (a precedência pendente > concluída esconderia o efeito);
            //  - can_edit (5b-2): editar — só não-concluída e fora de
            //    pendência (mesma leitura da tela Hoje);
            //  - can_pend (5b-2): marcar pendência — só o que ainda está
            //    em aberto (atrasada ou do dia);
            //  - can_unpend (5b-2): liberar — só o que está pendente.
            'can_act'     => $isOwn && $status !== 'pending',
            'can_edit'    => $isOwn && ($status === 'late' || $status === 'today'),
            'can_pend'    => $isOwn && ($status === 'late' || $status === 'today'),
            'can_unpend'  => $isOwn && $status === 'pending',
            'is_done'     => $status === 'done',
            // Campos do modal de edição (5b-2) — vazios nas nativas
            'description' => $isOwn ? (string) ($item['description'] ?? '') : '',
            'category'    => $isOwn ? (string) ($item['category'] ?? '') : '',
            'date'        => $isOwn ? (string) ($item['date'] ?? '') : '',
            'time_limit'  => $isOwn ? (string) ($item['time_limit'] ?? '') : '',
            'is_routine'  => $isOwn && !empty($item['is_routine']),
            // Repactuação de pendência parte dos valores atuais
            'pending_reason' => $isOwn ? (string) ($item['pending_reason'] ?? '') : '',
            'pending_until'  => $isOwn ? (string) ($item['pending_until'] ?? '') : '',
            'pending_time'   => $isOwn ? (string) ($item['pending_time'] ?? '') : '',
            // Auditoria: nome de quem concluiu (5b-1) / marcou a
            // pendência (5b-2), QUANDO não foi o próprio técnico —
            // vazio no caso normal; vira badge no JS
            'done_by'     => (!empty($item['done_by_other']))
                ? (string) ($item['done_by_label'] ?? '')
                : '',
            'pending_by'  => (!empty($item['pending_by_other']))
                ? (string) ($item['pending_by_label'] ?? '')
                : '',
            // 5c-1: quem CRIOU a tarefa, quando não foi o próprio
            // técnico (gestor pela Equipe) — vira badge no JS
            'created_by'  => (!empty($item['created_by_other']))
                ? (string) ($item['created_by_label'] ?? '')
                : '',
            'is_native'   => $isNative,
            // 'ticket' | 'project' | '' (própria)
            'source'    => (string) ($item['source'] ?? ''),
            // Link do item nativo (abre no GLPI); vazio nas próprias
            'url'       => !empty($item['is_native']) ? (string) ($item['url'] ?? '') : '',
        ];
    }

    // =====================================================================
    // Membros dos setores administrados
    // =====================================================================

    /**
     * Usuários MEMBROS dos grupos $groupIds, deduplicados:
     * [users_id => ['label' => nome exibido, 'groups' => [nomes]]].
     *
     * Duas consultas simples e junção em PHP (mesma higiene do
     * Access::managedGroups): dispensa JOIN com glpi_users e o
     * erro 1052 junto. Usuário desativado ou excluído fica fora —
     * a régua de "quem aparece na Equipe" é gente que ainda trabalha.
     */
    private static function members(array $groupIds, array $groupNames): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($groupIds === []) {
            return [];
        }

        $byUser = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => ['glpi_groups_users.groups_id' => $groupIds],
        ]) as $row) {
            $uid = (int) ($row['users_id'] ?? 0);
            $gid = (int) ($row['groups_id'] ?? 0);
            if ($uid > 0 && isset($groupNames[$gid])) {
                $byUser[$uid][$gid] = $groupNames[$gid];
            }
        }

        if ($byUser === []) {
            return [];
        }

        $members = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => [
                // Lista nunca vazia aqui (early return acima), mas a
                // regra "filtro nunca some" fica explícita mesmo assim
                'glpi_users.id'         => array_keys($byUser) ?: [0],
                'glpi_users.is_deleted' => 0,
                'glpi_users.is_active'  => 1,
            ],
        ]) as $row) {
            $uid = (int) ($row['id'] ?? 0);

            $label = trim(
                (string) ($row['firstname'] ?? '') . ' ' . (string) ($row['realname'] ?? '')
            );
            if ($label === '') {
                $label = (string) ($row['name'] ?? '');
            }

            $names = $byUser[$uid] ?? [];
            sort($names, SORT_FLAG_CASE | SORT_NATURAL);

            $members[$uid] = [
                'label'  => $label,
                'groups' => $names,
            ];
        }

        return $members;
    }
}
