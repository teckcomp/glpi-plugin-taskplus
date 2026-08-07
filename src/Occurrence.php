<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — tarefas avulsas e ocorrências (Etapa 1; JOIN com rotinas na 2b).
 *
 * Camada única de dados/ações da tabela glpi_plugin_taskplus_occurrences,
 * usada pelo endpoint ajax/occurrence.php e pelo front/today.php. Fica em
 * classe própria (e não no endpoint) para ser validável por harness.
 *
 * Regras desta etapa:
 *  - o usuário só enxerga e mexe nas PRÓPRIAS ocorrências (users_id = ele);
 *    criar para terceiros é papel do gestor (Etapa 4);
 *  - avulsa = plugin_taskplus_routines_id NULL (decisão-chave de produto);
 *    editar/excluir só vale para avulsas — ocorrência de rotina (Etapa 2)
 *    aceita apenas concluir/desfazer;
 *  - exclusão é SEMPRE soft (is_deleted = 1): preserva a trilha auditável
 *    prometida para o Histórico (Etapa 5);
 *  - toda consulta usa coluna qualificada (tabela.coluna), mesmo sem JOIN —
 *    higiene herdada do ProjectPlus (erro 1052 quando o JOIN chegar).
 */
class Occurrence
{
    public const TABLE = 'glpi_plugin_taskplus_occurrences';

    // =====================================================================
    // Payload da tela Hoje (usado no carregamento e devolvido pelo ajax)
    // =====================================================================

    /**
     * Tudo que a tela Hoje precisa para renderizar, já formatado:
     *
     *   [
     *     'date'    => 'Y-m-d' (hoje, para o input de data do modal),
     *     'kpis'    => ['today' => n, 'done' => n, 'late' => n],
     *     'today'   => [itens do dia, pendentes primeiro],
     *     'overdue' => [pendentes de dias anteriores, mais antigas primeiro],
     *   ]
     *
     * KPIs: today = total do dia (feitas + pendentes); done = concluídas do
     * dia; late = pendentes de dias anteriores + pendentes de hoje com o
     * horário-limite estourado.
     */
    public static function payload(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');

        $todayRows = [];
        foreach ($DB->request(self::baseQuery() + [
            'WHERE' => [
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
                // Pulada sai da tela do dia (fica só no Histórico)
                self::TABLE . '.is_skipped' => 0,
                self::TABLE . '.date'       => $today,
            ],
        ]) as $row) {
            $todayRows[] = self::format($row, $today, $nowTime);
        }

        $overdueRows = [];
        foreach ($DB->request(self::baseQuery() + [
            'WHERE' => [
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
                self::TABLE . '.is_done'    => 0,
                self::TABLE . '.is_skipped' => 0,
                self::TABLE . '.date'       => ['<', $today],
            ],
        ]) as $row) {
            $overdueRows[] = self::format($row, $today, $nowTime);
        }

        // Ordenação em PHP, não no SQL: o controle fino ("NULL de
        // time_limit por último") é mais simples e testável aqui.
        usort($todayRows, [self::class, 'compareToday']);
        usort($overdueRows, [self::class, 'compareOverdue']);


        // Origens nativas (Etapa 3): leitura + link, no fim da lista.
        // Falha de leitura não pode derrubar a tela — o usuário ainda
        // precisa das próprias tarefas. Cada origem tem o seu try: um
        // schema diferente em chamados não pode esconder os projetos.
        $native = [];
        try {
            $native = array_merge($native, Native::ticketTasks($usersId));
        } catch (\Throwable $e) {
            // origem indisponível: segue sem ela
        }
        try {
            $native = array_merge($native, Native::projectTasks($usersId));
        } catch (\Throwable $e) {
            // origem indisponível: segue sem ela
        }

        // Pendências (Etapa 4b): marcadas por usuário, valem para tarefa
        // própria e para item nativo. Expiram sozinhas — activeMap já
        // descarta as que passaram da data de retorno.
        $pendings = [];
        try {
            $pendings = Pending::activeMap($usersId, $today);
        } catch (\Throwable $e) {
            $pendings = [];
        }

        $todayRows   = self::applyPendings($todayRows, $pendings, Pending::TYPE_OCCURRENCE);
        $overdueRows = self::applyPendings($overdueRows, $pendings, Pending::TYPE_OCCURRENCE);
        $native      = self::applyPendings($native, $pendings, null);

        // KPIs contam APENAS as tarefas próprias (as nativas são leitura e
        // nunca migrariam para "Concluídas"), com uma exceção: pendência
        // vale para qualquer origem, então o KPI de pendentes soma todas.
        //
        // Tarefa pendente SAI de "Para hoje" e de "Atrasadas": é uma
        // espera declarada, com data de volta — senão o número do dia
        // nunca fecharia.
        $pendingCount = 0;
        $todayCount   = 0;
        $done         = 0;
        $lateToday    = 0;

        foreach ($todayRows as $item) {
            if (!empty($item['is_pending'])) {
                $pendingCount++;
                continue;
            }
            $todayCount++;
            if ($item['is_done']) {
                $done++;
            } elseif ($item['is_late']) {
                $lateToday++;
            }
        }

        $overdueCount = 0;
        foreach ($overdueRows as $item) {
            if (!empty($item['is_pending'])) {
                $pendingCount++;
                continue;
            }
            $overdueCount++;
        }

        foreach ($native as $item) {
            if (!empty($item['is_pending'])) {
                $pendingCount++;
            }
        }

        $kpis = [
            'late'    => $overdueCount + $lateToday,
            'today'   => $todayCount,
            'pending' => $pendingCount,
            'done'    => $done,
        ];

        return [
            'date'    => $today,
            'kpis'    => $kpis,
            'today'   => array_merge($todayRows, $native),
            'overdue' => $overdueRows,
        ];
    }

