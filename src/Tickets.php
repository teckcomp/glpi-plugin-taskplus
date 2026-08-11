<?php

namespace GlpiPlugin\Taskplus;

use Ticket;

/**
 * Task+ — CHAMADOS nativos do usuário (Etapa 8a, decisão de produto nº 10).
 *
 * Primeira coluna da tela Hoje em 3 colunas: os chamados de `glpi_tickets`
 * em que o usuário é ATRIBUÍDO, OBSERVADOR ou REQUERENTE (decisão da
 * abertura da Etapa 8, 10/08/2026), com todos os status exceto
 * Solucionado/Fechado, ordenados pelo PRAZO DE SLA mais próximo.
 *
 * Regras fechadas na abertura:
 *  - papéis somados: a mesma pessoa pode ser atribuído E requerente do
 *    mesmo chamado — o card mostra os papéis acumulados;
 *  - prioridade de exibição = SLA: menor prazo entre "tempo para aceitar"
 *    (time_to_own, só enquanto o chamado não foi assumido) e "tempo para
 *    solucionar" (time_to_resolve); estourado vem primeiro; sem SLA vai
 *    para o fim, por prioridade e data;
 *  - card SOMENTE LEITURA: a única ação é abrir a página nativa do
 *    chamado. Escrita em chamado, nunca (regra nº 1 do produto);
 *  - fora dos KPIs e fora do recorte por período (nativas nunca em
 *    recorte por dia — premissa 3 da decisão nº 14);
 *  - fica FORA do Occurrence::payload de propósito: Team/Board/Week o
 *    reusam e não podem pagar esta consulta por técnico. Quem anexa é o
 *    front/today.php e o ajax/occurrence.php, em chave própria `tickets`.
 *
 * Leitura pura, sem escrita — mesmo espírito (e mesmos guardas) do
 * src/Native.php da Etapa 3.
 */
class Tickets
{
    public const TICKETS_TABLE  = 'glpi_tickets';
    public const ACTORS_TABLE   = 'glpi_tickets_users';
    public const ENTITIES_TABLE = 'glpi_entities';

    /**
     * Papéis de `glpi_tickets_users.type` (CommonITILActor). Fixados aqui
     * de propósito, como as STATE_* do Native: constante estável para o
     * harness rodar sem o core.
     */
    public const ROLE_REQUESTER = 1;
    public const ROLE_ASSIGN    = 2;
    public const ROLE_OBSERVER  = 3;

    /** Ordem de exibição dos papéis no badge (decisão da abertura). */
    private const ROLE_LABELS = [
        self::ROLE_ASSIGN    => 'atribuído',
        self::ROLE_OBSERVER  => 'observador',
        self::ROLE_REQUESTER => 'requerente',
    ];

    /**
     * Chamados do usuário no formato de card da coluna 1 da tela Hoje.
     *
     * Devolve [] em qualquer erro de leitura (mesma filosofia do Native:
     * origem nativa é acessório — se o JOIN falhar, o usuário ainda tem
     * as próprias tarefas).
     */
    public static function forUser(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($usersId <= 0) {
            return [];
        }

        // 1) Em quais chamados eu apareço, e com quais papéis?
        //    (dois passos, como o projectTasks: legível e testável — a
        //    lista de chamados vivos de uma pessoa é sempre pequena)
        $roles = []; // tickets_id => [type, ...]
        foreach ($DB->request([
            'SELECT' => [
                self::ACTORS_TABLE . '.tickets_id',
                self::ACTORS_TABLE . '.type',
            ],
            'FROM'  => self::ACTORS_TABLE,
            'WHERE' => [
                self::ACTORS_TABLE . '.users_id' => $usersId,
                self::ACTORS_TABLE . '.type'     => [
                    self::ROLE_REQUESTER,
                    self::ROLE_ASSIGN,
                    self::ROLE_OBSERVER,
                ],
            ],
        ]) as $row) {
            $tid  = (int) ($row['tickets_id'] ?? 0);
            $type = (int) ($row['type'] ?? 0);
            if ($tid <= 0 || !isset(self::ROLE_LABELS[$type])) {
                continue;
            }
            if (!isset($roles[$tid])) {
                $roles[$tid] = [];
            }
            if (!in_array($type, $roles[$tid], true)) {
                $roles[$tid][] = $type;
            }
        }

        if (empty($roles)) {
            return [];
        }

        // 2) Os chamados em si. SELECT explícito e colunas qualificadas
        //    (regra da casa — `name`/`status` existem em várias tabelas).
        $select = [
            self::TICKETS_TABLE . '.id',
            self::TICKETS_TABLE . '.name',
            self::TICKETS_TABLE . '.content',
            self::TICKETS_TABLE . '.status',
            self::TICKETS_TABLE . '.priority',
            self::TICKETS_TABLE . '.date',
            self::TICKETS_TABLE . '.time_to_own',
            self::TICKETS_TABLE . '.time_to_resolve',
            self::ENTITIES_TABLE . '.name AS entity_name',
        ];

        // "Já foi assumido?" decide se o time_to_own ainda conta como
        // prazo. A coluna varia com a versão — filtrar/selecionar coluna
        // inexistente derruba a consulta com 1054, então só entra se
        // existir mesmo (mesmo guarda do Native::hasField).
        $hasTakeInto = self::hasField(self::TICKETS_TABLE, 'takeintoaccount_delay_stat');
        if ($hasTakeInto) {
            $select[] = self::TICKETS_TABLE . '.takeintoaccount_delay_stat';
        }

        $criteria = [
            'SELECT'    => $select,
            'FROM'      => self::TICKETS_TABLE,
            'LEFT JOIN' => [
                self::ENTITIES_TABLE => [
                    'ON' => [
                        self::ENTITIES_TABLE => 'id',
                        self::TICKETS_TABLE  => 'entities_id',
                    ],
                ],
            ],
            'WHERE' => [
                self::TICKETS_TABLE . '.id'         => array_keys($roles),
                self::TICKETS_TABLE . '.is_deleted' => 0,
                'NOT' => [
                    self::TICKETS_TABLE . '.status' => self::finishedStatuses(),
                ],
            ],
        ];

        $entities = self::entityCriteria(self::TICKETS_TABLE);
        if (!empty($entities)) {
            // Chaves distintas das de cima (entities_id): merge seguro.
            $criteria['WHERE'] = array_merge($criteria['WHERE'], $entities);
        }

        $now   = date('Y-m-d H:i:s');
        $items = [];
        foreach ($DB->request($criteria) as $row) {
            $items[] = self::format($row, $roles, $now, $hasTakeInto);
        }

        // Ordenação em PHP, não no SQL (padrão da casa): com SLA primeiro,
        // pelo prazo mais próximo — estourado naturalmente sobe; sem SLA
        // no fim, por prioridade desc e data asc.
        usort($items, [self::class, 'compare']);

        return $items;
    }

