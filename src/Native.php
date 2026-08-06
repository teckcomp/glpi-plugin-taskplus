<?php

namespace GlpiPlugin\Taskplus;

use ProjectTask;
use Ticket;

/**
 * Task+ — origens NATIVAS do GLPI (Etapa 3).
 *
 * Somente LEITURA. Nada aqui escreve em tabela nativa — concluir uma
 * tarefa de chamado ou de projeto continua sendo feito na tela do item
 * nativo (decisão de produto nº 2; gravação fica para etapa futura).
 *
 * 3a: tarefas de chamado (`glpi_tickettasks`).
 * 3b: tarefas de projeto (`glpi_projecttasks`) + filtro por origem na tela.
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

    public const PROJECT_TASK_TABLE      = 'glpi_projecttasks';
    public const PROJECT_TASK_TEAM_TABLE = 'glpi_projecttaskteams';
    public const PROJECT_TABLE           = 'glpi_projects';

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

        $entities = self::entityCriteria(self::TICKET_TABLE);
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

    // =====================================================================
    // Tarefas de projeto (Etapa 3b)
    // =====================================================================

    /**
     * Tarefas de projeto do usuário, no formato de item da tela Hoje.
     *
     * Regras decididas em 03/08/2026 com o usuário:
     *  - "minha" tarefa = estou na **equipe da tarefa**
     *    (`glpi_projecttaskteams` com itemtype 'User'). A equipe do
     *    PROJETO não conta: quem entra no projeto inteiro receberia todas
     *    as tarefas dele na tela Hoje;
     *  - aparecem todas com **percentual < 100%**, sem filtro de data
     *    (mesmo espírito da regra das tarefas de chamado);
     *  - projeto excluído não entra.
     *
     * A consulta é feita em dois passos (equipe → tarefas) em vez de um
     * JOIN com condição composta: fica legível, testável, e a lista de
     * ids de uma pessoa é sempre pequena.
     */
    public static function projectTasks(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($usersId <= 0) {
            return [];
        }

        // 1) Em quais tarefas de projeto eu estou na equipe?
        $taskIds = [];
        foreach ($DB->request([
            'SELECT' => [self::PROJECT_TASK_TEAM_TABLE . '.projecttasks_id'],
            'FROM'   => self::PROJECT_TASK_TEAM_TABLE,
            'WHERE'  => [
                self::PROJECT_TASK_TEAM_TABLE . '.itemtype' => 'User',
                self::PROJECT_TASK_TEAM_TABLE . '.items_id' => $usersId,
            ],
        ]) as $row) {
            $id = (int) ($row['projecttasks_id'] ?? 0);
            if ($id > 0 && !in_array($id, $taskIds, true)) {
                $taskIds[] = $id;
            }
        }

        if (empty($taskIds)) {
            return [];
        }

        // 2) As tarefas em si, com o projeto por INNER JOIN.
        $criteria = [
            'SELECT' => [
                self::PROJECT_TASK_TABLE . '.id',
                self::PROJECT_TASK_TABLE . '.projects_id',
                self::PROJECT_TASK_TABLE . '.name',
                self::PROJECT_TASK_TABLE . '.content',
                self::PROJECT_TASK_TABLE . '.percent_done',
                self::PROJECT_TASK_TABLE . '.plan_start_date',
                self::PROJECT_TASK_TABLE . '.plan_end_date',
                self::PROJECT_TABLE . '.name AS project_name',
            ],
            'FROM'       => self::PROJECT_TASK_TABLE,
            'INNER JOIN' => [
                self::PROJECT_TABLE => [
                    'ON' => [
                        self::PROJECT_TABLE      => 'id',
                        self::PROJECT_TASK_TABLE => 'projects_id',
                    ],
                ],
            ],
            'WHERE' => [
                self::PROJECT_TASK_TABLE . '.id'           => $taskIds,
                self::PROJECT_TASK_TABLE . '.percent_done' => ['<', 100],
            ],
            'ORDER' => [
                self::PROJECT_TASK_TABLE . '.projects_id ASC',
                self::PROJECT_TASK_TABLE . '.id ASC',
            ],
        ];

        // `is_deleted` existe no projeto; na TAREFA de projeto depende da
        // versão do schema. Filtrar coluna inexistente derruba a consulta
        // com 1054, então só entra se existir mesmo.
        foreach ([self::PROJECT_TABLE, self::PROJECT_TASK_TABLE] as $table) {
            if (self::hasField($table, 'is_deleted')) {
                $criteria['WHERE'][$table . '.is_deleted'] = 0;
            }
        }

        $entities = self::entityCriteria(self::PROJECT_TABLE);
        if (!empty($entities)) {
            $criteria['WHERE'] = array_merge($criteria['WHERE'], $entities);
        }

        $items = [];
        foreach ($DB->request($criteria) as $row) {
            $items[] = self::formatProjectTask($row);
        }

        return $items;
    }

    private static function formatProjectTask(array $row): array
    {
        $taskId  = (int) ($row['id'] ?? 0);
        $percent = (int) ($row['percent_done'] ?? 0);
        $today   = date('Y-m-d');

        return [
            'id'            => $taskId,
            'is_native'     => true,
            'source'        => 'project',
            'group'         => 'project',
            'is_routine'    => false,
            'name'          => (string) ($row['name'] ?? ''),
            'description'   => self::excerpt((string) ($row['content'] ?? '')),
            'category'      => '',
            'project_name'  => (string) ($row['project_name'] ?? ''),
            'percent_done'  => $percent,
            'percent_label' => $percent . '%',
            'url'           => self::projectTaskUrl($taskId),
            'date'          => $today,
            'date_label'    => substr($today, 8, 2) . '/' . substr($today, 5, 2),
            'time_limit'    => null,
            'planned_label' => self::plannedLabel(
                $row['plan_start_date'] ?? null,
                $row['plan_end_date'] ?? null
            ),
            'is_done'       => false,
            'done_time'     => null,
            'is_late'       => false,
        ];
    }

    private static function projectTaskUrl(int $taskId): string
    {
        if ($taskId <= 0) {
            return '';
        }
        if (class_exists(ProjectTask::class) && method_exists(ProjectTask::class, 'getFormURLWithID')) {
            return (string) ProjectTask::getFormURLWithID($taskId);
        }
        return '/front/projecttask.form.php?id=' . $taskId;
    }

    /**
     * A coluna existe na tabela? Evita 1054 em schema que varia entre
     * versões do GLPI. Sem o core (harness), assume que existe.
     */
    private static function hasField(string $table, string $field): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (is_object($DB) && method_exists($DB, 'fieldExists')) {
            return (bool) $DB->fieldExists($table, $field);
        }
        return true;
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
    private static function entityCriteria(string $table): array
    {
        if (!function_exists('getEntitiesRestrictCriteria')) {
            return [];
        }
        return getEntitiesRestrictCriteria($table);
    }
}