    /**
     * Marca em cada item se ele tem pendência ativa deste usuário.
     * `$forceType` fixa o itemtype (tarefas próprias); com null, usa o
     * `source` do item nativo (TicketTask / ProjectTask).
     */
    private static function applyPendings(array $items, array $pendings, ?string $forceType): array
    {
        $sourceMap = [
            'ticket'  => Pending::TYPE_TICKET_TASK,
            'project' => Pending::TYPE_PROJECT_TASK,
        ];

        foreach ($items as &$item) {
            $type = $forceType ?? ($sourceMap[$item['source'] ?? ''] ?? null);

            $item['pending_type']   = $type;
            $item['is_pending']     = false;
            $item['pending_reason'] = '';
            $item['pending_label']  = '';
            $item['pending_until']  = '';
            $item['pending_time']   = '';

            if ($type === null) {
                continue;
            }

            $key = $type . ':' . (int) ($item['id'] ?? 0);
            if (isset($pendings[$key])) {
                $item['is_pending']     = true;
                $item['pending_reason'] = $pendings[$key]['reason'];
                $item['pending_label']  = $pendings[$key]['label'];
                $item['pending_until']  = $pendings[$key]['until'];
                $item['pending_time']   = $pendings[$key]['time'] ?? '';
                // Pendente não é atrasada: a espera foi combinada
                $item['is_late'] = false;
            }
        }
        unset($item);

        return $items;
    }

    /**
     * SELECT + FROM + LEFT JOIN comuns às consultas da tela Hoje
     * (Etapa 2b: a ocorrência passou a poder vir de rotina, e a tela
     * agrupa por Diárias/Semanais/Mensais).
     *
     * TODA coluna vai qualificada e o SELECT é explícito de propósito:
     * `id`, `name`, `date_mod` e `is_deleted` existem nas DUAS tabelas —
     * `SELECT *` num JOIN devolveria a coluna errada e o WHERE sem
     * qualificar daria erro 1052 ("Column ... is ambiguous").
     */
    private static function baseQuery(): array
    {
        return [
            'SELECT' => [
                self::TABLE . '.id',
                self::TABLE . '.plugin_taskplus_routines_id',
                self::TABLE . '.name',
                self::TABLE . '.description',
                self::TABLE . '.category',
                self::TABLE . '.date',
                self::TABLE . '.time_limit',
                self::TABLE . '.is_done',
                self::TABLE . '.done_date',
                self::TABLE . '.is_edited',
                Routine::TABLE . '.name AS routine_name',
                Routine::TABLE . '.frequency AS routine_frequency',
            ],
            'FROM'      => self::TABLE,
            'LEFT JOIN' => [
                Routine::TABLE => [
                    'ON' => [
                        Routine::TABLE => 'id',
                        self::TABLE    => 'plugin_taskplus_routines_id',
                    ],
                ],
            ],
        ];
    }

