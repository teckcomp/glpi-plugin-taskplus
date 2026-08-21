<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — rotinas recorrentes (Etapa 2a).
 *
 * Camada única de dados/ações da tabela glpi_plugin_taskplus_routines,
 * usada pelo endpoint ajax/routine.php e pelo front/routines.php. Mesmo
 * padrão do Occurrence.php: payload()/handle() testáveis por harness, o
 * endpoint ajax/ fica FINO.
 *
 * Escopo desta etapa (2a): só CRUD + pausa/retomada. A leitura da rotina
 * para GERAR ocorrências do dia (isDueOn / cron taskplusgen) fica para a
 * 2b — não faz sentido misturar as duas áreas no mesmo bloco.
 *
 * Regras:
 *  - o usuário ENXERGA as próprias rotinas (users_id = ele); quem MEXE
 *    (editar/pausar/excluir) é quem CRIOU (users_id_creator) — decisão
 *    nº 57 (A-1): rotina que o gestor criou para o técnico é do gestor
 *    para controlar; o técnico a lê e age só nas tarefas do dia;
 *  - o gestor vê, na mesma tela, as rotinas que criou para terceiros
 *    agrupadas em LOTE (a criação por setor grava N linhas no mesmo
 *    segundo) e age sobre o lote inteiro — ver batchKey()/batches();
 *  - frequency: 'daily' | 'weekly' | 'monthly'; cada uma valida só os
 *    campos que lhe dizem respeito — os demais vão zerados/vazios;
 *  - monthly aceita OU dia fixo (monthday) OU posição
 *    (monthweek+monthweekday), nunca os dois nem nenhum;
 *  - exclusão é SEMPRE soft (is_deleted = 1) — mesma trilha do Histórico
 *    (Etapa 5) prometida para Occurrence;
 *  - toda consulta usa coluna qualificada (tabela.coluna), mesmo sem
 *    JOIN — higiene herdada do ProjectPlus (erro 1052 quando o JOIN da
 *    Etapa 2b/3 chegar).
 */
class Routine
{
    public const TABLE = 'glpi_plugin_taskplus_routines';

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** ISO-8601: 1 = segunda … 7 = domingo. */
    private const WEEKDAY_SHORT = [
        1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui',
        5 => 'Sex', 6 => 'Sáb', 7 => 'Dom',
    ];

    private const WEEKDAY_FULL = [
        1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira',
        4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo',
    ];

    private const POSITION_LABEL = [
        1 => '1ª', 2 => '2ª', 3 => '3ª', 4 => '4ª', 5 => '5ª', -1 => 'última',
    ];

    // =====================================================================
    // Payload da tela Rotinas
    // =====================================================================

