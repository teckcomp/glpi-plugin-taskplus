<?php

namespace GlpiPlugin\Taskplus;

use Ticket;

/**
 * Task+ — origens NATIVAS do GLPI (Etapa 3).
 *
 * Somente LEITURA. Nada aqui escreve em tabela nativa — concluir uma
 * tarefa de chamado ou de projeto continua sendo feito na tela do item
 * nativo (decisão de produto nº 2; gravação fica para etapa futura).
 *
 * 3a (esta entrega): tarefas de chamado (`glpi_tickettasks`).
 * 3b: tarefas de projeto (`glpi_projecttasks`) + filtro por origem.
 *
 * Regras decididas em 03/08/2026 com o usuário:
 *  - aparecem TODAS as tarefas com estado "A fazer", sem filtro por data
 *    de planejamento (tarefa de chamado costuma nascer sem `begin`/`end`,
 *    e filtrar por data esconderia justamente o que está pendente);
 *  - "minha" tarefa = `users_id_tech` igual ao usuário logado (o técnico
 *    designado na própria tarefa), não o grupo nem o técnico do chamado;
 *  - chamado resolvido/fechado não entra, mesmo que a tarefa tenha ficado
 *    com estado "A fazer" — seria ruído permanente na tela Hoje;
 *  - entidade é respeitada por getEntitiesRestrictCriteria (o usuário não
 *    pode ver tarefa de chamado de entidade fora do seu perfil ativo).
 */
class Native
{
    public const TICKET_TASK_TABLE = 'glpi_tickettasks';
    public const TICKET_TABLE      = 'glpi_tickets';

    /**
     * Estado "A fazer" das tarefas de ITIL (Planning::TODO).
     * Fixado aqui de propósito: evita depender da classe Planning para
     * uma constante estável, e deixa o harness rodar sem o core.
     */
    public const STATE_TODO = 1;

    /**
     * Tarefas de chamado do usuário, já no formato de item da tela Hoje.
     *
     * Devolve [] em qualquer erro de leitura: origem nativa é acessório
     * da tela: se o JOIN falhar, o usuário ainda tem as próprias tarefas.
     */
    public static function ticketTasks(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($usersId <= 0) {
            return [];
        }

        // SELECT explícito e colunas SEMPRE qualificadas: `id`, `name`,
        // `date`, `status` e `date_mod` existem nas DUAS tabelas — sem
        // qualificar, o MySQL devolve 1052 ("Column ... is ambiguous"),
        // que na tela vira "Ocorreu um erro inesperado".
        $criteria = [
            'SELECT' => [
                self::TICKET_TASK_TABLE . '.id',
                self::TICKET_TASK_TABLE . '.tickets_id',
                self::TICKET_TASK_TABLE . '.content',
                self::TICKET_TASK_TABLE . '.begin',
                self::TICKET_TASK_TABLE . '.end',
                self::TICKET_TABLE . '.name AS ticket_name',
            ],
            'FROM'       => self::TICKET_TASK_TABLE,
            'INNER JOIN' => [
                self::TICKET_TABLE => [
                    'ON' => [
                        self::TICKET_TABLE      => 'id',
                        self::TICKET_TASK_TABLE => 'tickets_id',
                    ],
                ],
            ],
            'WHERE' => [
                self::TICKET_TASK_TABLE . '.users_id_tech' => $usersId,
                self::TICKET_TASK_TABLE . '.state'         => self::STATE_TODO,
                self::TICKET_TABLE . '.is_deleted'         => 0,
                'NOT' => [
                    self::TICKET_TABLE . '.status' => self::finishedTicketStatuses(),
                ],
            ],
            'ORDER' => [
                self::TICKET_TASK_TABLE . '.tickets_id ASC',
                self::TICKET_TASK_TABLE . '.id ASC',
            ],
        ];

        $entities = self::entityCriteria();
        if (!empty($entities)) {
            // Chaves distintas das de cima (entities_id), então merge é
            // seguro: nenhuma restrição sobrescreve a outra.
            $criteria['WHERE'] = array_merge($criteria['WHERE'], $entities);
        }

        $items = [];
        foreach ($DB->request($criteria) as $row) {
            $items[] = self::formatTicketTask($row);
        }

        return $items;
    }

    /**
     * Linha do banco → item do payload da tela Hoje.
     *
     * O item nasce marcado como `is_native`: o JS não desenha check de
     * conclusão nem editar/excluir nele, só o link para o item nativo.
     */
    private static function formatTicketTask(array $row): array
    {
        $ticketId = (int) ($row['tickets_id'] ?? 0);
        $today    = date('Y-m-d');

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'is_native'    => true,
            'source'       => 'ticket',
            'group'        => 'ticket',
            'is_routine'   => false,
            'name'         => (string) ($row['ticket_name'] ?? ''),
            'description'  => self::excerpt((string) ($row['content'] ?? '')),
            'category'     => '',
            'ticket_id'    => $ticketId,
            'ticket_label' => '#' . $ticketId,
            'url'          => self::ticketUrl($ticketId),
            'date'         => $today,
            'date_label'   => substr($today, 8, 2) . '/' . substr($today, 5, 2),
            'time_limit'   => null,
            // Planejamento é só informativo aqui: a decisão foi mostrar
            // TODAS as "A fazer", com ou sem data.
            'planned_label' => self::plannedLabel($row['begin'] ?? null, $row['end'] ?? null),
            'is_done'      => false,
            'done_time'    => null,
            'is_late'      => false,
        ];
    }

    /**
     * Conteúdo da tarefa (HTML rico do GLPI) → texto curto de uma linha.
     * O JS insere por textContent, então isto é legibilidade, não
     * segurança.
     */
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

    /**
     * "planejada 04/08 09:00 → 04/08 10:00", ou null quando a tarefa não
     * tem planejamento (o caso mais comum).
     */
    private static function plannedLabel(?string $begin, ?string $end): ?string
    {
        $b = self::shortDateTime($begin);
        $e = self::shortDateTime($end);

        if ($b === null && $e === null) {
            return null;
        }
        if ($b !== null && $e !== null) {
            return 'planejada ' . $b . ' → ' . $e;
        }
        return 'planejada ' . ($b ?? $e);
    }

    /** '2026-08-04 09:00:00' → '04/08 09:00' */
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

    /**
     * URL do chamado. Usa o helper do core quando disponível (é ele que
     * conhece o root_doc); cai no caminho padrão no harness.
     */
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

    /**
     * Status de chamado que tiram a tarefa da tela (resolvido/fechado).
     */
    private static function finishedTicketStatuses(): array
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

    /**
     * Restrição de entidade do perfil ativo. Isolada num método para o
     * harness poder rodar sem o core.
     */
    private static function entityCriteria(): array
    {
        if (!function_exists('getEntitiesRestrictCriteria')) {
            return [];
        }
        return getEntitiesRestrictCriteria(self::TICKET_TABLE);
    }
}
