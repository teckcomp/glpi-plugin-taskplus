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
                self::TABLE . '.date'       => ['<', $today],
            ],
        ]) as $row) {
            $overdueRows[] = self::format($row, $today, $nowTime);
        }

        // Ordenação em PHP, não no SQL: o controle fino ("NULL de
        // time_limit por último") é mais simples e testável aqui.
        usort($todayRows, [self::class, 'compareToday']);
        usort($overdueRows, [self::class, 'compareOverdue']);

        $done = 0;
        $lateToday = 0;
        foreach ($todayRows as $item) {
            if ($item['is_done']) {
                $done++;
            } elseif ($item['is_late']) {
                $lateToday++;
            }
        }

        // KPIs contam APENAS as tarefas próprias do Task+ (avulsas e
        // rotinas), calculados ANTES de anexar as origens nativas: elas
        // são leitura, não dá para concluí-las aqui, então entrariam
        // eternamente em "Para hoje" sem nunca migrar para "Concluídas" e
        // estragariam a taxa de conclusão do Painel (Etapa 5). A contagem
        // das nativas aparece no cabeçalho da própria seção.
        $kpis = [
            'late'    => count($overdueRows) + $lateToday,
            'today'   => count($todayRows),
            // Marcação de pendência chega na Etapa 4b; a chave já existe
            // para o JS não precisar de defensiva depois.
            'pending' => 0,
            'done'    => $done,
        ];

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

        return [
            'date'    => $today,
            'kpis'    => $kpis,
            'today'   => array_merge($todayRows, $native),
            'overdue' => $overdueRows,
        ];
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
        if (($row['plugin_taskplus_routines_id'] ?? null) !== null) {
            return ['success' => false, 'message' => __('Ocorrência de rotina não pode ser editada aqui', 'taskplus')];
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

        return ['success' => true, 'message' => __('Tarefa atualizada', 'taskplus')];
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