    /**
     * Linha do banco → card da coluna Chamados.
     *
     * `id` = 0 de propósito (T15): item nativo nunca expõe id no espaço
     * numérico das ocorrências — o identificador útil é `ticket_id`.
     */
    private static function format(array $row, array $roles, string $now, bool $hasTakeInto): array
    {
        $ticketId = (int) ($row['id'] ?? 0);
        $status   = (int) ($row['status'] ?? 0);
        $priority = (int) ($row['priority'] ?? 0);
        $today    = substr($now, 0, 10);

        // Papéis acumulados, na ordem fixa do badge
        $mine       = $roles[$ticketId] ?? [];
        $roleLabels = [];
        foreach (self::ROLE_LABELS as $type => $label) {
            if (in_array($type, $mine, true)) {
                $roleLabels[] = $label;
            }
        }

        // Prazo de SLA mais próximo (decisão da abertura):
        //  - time_to_own conta enquanto o chamado NÃO foi assumido;
        //  - time_to_resolve conta sempre que existir.
        $candidates = [];
        $tto        = self::validDateTime($row['time_to_own'] ?? null);
        $ttr        = self::validDateTime($row['time_to_resolve'] ?? null);

        $taken = $hasTakeInto
            ? ((int) ($row['takeintoaccount_delay_stat'] ?? 0) > 0)
            // Sem a coluna no schema, o melhor sinal é o status: só o
            // "Novo" ainda não foi assumido.
            : ($status !== 1);

        if ($tto !== null && !$taken) {
            $candidates[] = $tto;
        }
        if ($ttr !== null) {
            $candidates[] = $ttr;
        }

        $slaDue = empty($candidates) ? null : min($candidates);

        return [
            'id'             => 0,
            'is_native'      => true,
            'source'         => 'glpi_ticket',
            'group'          => 'glpi_ticket',
            'is_routine'     => false,
            'ticket_id'      => $ticketId,
            'ticket_label'   => '#' . $ticketId,
            'name'           => (string) ($row['name'] ?? ''),
            'description'    => self::excerpt((string) ($row['content'] ?? '')),
            'category'       => '',
            'status'         => $status,
            'status_label'   => self::statusLabel($status),
            'priority'       => $priority,
            'priority_label' => self::priorityLabel($priority),
            'is_high'        => $priority >= 5,
            'entity_name'    => (string) ($row['entity_name'] ?? ''),
            'roles_label'    => implode(' · ', $roleLabels),
            'opened'         => (string) ($row['date'] ?? ''),
            'opened_label'   => self::shortDateTime($row['date'] ?? null),
            'sla_due'        => $slaDue,
            'sla_label'      => self::shortDateTime($slaDue),
            'is_sla_late'    => ($slaDue !== null && $slaDue < $now),
            'url'            => self::ticketUrl($ticketId),
            'date'           => $today,
            'date_label'     => substr($today, 8, 2) . '/' . substr($today, 5, 2),
            // Chaves que o card() genérico do JS consulta em qualquer
            // item — presentes para nunca faltar (Twig/JS defensivos):
            'time_limit'     => null,
            'is_done'        => false,
            'done_time'      => null,
            'is_late'        => false,
        ];
    }