    /**
     * Tudo que a tela Rotinas precisa para renderizar:
     *
     *   [
     *     'today'    => 'Y-m-d' (para o input "Início" do modal),
     *     'routines' => [itens, nome ASC],
     *   ]
     */
    public static function payload(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
            ],
            'ORDER' => self::TABLE . '.name ASC',
        ]) as $row) {
            $rows[] = self::format($row);
        }

        // 5c-2: nome de quem criou a rotina, quando não foi o próprio
        // dono (gestor pela Equipe) — mesmo padrão do fillActorLabels
        // da Occurrence: uma consulta para todos os ids.
        $rows = self::fillCreatorLabels($rows);

        return [
            'today'    => date('Y-m-d'),
            'routines' => $rows,
            // A-1 (decisão nº 57): rotinas que EU criei para terceiros,
            // agrupadas em lote. Chave nova → safeData do routines.js.
            'batches'  => self::batches($usersId),
        ];
    }

    // =====================================================================
    // Controle (decisão nº 57): quem criou, controla
    // =====================================================================

    /**
     * $usersId controla a rotina $row? Regra única para editar, pausar e
     * excluir, na tela do dono e na do criador:
     *  - criador registrado: só ele;
     *  - sem criador registrado (linha anterior à 5c-2, users_id_creator
     *    = 0): o próprio dono.
     * Pura — o harness a exercita direto.
     */
    public static function controls(array $row, int $usersId): bool
    {
        $creator = (int) ($row['users_id_creator'] ?? 0);
        if ($creator > 0) {
            return $creator === $usersId;
        }
        return ((int) ($row['users_id'] ?? 0)) === $usersId;
    }

    /**
     * Chave de LOTE de uma rotina criada para terceiro: a criação por
     * setor (Team::createGroupRoutine) grava N linhas IDÊNTICAS no
     * mesmo segundo, com o mesmo criador. Como só o criador edita (e o
     * batch_update grava o mesmo conteúdo em todas), as linhas do lote
     * continuam iguais pela vida toda — a chave se reconstitui a partir
     * dos dados, sem coluna nova, inclusive nas rotinas já existentes.
     * Pura.
     */
    public static function batchKey(array $row): string
    {
        $parts = [];
        foreach ([
            'users_id_creator', 'date_creation', 'name', 'instructions',
            'frequency', 'only_workdays', 'weekdays', 'monthday',
            'monthweek', 'monthweekday', 'time_limit', 'date_begin', 'date_end',
        ] as $field) {
            $parts[] = (string) ($row[$field] ?? '');
        }
        return md5(implode('|', $parts));
    }

    /**
     * Linhas VIVAS criadas por $usersId para OUTROS donos, em ordem
     * estável (nome, id). Base do payload de lotes e da revalidação de
     * cada ação em lote (T18: relido a cada POST).
     */
    private static function createdForOthers(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.users_id_creator' => $usersId,
                self::TABLE . '.is_deleted'       => 0,
            ],
            'ORDER' => [self::TABLE . '.name ASC', self::TABLE . '.id ASC'],
        ]) as $row) {
            if (((int) ($row['users_id'] ?? 0)) !== $usersId) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Lotes do criador $usersId, prontos para o JS:
     *
     *   [ ['key', campos da rotina (do 1º membro), 'count',
     *      'paused_count', 'is_paused' (todos), 'members' => [
     *          ['id', 'users_id', 'label', 'is_paused'], ... ]], ... ]
     *
     * Um técnico só = lote de 1 (rotina criada pela Equipe para ele).
     */
    public static function batches(int $usersId): array
    {
        $byKey = [];
        foreach (self::createdForOthers($usersId) as $row) {
            $key = self::batchKey($row);
            if (!isset($byKey[$key])) {
                $item = self::format($row);
                unset($item['created_by_other'], $item['created_by_id'], $item['created_by_label']);
                $byKey[$key] = $item + [
                    'key'          => $key,
                    'count'        => 0,
                    'paused_count' => 0,
                    'members'      => [],
                ];
            }
            $paused = ((int) ($row['is_paused'] ?? 0)) === 1;
            $byKey[$key]['members'][] = [
                'id'        => (int) ($row['id'] ?? 0),
                'users_id'  => (int) ($row['users_id'] ?? 0),
                'label'     => '',
                'is_paused' => $paused,
            ];
            $byKey[$key]['count']++;
            if ($paused) {
                $byKey[$key]['paused_count']++;
            }
        }

        $userIds = [];
        foreach ($byKey as $batch) {
            foreach ($batch['members'] as $m) {
                $userIds[$m['users_id']] = true;
            }
        }
        $labels = self::userLabels(array_keys($userIds));

        $out = [];
        foreach ($byKey as $batch) {
            foreach ($batch['members'] as &$m) {
                $m['label'] = $labels[$m['users_id']] ?? ('#' . $m['users_id']);
            }
            unset($m);
            usort($batch['members'], static function (array $a, array $b): int {
                return [mb_strtolower($a['label']), $a['id']]
                    <=> [mb_strtolower($b['label']), $b['id']];
            });
            // O lote está pausado quando TODOS os membros estão
            $batch['is_paused'] = $batch['count'] > 0 && $batch['paused_count'] === $batch['count'];
            $out[] = $batch;
        }
        return $out;
    }

    /**
     * Nome exibido de cada usuário em UMA consulta: [id => label].
     */
    private static function userLabels(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($ids === []) {
            return [];
        }
        $labels = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['glpi_users.id' => $ids ?: [0]],
        ]) as $row) {
            $label = trim(
                (string) ($row['firstname'] ?? '') . ' ' . (string) ($row['realname'] ?? '')
            );
            if ($label === '') {
                $label = (string) ($row['name'] ?? '');
            }
            $labels[(int) ($row['id'] ?? 0)] = $label;
        }
        return $labels;
    }

    /**
     * Preenche `created_by_label` nas rotinas criadas por OUTRO usuário.
     * Autor excluído do GLPI deixa o label vazio — o front simplesmente
     * não mostra o badge.
     */
    private static function fillCreatorLabels(array $rows): array
    {
        $ids = [];
        foreach ($rows as $item) {
            if (!empty($item['created_by_other']) && (int) ($item['created_by_id'] ?? 0) > 0) {
                $ids[(int) $item['created_by_id']] = true;
            }
        }
        if ($ids === []) {
            return $rows;
        }

        $labels = self::userLabels(array_keys($ids));

        foreach ($rows as &$item) {
            if (!empty($item['created_by_other'])) {
                $item['created_by_label'] = $labels[(int) ($item['created_by_id'] ?? 0)] ?? '';
            }
        }
        unset($item);

        return $rows;
    }

    /**
     * Linha do banco → item do payload (formatos prontos para o JS).
     */
    private static function format(array $row): array
    {
        $frequency = (string) ($row['frequency'] ?? 'daily');

        return [
            'id'               => (int) ($row['id'] ?? 0),
            'name'             => (string) ($row['name'] ?? ''),
            'instructions'     => (string) ($row['instructions'] ?? ''),
            'frequency'        => $frequency,
            'frequency_label'  => self::frequencyLabel($frequency),
            'only_workdays'    => ((int) ($row['only_workdays'] ?? 0)) === 1,
            'weekdays'         => self::weekdaysToArray((string) ($row['weekdays'] ?? '')),
            'monthday'         => (int) ($row['monthday'] ?? 0),
            'monthweek'        => (int) ($row['monthweek'] ?? 0),
            'monthweekday'     => (int) ($row['monthweekday'] ?? 0),
            'recurrence_label' => self::recurrenceLabel($row),
            'time_limit'       => (!empty($row['time_limit'])) ? substr((string) $row['time_limit'], 0, 5) : null,
            'is_paused'        => ((int) ($row['is_paused'] ?? 0)) === 1,
            'date_begin'       => (!empty($row['date_begin'])) ? (string) $row['date_begin'] : null,
            'date_end'         => (!empty($row['date_end'])) ? (string) $row['date_end'] : null,
            // Autoria da criação (5c-2): rotina criada por OUTRO usuário
            // (gestor pela Equipe). Nome resolvido em lote no payload.
            'created_by_other' => ((int) ($row['users_id_creator'] ?? 0)) > 0
                && ((int) ($row['users_id_creator'] ?? 0)) !== ((int) ($row['users_id'] ?? 0)),
            'created_by_id'    => (int) ($row['users_id_creator'] ?? 0),
            'created_by_label' => '',
            // A-1 (decisão nº 57): o DONO só edita/pausa/exclui o que ele
            // mesmo criou. O JS esconde os botões; o servidor recusa de
            // qualquer jeito (controlledRow).
            'can_manage'       => self::controls($row, (int) ($row['users_id'] ?? 0)),
        ];
    }

    private static function frequencyLabel(string $frequency): string
    {
        switch ($frequency) {
            case 'daily':
                return __('Diária', 'taskplus');
            case 'weekly':
                return __('Semanal', 'taskplus');
            case 'monthly':
                return __('Mensal', 'taskplus');
            default:
                return $frequency;
        }
    }

    /**
     * Descrição legível da recorrência ("Seg, Qua, Sex" / "Dia 15 do mês" /
     * "última sexta-feira"). Composta em tempo de execução — por isso não
     * passa pelo __() (xgettext não extrai concatenação); é texto pt-BR
     * direto, mesmo padrão de "fonte da verdade = pt-BR" do restante do
     * plugin. Tradução desses fragmentos fica para quando entrar en-GB.
     */
    private static function recurrenceLabel(array $row): string
    {
        $frequency = (string) ($row['frequency'] ?? 'daily');

        if ($frequency === 'daily') {
            return ((int) ($row['only_workdays'] ?? 0)) === 1
                ? 'Todo dia útil'
                : 'Todo dia';
        }

        if ($frequency === 'weekly') {
            $days = self::weekdaysToArray((string) ($row['weekdays'] ?? ''));
            if (empty($days)) {
                return 'Nenhum dia selecionado';
            }
            $labels = array_map(static function (int $d): string {
                return self::WEEKDAY_SHORT[$d] ?? '?';
            }, $days);
            return implode(', ', $labels);
        }

        if ($frequency === 'monthly') {
            $monthday = (int) ($row['monthday'] ?? 0);
            if ($monthday > 0) {
                return 'Dia ' . $monthday . ' do mês';
            }
            $week    = (int) ($row['monthweek'] ?? 0);
            $weekday = (int) ($row['monthweekday'] ?? 0);
            if ($week !== 0 && $weekday !== 0) {
                $pos = self::POSITION_LABEL[$week] ?? '?';
                $wd  = self::WEEKDAY_FULL[$weekday] ?? '?';
                return $pos . ' ' . $wd;
            }
            return 'Sem posição definida';
        }

        return '';
    }

    // =====================================================================
    // Ações do endpoint ajax
    // =====================================================================

    /**
     * Despacha a ação vinda do POST. Sempre devolve
     * ['success' => bool, 'message' => string] — o endpoint completa com
     * o token CSRF novo e o payload atualizado.
     */
    public static function handle(string $action, array $input, int $usersId): array
    {
        switch ($action) {
            case 'add':
                return self::add($input, $usersId);
            case 'update':
                return self::update($input, $usersId);
            case 'delete':
                return self::delete($input, $usersId);
            case 'pause':
                return self::pause($input, $usersId);
            case 'batch_update':
                return self::batchUpdate($input, $usersId);
            case 'batch_delete':
                return self::batchDelete($input, $usersId);
            case 'batch_pause':
                return self::batchPause($input, $usersId);
            case 'list':
                // Só quer o payload atualizado (o endpoint já o inclui)
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    private static function add(array $input, int $usersId): array
    {
        // Dono criando para si: dono = criador
        return self::addFor($input, $usersId, $usersId);
    }

    /**
     * Cria uma ROTINA para o dono $ownerId com $creatorId como AUTOR
     * (5c-2: gestor pela tela Equipe). A rotina é DO TÉCNICO — aparece
     * na tela Rotinas dele com controle total (editar/pausar/excluir,
     * decisão da abertura do 5c-2); a autoria vira badge "criada pelo
     * gestor". O generateForDate propaga users_id_creator para cada
     * ocorrência gerada, então o badge da tela Hoje/Equipe sai de
     * graça, sem mudança no cron. Escopo já validado no Team::handle;
     * os CAMPOS passam pelo cleanFields de sempre.
     *
     * $generate (5c-3): a criação em LOTE para o setor chama addFor uma
     * vez por membro — gerar as ocorrências dentro de cada chamada
     * seria N varreduras completas da tabela de rotinas. O loop passa
     * false e dispara generateForDate() UMA vez ao fim. O default true
     * preserva o comportamento do 5c-2 em todos os caminhos existentes.
     */
    public static function addFor(array $input, int $ownerId, int $creatorId, bool $generate = true): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $now = date('Y-m-d H:i:s');

        $DB->insert(self::TABLE, $fields + [
            'users_id'         => $ownerId,
            'users_id_creator' => $creatorId,
            'is_paused'        => 0,
            'is_deleted'       => 0,
            'date_creation'    => $now,
            'date_mod'         => $now,
        ]);

        // Rotina devida HOJE gera a ocorrência do dia NA HORA: sem isso,
        // "criei e não apareceu" — o cron só passaria em até 30 min
        // (aresta achada na homologação do 5c-2). generateForDate é
        // idempotente (occurrenceExists), então a passada extra é segura
        // e cobre também quem cria rotina para si na tela Rotinas.
        if ($generate) {
            self::generateForDate();
        }

        return ['success' => true, 'message' => __('Rotina criada', 'taskplus')];
    }

    private static function update(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::controlledRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Rotina não encontrada', 'taskplus')];
        }

        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $DB->update(
            self::TABLE,
            $fields + ['date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Rotina atualizada', 'taskplus')];
    }

    private static function delete(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::controlledRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Rotina não encontrada', 'taskplus')];
        }

        // Soft delete sempre — preserva a trilha do Histórico (Etapa 5).
        // Ocorrências já geradas (Etapa 2b) não são tocadas aqui.
        $DB->update(
            self::TABLE,
            ['is_deleted' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Rotina excluída', 'taskplus')];
    }

    /**
     * Pausar (paused=1) ou retomar (paused=0) a rotina. Rotina pausada
     * não é considerada pelo cron taskplusgen (regra da Etapa 2b).
     */
    private static function pause(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::controlledRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Rotina não encontrada', 'taskplus')];
        }

        $paused = ((int) ($input['paused'] ?? 0)) === 1;

        $DB->update(
            self::TABLE,
            ['is_paused' => $paused ? 1 : 0, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return [
            'success' => true,
            'message' => $paused
                ? __('Rotina pausada', 'taskplus')
                : __('Rotina retomada', 'taskplus'),
        ];
    }

    // =====================================================================
    // Ações em LOTE (A-1, decisão nº 57) — o criador sobre N rotinas
    // =====================================================================

    /**
     * Linhas do lote $key que $usersId criou, relidas do banco AGORA
     * (T18). Vazio = lote inexistente ou de outro criador.
     */
    private static function batchRows(string $key, int $usersId): array
    {
        if ($key === '') {
            return [];
        }
        $rows = [];
        foreach (self::createdForOthers($usersId) as $row) {
            if (self::batchKey($row) === $key) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function batchUpdate(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = self::batchRows((string) ($input['batch'] ?? ''), $usersId);
        if ($rows === []) {
            return ['success' => false, 'message' => __('Lote não encontrado', 'taskplus')];
        }

        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $DB->update(
            self::TABLE,
            $fields + ['date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => $ids]
        );

        return [
            'success' => true,
            'message' => sprintf(__('Rotina atualizada para %d técnico(s)', 'taskplus'), count($ids)),
        ];
    }

    private static function batchDelete(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = self::batchRows((string) ($input['batch'] ?? ''), $usersId);
        if ($rows === []) {
            return ['success' => false, 'message' => __('Lote não encontrado', 'taskplus')];
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $DB->update(
            self::TABLE,
            ['is_deleted' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => $ids]
        );

        return [
            'success' => true,
            'message' => sprintf(__('Rotina excluída para %d técnico(s)', 'taskplus'), count($ids)),
        ];
    }

    private static function batchPause(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = self::batchRows((string) ($input['batch'] ?? ''), $usersId);
        if ($rows === []) {
            return ['success' => false, 'message' => __('Lote não encontrado', 'taskplus')];
        }

        $paused = ((int) ($input['paused'] ?? 0)) === 1;
        $ids    = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $DB->update(
            self::TABLE,
            ['is_paused' => $paused ? 1 : 0, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => $ids]
        );

        return [
            'success' => true,
            'message' => sprintf(
                $paused
                    ? __('Rotina pausada para %d técnico(s)', 'taskplus')
                    : __('Rotina retomada para %d técnico(s)', 'taskplus'),
                count($ids)
            ),
        ];
    }

    // =====================================================================
    // Geração de ocorrências (Etapa 2b — usada pelo cron taskplusgen)
    // =====================================================================

    /**
     * A rotina $routine deve gerar ocorrência no dia $date ('Y-m-d')?
     *
     * Função PURA (nenhum acesso a banco) de propósito: é a regra mais
     * sujeita a erro do plugin inteiro (meses de 28/29/30/31 dias,
     * "última sexta", dia 31 em mês sem 31) e precisa ser exercitável por
     * harness com bateria de datas.
     *
     * Decisões de calendário desta etapa:
     *  - "só dias úteis" = segunda a sexta (ISO 1..5). Feriado NÃO é
     *    considerado (o calendário de feriados do GLPI entra em etapa
     *    futura, se o uso pedir);
     *  - mensal por DIA FIXO em mês curto: dia 31 numa base de 30 dias cai
     *    no ÚLTIMO dia do mês (31/jan, 28/fev, 31/mar...). A alternativa
     *    seria simplesmente pular o mês, o que faria uma rotina "dia 31"
     *    nunca rodar em fevereiro — pior para o usuário;
     *  - mensal por POSIÇÃO com monthweek = 5 num mês em que aquele dia da
     *    semana só ocorre 4 vezes: não gera (5ª segunda é 5ª segunda; quem
     *    quer "a última" escolhe -1).
     */
    public static function isDueOn(array $routine, string $date): bool
    {
        if (((int) ($routine['is_deleted'] ?? 0)) === 1) {
            return false;
        }
        if (((int) ($routine['is_paused'] ?? 0)) === 1) {
            return false;
        }

        $begin = (string) ($routine['date_begin'] ?? '');
        if ($begin !== '' && $date < $begin) {
            return false;
        }
        $end = (string) ($routine['date_end'] ?? '');
        if ($end !== '' && $date > $end) {
            return false;
        }

        // Meio-dia para não sofrer com horário de verão na conversão
        $ts = strtotime($date . ' 12:00:00');
        if ($ts === false) {
            return false;
        }

        $iso         = (int) date('N', $ts); // 1 = segunda … 7 = domingo
        $dayOfMonth  = (int) date('j', $ts);
        $daysInMonth = (int) date('t', $ts);

        $frequency = (string) ($routine['frequency'] ?? 'daily');

        if ($frequency === 'daily') {
            if (((int) ($routine['only_workdays'] ?? 0)) === 1) {
                return $iso <= 5;
            }
            return true;
        }

        if ($frequency === 'weekly') {
            return in_array($iso, self::weekdaysToArray((string) ($routine['weekdays'] ?? '')), true);
        }

        if ($frequency === 'monthly') {
            $monthday = (int) ($routine['monthday'] ?? 0);
            if ($monthday > 0) {
                // Mês curto: cai no último dia (ver comentário acima)
                return $dayOfMonth === min($monthday, $daysInMonth);
            }

            $week    = (int) ($routine['monthweek'] ?? 0);
            $weekday = (int) ($routine['monthweekday'] ?? 0);
            if ($week === 0 || $weekday === 0 || $weekday !== $iso) {
                return false;
            }

            if ($week === -1) {
                // Última ocorrência daquele dia da semana no mês:
                // não existe outra 7 dias depois.
                return ($dayOfMonth + 7) > $daysInMonth;
            }

            // 1ª/2ª/…: a n-ésima ocorrência cai no bloco de 7 dias nº n
            return (intdiv($dayOfMonth - 1, 7) + 1) === $week;
        }

        return false;
    }

    /**
     * Materializa as ocorrências de $date a partir das rotinas ativas.
     *
     * Vive aqui (e não no Cron) para ser exercitável por harness — o
     * Cron::cronTaskplusgen só chama e loga.
     *
     * IDEMPOTENTE: antes de inserir, checa se já existe ocorrência da
     * rotina naquele dia (INCLUSIVE excluída — a UNIQUE `routine_day` do
     * banco não distingue is_deleted, e reinserir ressuscitaria algo que o
     * usuário tirou da frente). Rodar de hora em hora não duplica nada.
     *
     * Devolve ['created' => n, 'skipped' => n, 'routines' => n].
     */
    public static function generateForDate(?string $date = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $date = $date ?? date('Y-m-d');
        $now  = date('Y-m-d H:i:s');

        $created  = 0;
        $skipped  = 0;
        $examined = 0;

        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.is_deleted' => 0,
                self::TABLE . '.is_paused'  => 0,
            ],
        ]) as $routine) {
            $examined++;

            if (!self::isDueOn($routine, $date)) {
                continue;
            }

            if (self::occurrenceExists((int) $routine['id'], $date)) {
                $skipped++;
                continue;
            }

            $DB->insert(Occurrence::TABLE, [
                'plugin_taskplus_routines_id' => (int) $routine['id'],
                'name'             => (string) ($routine['name'] ?? ''),
                // As instruções da rotina viram a descrição do dia: quem
                // executa vê o "como fazer" sem sair da tela Hoje.
                'description'      => (string) ($routine['instructions'] ?? ''),
                'category'         => '',
                'users_id'         => (int) ($routine['users_id'] ?? 0),
                'users_id_creator' => (int) ($routine['users_id_creator'] ?? 0),
                'date'             => $date,
                'time_limit'       => $routine['time_limit'] ?? null,
                'is_done'          => 0,
                'is_skipped'       => 0,
                'is_deleted'       => 0,
                'date_creation'    => $now,
                'date_mod'         => $now,
            ]);
            $created++;
        }

        return [
            'created'  => $created,
            'skipped'  => $skipped,
            'routines' => $examined,
        ];
    }

    /**
     * Já existe ocorrência desta rotina neste dia? Propositalmente SEM
     * filtro de is_deleted (ver comentário de generateForDate).
     */
    private static function occurrenceExists(int $routineId, string $date): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach ($DB->request([
            'FROM'  => Occurrence::TABLE,
            'WHERE' => [
                Occurrence::TABLE . '.plugin_taskplus_routines_id' => $routineId,
                Occurrence::TABLE . '.date'                        => $date,
            ],
        ]) as $ignored) {
            return true;
        }

        return false;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Valida e normaliza os campos editáveis da rotina. Devolve o array
     * pronto para insert/update, ou a MENSAGEM de erro (string) quando
     * algo inválido chegou.
     */
    private static function cleanFields(array $input): array|string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return __('Informe o nome da rotina', 'taskplus');
        }

        $frequency = (string) ($input['frequency'] ?? '');
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            return __('Frequência inválida', 'taskplus');
        }

        $onlyWorkdays = 0;
        $weekdays     = '';
        $monthday     = 0;
        $monthweek    = 0;
        $monthweekday = 0;

        if ($frequency === 'daily') {
            $onlyWorkdays = ((int) ($input['only_workdays'] ?? 0)) === 1 ? 1 : 0;
        } elseif ($frequency === 'weekly') {
            $days = self::cleanWeekdays((string) ($input['weekdays'] ?? ''));
            if ($days === null) {
                return __('Selecione ao menos um dia da semana', 'taskplus');
            }
            $weekdays = implode(',', $days);
        } elseif ($frequency === 'monthly') {
            $rawMonthday = trim((string) ($input['monthday'] ?? ''));
            $rawWeek     = trim((string) ($input['monthweek'] ?? ''));
            $rawWeekday  = trim((string) ($input['monthweekday'] ?? ''));

            $hasDay = $rawMonthday !== '' && (int) $rawMonthday > 0;
            $hasPos = $rawWeek !== '' && $rawWeekday !== '';

            // XOR: exatamente um dos dois modos, nunca os dois nem nenhum.
            if ($hasDay === $hasPos) {
                return __('Informe o dia fixo OU a posição do mês, não os dois', 'taskplus');
            }

            if ($hasDay) {
                $monthday = (int) $rawMonthday;
                if ($monthday < 1 || $monthday > 31) {
                    return __('Dia do mês inválido', 'taskplus');
                }
            } else {
                $monthweek    = (int) $rawWeek;
                $monthweekday = (int) $rawWeekday;
                if (!in_array($monthweek, [1, 2, 3, 4, 5, -1], true)) {
                    return __('Posição do mês inválida', 'taskplus');
                }
                if ($monthweekday < 1 || $monthweekday > 7) {
                    return __('Dia da semana inválido', 'taskplus');
                }
            }
        }

        $rawTime = trim((string) ($input['time_limit'] ?? ''));
        $time    = null;
        if ($rawTime !== '') {
            $time = self::validTime($rawTime);
            if ($time === null) {
                return __('Horário-limite inválido', 'taskplus');
            }
        }

        $rawBegin = trim((string) ($input['date_begin'] ?? ''));
        $begin    = $rawBegin === '' ? date('Y-m-d') : self::validDate($rawBegin);
        if ($begin === null) {
            return __('Data de início inválida', 'taskplus');
        }

        $rawEnd = trim((string) ($input['date_end'] ?? ''));
        $end    = null;
        if ($rawEnd !== '') {
            $end = self::validDate($rawEnd);
            if ($end === null) {
                return __('Data de término inválida', 'taskplus');
            }
            if ($end < $begin) {
                return __('Término não pode ser antes do início', 'taskplus');
            }
        }

        return [
            'name'          => mb_substr($name, 0, 255),
            'instructions'  => trim((string) ($input['instructions'] ?? '')),
            'frequency'     => $frequency,
            'only_workdays' => $onlyWorkdays,
            'weekdays'      => $weekdays,
            'monthday'      => $monthday,
            'monthweek'     => $monthweek,
            'monthweekday'  => $monthweekday,
            'time_limit'    => $time,
            'date_begin'    => $begin,
            'date_end'      => $end,
        ];
    }

    /**
     * 'Y-m-d' válido, ou null.
     */
    private static function validDate(string $raw): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $raw;
    }

    /**
     * 'HH:MM' (ou 'HH:MM:SS') → 'HH:MM:SS', ou null se inválido.
     */
    private static function validTime(string $raw): ?string
    {
        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            return null;
        }
        if ((int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }
        return $m[1] . ':' . $m[2] . ':00';
    }

    /**
     * Lista de dias ISO (string "1,3,5") → array de int válidos [1..7],
     * únicos, ordenados. Devolve null se nenhum dia sobrar (obrigatório
     * ao menos um em weekly).
     */
    private static function cleanWeekdays(string $raw): ?array
    {
        $days = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $n = (int) $part;
            if ($n >= 1 && $n <= 7 && !in_array($n, $days, true)) {
                $days[] = $n;
            }
        }
        if (empty($days)) {
            return null;
        }
        sort($days);
        return $days;
    }

    /**
     * "1,3,5" (do banco) → [1, 3, 5]. Sem validação (já validado ao
     * gravar) — defesa mínima contra lixo residual.
     */
    private static function weekdaysToArray(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = (int) $part;
            }
        }
        return $out;
    }

    /**
     * A rotina $id, viva, se $usersId a CONTROLA (decisão nº 57: quem
     * criou). Relida do banco a cada chamada (T18). Substitui o ownRow
     * da 2a, que filtrava pelo dono.
     */
    private static function controlledRow(int $id, int $usersId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($id <= 0) {
            return null;
        }

        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.id'         => $id,
                self::TABLE . '.is_deleted' => 0,
            ],
        ]) as $row) {
            return self::controls($row, $usersId) ? $row : null;
        }

        return null;
    }
}