    /**
     * Linha do banco → item do payload (formatos prontos para o JS).
     */
    private static function format(array $row, string $today, string $nowTime): array
    {
        $isDone = ((int) ($row['is_done'] ?? 0)) === 1;
        $date   = (string) ($row['date'] ?? $today);
        $limit  = $row['time_limit'] ?? null; // 'HH:MM:SS' ou NULL

        // Atrasada = pendente E (dia já passou OU horário-limite de hoje
        // estourado). Concluída nunca é atrasada.
        $isLate = !$isDone && (
            $date < $today
            || ($date === $today && $limit !== null && $limit !== '' && $limit < $nowTime)
        );

        $isRoutine = ($row['plugin_taskplus_routines_id'] ?? null) !== null;

        // Grupo da tela Hoje: 'daily'|'weekly'|'monthly' para ocorrência de
        // rotina, 'avulsa' para o resto. Rotina excluída depois de gerar a
        // ocorrência do dia deixa o JOIN sem par → cai em 'avulsa' de
        // propósito: a tarefa continua existindo e concluível, só perde o
        // agrupamento.
        $group = 'avulsa';
        if ($isRoutine && !empty($row['routine_frequency'])) {
            $group = (string) $row['routine_frequency'];
        }

        return [
            'id'          => (int) ($row['id'] ?? 0),
            // Ocorrência de rotina não é editável/excluível na tela Hoje
            'is_routine'  => $isRoutine,
            // Origem própria do Task+ (as nativas vêm de Native.php)
            'is_native'   => false,
            'is_edited'   => ((int) ($row['is_edited'] ?? 0)) === 1,
            'group'       => $group,
            'routine_name' => (string) ($row['routine_name'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'category'    => (string) ($row['category'] ?? ''),
            'date'        => $date,
            'date_label'  => substr($date, 8, 2) . '/' . substr($date, 5, 2),
            'time_limit'  => ($limit !== null && $limit !== '') ? substr((string) $limit, 0, 5) : null,
            'is_done'     => $isDone,
            'done_time'   => !empty($row['done_date']) ? substr((string) $row['done_date'], 11, 5) : null,
            'is_late'     => $isLate,
        ];
    }

    /**
     * Hoje: pendentes antes das feitas; dentro do grupo, horário-limite
     * crescente (sem limite vai para o fim); empate resolve por id.
     */
    private static function compareToday(array $a, array $b): int
    {
        if ($a['is_done'] !== $b['is_done']) {
            return $a['is_done'] ? 1 : -1;
        }
        $ta = $a['time_limit'] ?? null;
        $tb = $b['time_limit'] ?? null;
        if ($ta !== $tb) {
            if ($ta === null) {
                return 1;
            }
            if ($tb === null) {
                return -1;
            }
            return strcmp($ta, $tb);
        }
        return $a['id'] <=> $b['id'];
    }

    /**
     * Atrasadas: as mais antigas primeiro.
     */
    private static function compareOverdue(array $a, array $b): int
    {
        return [$a['date'], $a['time_limit'] ?? '99:99', $a['id']]
            <=> [$b['date'], $b['time_limit'] ?? '99:99', $b['id']];
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
            case 'toggle':
                return self::toggle($input, $usersId);
            case 'skip':
                return self::skip($input, $usersId);
            case 'unskip':
                return self::unskip($input, $usersId);
            case 'pending':
                return self::setPending($input, $usersId);
            case 'unpending':
                return self::clearPending($input, $usersId);
            case 'list':
                // Só quer o payload atualizado (o endpoint já o inclui)
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    private static function add(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $now = date('Y-m-d H:i:s');

        // plugin_taskplus_routines_id fica FORA de propósito: o DEFAULT
        // NULL da coluna é o que marca a tarefa como avulsa.
        $DB->insert(self::TABLE, $fields + [
            'users_id'         => $usersId,
            'users_id_creator' => $usersId,
            'is_done'          => 0,
            'is_skipped'       => 0,
            'is_deleted'       => 0,
            'date_creation'    => $now,
            'date_mod'         => $now,
        ]);

        return ['success' => true, 'message' => __('Tarefa criada', 'taskplus')];
    }

    private static function update(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $isRoutine = ($row['plugin_taskplus_routines_id'] ?? null) !== null;
        if ($isRoutine) {
            // Editar ocorrência de rotina altera SÓ o dia: a rotina segue
            // intacta e amanhã o cron gera uma nova, limpa. Mudar a DATA
            // fica de fora — ela é metade da UNIQUE `routine_day`, e mover
            // o dia colidiria com a ocorrência que já existe lá.
            unset($fields['date']);
            $fields['is_edited'] = 1;
        }

        $DB->update(
            self::TABLE,
            $fields + ['date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return [
            'success' => true,
            'message' => $isRoutine
                ? __('Tarefa de hoje atualizada (a rotina nao mudou)', 'taskplus')
                : __('Tarefa atualizada', 'taskplus'),
        ];
    }

    /**
     * "Pular hoje": a tarefa não se aplicava neste dia (servidor em
     * manutenção, cliente ausente, feriado interno). Sai da lista sem
     * contar como concluída nem como atrasada, e o motivo fica gravado
     * para o Histórico. A rotina segue normal e gera amanhã.
     */
    private static function skip(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        if (((int) ($row['is_done'] ?? 0)) === 1) {
            return ['success' => false, 'message' => __('Tarefa concluída não pode ser pulada', 'taskplus')];
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            return ['success' => false, 'message' => __('Informe o motivo', 'taskplus')];
        }

        $now = date('Y-m-d H:i:s');
        $DB->update(self::TABLE, [
            'is_skipped'    => 1,
            'skip_reason'   => $reason,
            'skip_date'     => $now,
            'users_id_skip' => $usersId,
            'date_mod'      => $now,
        ], [self::TABLE . '.id' => (int) $row['id']]);

        return ['success' => true, 'message' => __('Tarefa pulada hoje', 'taskplus')];
    }

    /** Desfaz o "pular" (clicou no card errado, ou o dia mudou). */
    private static function unskip(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        // skip_reason/skip_date ficam: a trilha do Histórico mostra que
        // houve um pulo desfeito, não um dia sem nada.
        $DB->update(
            self::TABLE,
            ['is_skipped' => 0, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Tarefa voltou para o dia', 'taskplus')];
    }

    /**
     * Marca pendência. Aceita tarefa própria E item nativo (chamado ou
     * tarefa de projeto): a pendência mora em tabela do plugin, então
     * nada é gravado no GLPI.
     */
    private static function setPending(array $input, int $usersId): array
    {
        $itemtype = (string) ($input['itemtype'] ?? Pending::TYPE_OCCURRENCE);
        $itemsId  = (int) ($input['id'] ?? 0);

        // Só a tarefa PRÓPRIA passa pela checagem de dono: item nativo já
        // chega filtrado pela consulta do Native (users_id_tech / equipe).
        if ($itemtype === Pending::TYPE_OCCURRENCE && self::ownRow($itemsId, $usersId) === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        return Pending::set($itemtype, $itemsId, $usersId, $input);
    }

    private static function clearPending(array $input, int $usersId): array
    {
        return Pending::clear(
            (string) ($input['itemtype'] ?? Pending::TYPE_OCCURRENCE),
            (int) ($input['id'] ?? 0),
            $usersId
        );
    }

    private static function delete(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        if (($row['plugin_taskplus_routines_id'] ?? null) !== null) {
            return ['success' => false, 'message' => __('Ocorrência de rotina não pode ser excluída aqui', 'taskplus')];
        }

        // Soft delete sempre — preserva a trilha do Histórico (Etapa 5)
        $DB->update(
            self::TABLE,
            ['is_deleted' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Tarefa excluída', 'taskplus')];
    }

    /**
     * Concluir (done=1) ou desfazer (done=0) em 1 clique.
     * Vale para QUALQUER ocorrência do próprio usuário (avulsa ou, no
     * futuro, de rotina) — grava autor e hora, exigência da auditoria.
     */
    private static function toggle(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        $done = ((int) ($input['done'] ?? 0)) === 1;

        $DB->update(
            self::TABLE,
            [
                'is_done'       => $done ? 1 : 0,
                'done_date'     => $done ? date('Y-m-d H:i:s') : null,
                'users_id_done' => $done ? $usersId : 0,
                'date_mod'      => date('Y-m-d H:i:s'),
            ],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return [
            'success' => true,
            'message' => $done
                ? __('Tarefa concluída', 'taskplus')
                : __('Conclusão desfeita', 'taskplus'),
        ];
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Valida e normaliza os campos editáveis da avulsa.
     * Devolve o array pronto para insert/update, ou a MENSAGEM de erro
     * (string) quando algo inválido chegou.
     */
    private static function cleanFields(array $input): array|string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return __('Informe o título da tarefa', 'taskplus');
        }

        $rawDate = trim((string) ($input['date'] ?? ''));
        if ($rawDate === '') {
            $date = date('Y-m-d'); // sem data = hoje
        } else {
            $date = self::validDate($rawDate);
            if ($date === null) {
                return __('Data inválida', 'taskplus');
            }
        }

        $rawTime = trim((string) ($input['time_limit'] ?? ''));
        if ($rawTime === '') {
            $time = null; // NULL = sem horário-limite
        } else {
            $time = self::validTime($rawTime);
            if ($time === null) {
                return __('Horário-limite inválido', 'taskplus');
            }
        }

        return [
            'name'        => mb_substr($name, 0, 255),
            'description' => trim((string) ($input['description'] ?? '')),
            'category'    => mb_substr(trim((string) ($input['category'] ?? '')), 0, 255),
            'date'        => $date,
            'time_limit'  => $time,
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
     * A ocorrência $id, se pertencer a $usersId e não estiver excluída.
     */
    private static function ownRow(int $id, int $usersId): ?array
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
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
            ],
        ]) as $row) {
            return $row;
        }

        return null;
    }
}