    /**
     * Com SLA antes de sem SLA; entre SLAs, o prazo mais próximo primeiro
     * (estourado sobe sozinho); sem SLA, prioridade desc e abertura asc.
     * Empate final por id: ordem TOTAL e estável (padrão dos compare* da
     * Occurrence).
     */
    private static function compare(array $a, array $b): int
    {
        $aDue = $a['sla_due'] ?? null;
        $bDue = $b['sla_due'] ?? null;

        if ($aDue !== null && $bDue !== null) {
            $cmp = strcmp($aDue, $bDue);
            if ($cmp !== 0) {
                return $cmp;
            }
        } elseif ($aDue !== null) {
            return -1;
        } elseif ($bDue !== null) {
            return 1;
        } else {
            $cmp = ((int) $b['priority']) <=> ((int) $a['priority']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string) ($a['opened'] ?? ''), (string) ($b['opened'] ?? ''));
            if ($cmp !== 0) {
                return $cmp; // abertura mais antiga primeiro
            }
        }

        return ((int) $a['ticket_id']) <=> ((int) $b['ticket_id']);
    }

    /** Rótulo do status — o helper do core quando houver, mapa local no harness. */
    private static function statusLabel(int $status): string
    {
        if (class_exists(Ticket::class) && method_exists(Ticket::class, 'getStatus')) {
            $label = (string) Ticket::getStatus($status);
            if ($label !== '') {
                return $label;
            }
        }
        $map = [
            1 => 'Novo',
            2 => 'Em atendimento (atribuído)',
            3 => 'Em atendimento (planejado)',
            4 => 'Pendente',
            5 => 'Solucionado',
            6 => 'Fechado',
        ];
        return $map[$status] ?? ('Status ' . $status);
    }

    /** Rótulo da prioridade — idem. */
    private static function priorityLabel(int $priority): string
    {
        if (class_exists(Ticket::class) && method_exists(Ticket::class, 'getPriorityName')) {
            $label = (string) Ticket::getPriorityName($priority);
            if ($label !== '') {
                return $label;
            }
        }
        $map = [
            1 => 'Muito baixa',
            2 => 'Baixa',
            3 => 'Média',
            4 => 'Alta',
            5 => 'Muito alta',
            6 => 'Crítica',
        ];
        return $map[$priority] ?? ('Prioridade ' . $priority);
    }

    /**
     * Status que tiram o chamado da coluna (decisão da abertura: todos
     * entram, EXCETO Solucionado e Fechado).
     */
    private static function finishedStatuses(): array
    {
        $status = [];
        if (class_exists(Ticket::class)) {
            if (method_exists(Ticket::class, 'getSolvedStatusArray')) {
                $status = array_merge($status, Ticket::getSolvedStatusArray());
            }
            if (method_exists(Ticket::class, 'getClosedStatusArray')) {
                $status = array_merge($status, Ticket::getClosedStatusArray());
            }
        }
        if (empty($status)) {
            $status = [5, 6]; // SOLVED, CLOSED
        }
        return array_values(array_unique(array_map('intval', $status)));
    }

    // =====================================================================
    // Helpers (mesma implementação do Native — privados lá de propósito;
    // duplicar 30 linhas custa menos que acoplar as duas classes)
    // =====================================================================

    /** '2026-08-04 09:00:00' válido → ele mesmo; vazio/zerado → null. */
    private static function validDateTime(?string $raw): ?string
    {
        $raw = (string) $raw;
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $raw)) {
            return null;
        }
        return $raw;
    }

    /** '2026-08-04 09:00:00' → '04/08 09:00'. */
    private static function shortDateTime(?string $raw): ?string
    {
        $raw = (string) $raw;
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $raw, $m)) {
            return null;
        }
        return $m[3] . '/' . $m[2] . ' ' . $m[4] . ':' . $m[5];
    }

    /** Conteúdo HTML rico → texto curto de uma linha (legibilidade). */
    private static function excerpt(string $html, int $limit = 180): string
    {
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit - 1) . '…';
    }

    /** URL do chamado — helper do core quando houver, caminho padrão no harness. */
    private static function ticketUrl(int $ticketId): string
    {
        if ($ticketId <= 0) {
            return '';
        }
        if (class_exists(Ticket::class) && method_exists(Ticket::class, 'getFormURLWithID')) {
            return (string) Ticket::getFormURLWithID($ticketId);
        }
        return '/front/ticket.form.php?id=' . $ticketId;
    }

    /** A coluna existe? Evita 1054 em schema que varia entre versões. */
    private static function hasField(string $table, string $field): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (is_object($DB) && method_exists($DB, 'fieldExists')) {
            return (bool) $DB->fieldExists($table, $field);
        }
        return true;
    }

    /** Restrição de entidade do perfil ativo (isolada para o harness). */
    private static function entityCriteria(string $table): array
    {
        if (!function_exists('getEntitiesRestrictCriteria')) {
            return [];
        }
        return getEntitiesRestrictCriteria($table);
    }
}
