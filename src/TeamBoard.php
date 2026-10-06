<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — Quadro de Equipe (13b, decisões nº 65/66).
 *
 * Camada única de dados/ações da tela, usada por ajax/teamboard.php e
 * front/teamboard.php — mesmo padrão do Board: payload()/handle()
 * testáveis por harness, endpoint FINO.
 *
 * MODELO:
 *
 *  - Tarefa de EQUIPE = ocorrência com `groups_id` > 0 e `users_id` = 0,
 *    com N colaboradores em glpi_plugin_taskplus_occurrence_users. É UMA
 *    tarefa para todos (concluir conclui para todos) — diferente da
 *    "criar para o setor" da Equipe (5c-3), que gera uma cópia por
 *    membro e continua existindo para rotinas e avulsas individuais.
 *
 *  - O quadro é POR SETOR: colunas = 4 fases de sistema + as fases
 *    daquele setor (Phase::boardColumns com um grupo só). Quem vê =
 *    membros e gestores do setor (Access::teamBoardGroups); gestor de
 *    mais de um setor troca pelo seletor.
 *
 *  - Pessoal e equipe não se misturam: o Quadro pessoal filtra
 *    users_id = eu (a de equipe tem 0) e este filtra groups_id = setor.
 *
 *  - Permissões (nº 66): criar/editar = membro ou gestor do setor;
 *    excluir = criador ou gestor; MOVER (fase, concluir, pendenciar)
 *    = colaborador da tarefa ou gestor, SEMPRE com comentário, que
 *    vai ao diálogo como "[Movida para X] …" (13c).
 *
 *  - Pendência de equipe = linha em glpi_plugin_taskplus_pendings com
 *    users_id = 0 (é da TAREFA, não de um usuário) e users_id_creator =
 *    quem marcou. Zero schema: Pending::activeMap(0) lê, Pending::set/
 *    clear com usersId 0 gravam.
 *
 *  - Diálogo: o MESMO ajax/comment.php da Hoje; Comment::canInteract
 *    reconhece a tarefa de equipe (colaborador ou gestor — canAct).
 */
class TeamBoard
{
    public const TABLE_USERS = 'glpi_plugin_taskplus_occurrence_users';

    /** 13c: "usuário" da pendência de equipe — a pendência é da tarefa. */
    public const TEAM_PENDING_USER = 0;

    // =====================================================================
    // Payload
    // =====================================================================

    /**
     * Tudo que a tela precisa:
     *
     *   [
     *     'date'       => 'Y-m-d' (hoje),
     *     'groups'     => [{id, name, can_manage}] em ordem de nome,
     *     'group_id'   => setor exibido (0 = nenhum),
     *     'can_manage' => bool (gestor do setor exibido),
     *     'columns'    => colunas na ordem canônica (Phase::boardColumns),
     *     'cards'      => tarefas de equipe do setor, cada uma com
     *                     'column' e 'collaborators' [{id, label}],
     *     'members'    => [{id, label}] colaboradores possíveis do setor,
     *   ]
     *
     * ATENÇÃO safeData(): chave nova aqui precisa entrar no teamboard.js.
     */
    public static function payload(int $usersId, int $groupId = 0, ?string $from = null, ?string $to = null): array
    {
        // 13c-2: período opcional, mesma normalização do Quadro pessoal
        // (5b-2 p2). Ativo → tarefas de equipe do setor com DATA no
        // intervalo, em qualquer estado (é como se vê concluída antiga).
        [$from, $to]  = Occurrence::periodRange($from, $to);
        $periodActive = ($from !== null || $to !== null);
        $periodEcho   = ['from' => $from ?? '', 'to' => $to ?? '', 'active' => $periodActive];

        $groups = Access::teamBoardGroups($usersId);
        $list   = [];
        foreach ($groups as $gid => $g) {
            $list[] = ['id' => (int) $gid, 'name' => $g['name'], 'can_manage' => (bool) $g['can_manage']];
        }

        if ($groupId <= 0 || !isset($groups[$groupId])) {
            $groupId = ($list === []) ? 0 : (int) $list[0]['id'];
        }

        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');

        if ($groupId === 0) {
            return [
                'date'       => $today,
                'groups'     => [],
                'group_id'   => 0,
                'can_manage' => false,
                'columns'    => [],
                'cards'      => [],
                'members'    => [],
                'period'     => $periodEcho,
            ];
        }

        $columns = Phase::boardColumns([$groupId]);
        $rows    = $periodActive
            ? self::rowsInPeriod($groupId, $from, $to)
            : self::rows($groupId, $today);

        $items = [];
        foreach ($rows as $row) {
            $item              = Occurrence::format($row, $today, $nowTime);
            $item['groups_id'] = $groupId;
            $items[]           = $item;
        }

        // 13c: pendência DA TAREFA (users_id = 0) — mesma decoração da
        // Hoje; o nome de quem marcou é resolvido abaixo.
        $pendings = [];
        try {
            $pendings = Pending::activeMap(self::TEAM_PENDING_USER, $today);
        } catch (\Throwable $e) {
            $pendings = [];
        }
        $items = Occurrence::applyPendings($items, $pendings, Pending::TYPE_OCCURRENCE, 0);

        // 13c: não lidos do diálogo, do ponto de vista de quem olha
        $ids = array_map(static function (array $it): int {
            return (int) $it['id'];
        }, $items);
        $unread = [];
        try {
            $unread = ($ids === []) ? [] : Comment::unreadFor($ids, $usersId);
        } catch (\Throwable $e) {
            $unread = [];
        }

        $actorIds = [];
        foreach ($items as $i => $item) {
            $items[$i]['column']   = Board::resolveColumn($item, $columns);
            $items[$i]['card_key'] = 'Occurrence:' . (int) $item['id'];
            $items[$i]['unread']   = (int) ($unread[(int) $item['id']] ?? 0);
            foreach (['pending_by_id', 'done_by_id'] as $k) {
                if ((int) ($item[$k] ?? 0) > 0) {
                    $actorIds[] = (int) $item[$k];
                }
            }
        }
        $labels = self::userLabels($actorIds);
        foreach ($items as $i => $item) {
            $items[$i]['pending_by_label'] = (string) ($labels[(int) ($item['pending_by_id'] ?? 0)] ?? '');
            // Em tarefa de equipe "quem concluiu" é sempre informativo
            // (não há dono para comparar): badge com o nome.
            $items[$i]['done_by_label']    = (string) ($labels[(int) ($item['done_by_id'] ?? 0)] ?? '');
            $items[$i]['done_by_other']    = !empty($item['is_done']) && (int) ($item['done_by_id'] ?? 0) > 0;
        }
        $items = self::fillCollaborators($items);

        $members = [];
        foreach (Team::membersOf([$groupId => $groups[$groupId]['name']]) as $uid => $m) {
            $members[] = ['id' => (int) $uid, 'label' => (string) $m['label']];
        }
        usort($members, static function (array $a, array $b): int {
            return strnatcasecmp($a['label'], $b['label']);
        });

        return [
            'date'       => $today,
            'groups'     => $list,
            'group_id'   => $groupId,
            'can_manage' => (bool) $groups[$groupId]['can_manage'],
            'columns'    => $columns,
            'cards'      => $items,
            'members'    => $members,
            'period'     => $periodEcho,
        ];
    }

    /**
     * 13c-2: todas as tarefas de equipe do setor com data no intervalo
     * (aberta, concluída, pendente), sem excluídas nem puladas. Uma
     * consulta; as duas bordas entram como critérios aninhados (duas
     * restrições na mesma coluna não podem dividir a chave do array).
     */
    private static function rowsInPeriod(int $groupId, ?string $from, ?string $to): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $base = Occurrence::baseQuery();
        $base['SELECT'][] = Occurrence::TABLE . '.groups_id';
        $where = [
            Occurrence::TABLE . '.groups_id'  => $groupId,
            Occurrence::TABLE . '.is_deleted' => 0,
            Occurrence::TABLE . '.is_skipped' => 0,
        ];
        if ($from !== null) {
            $where[] = [Occurrence::TABLE . '.date' => ['>=', $from]];
        }
        if ($to !== null) {
            $where[] = [Occurrence::TABLE . '.date' => ['<=', $to]];
        }
        $rows = [];
        foreach ($DB->request($base + ['WHERE' => $where]) as $row) {
            $rows[] = $row;
        }
        return self::sortRows($rows);
    }

    /**
     * Linhas de tarefa de equipe do setor: do dia + atrasadas + as de
     * dia anterior concluídas hoje (mesmo recorte do Quadro pessoal,
     * 4d-2), sem excluídas nem puladas. Três consultas, uma por regra,
     * como no Occurrence::payload — nunca divergir dele.
     */
    private static function rows(int $groupId, string $today): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $base = Occurrence::baseQuery();
        $base['SELECT'][] = Occurrence::TABLE . '.groups_id';
        $common = [
            Occurrence::TABLE . '.groups_id'  => $groupId,
            Occurrence::TABLE . '.is_deleted' => 0,
            Occurrence::TABLE . '.is_skipped' => 0,
        ];

        $rows = [];
        foreach ($DB->request($base + ['WHERE' => $common + [
            Occurrence::TABLE . '.date' => $today,
        ]]) as $row) {
            $rows[] = $row;
        }
        foreach ($DB->request($base + ['WHERE' => $common + [
            Occurrence::TABLE . '.is_done' => 0,
            Occurrence::TABLE . '.date'    => ['<', $today],
        ]]) as $row) {
            $rows[] = $row;
        }
        foreach ($DB->request($base + ['WHERE' => $common + [
            Occurrence::TABLE . '.is_done'   => 1,
            Occurrence::TABLE . '.date'      => ['<', $today],
            Occurrence::TABLE . '.done_date' => ['>=', $today . ' 00:00:00'],
        ]]) as $row) {
            $rows[] = $row;
        }

        return self::sortRows($rows);
    }

    /** Cronológica: data, horário-limite (sem limite por último), id. */
    private static function sortRows(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $c = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
            if ($c !== 0) {
                return $c;
            }
            $ta = (string) ($a['time_limit'] ?? '');
            $tb = (string) ($b['time_limit'] ?? '');
            if ($ta !== $tb) {
                if ($ta === '') {
                    return 1;
                }
                if ($tb === '') {
                    return -1;
                }
                return strcmp($ta, $tb);
            }
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $rows;
    }

    /**
     * Injeta `collaborators` [{id, label}] em cada item, em DUAS
     * consultas (vínculos + nomes), sem JOIN. Usuário removido do GLPI
     * aparece como "(usuário removido)".
     */
    private static function fillCollaborators(array $items): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($items as $item) {
            $ids[] = (int) ($item['id'] ?? 0);
        }
        $ids = array_values(array_filter($ids));

        $links = [];
        $uids  = [];
        if ($ids !== []) {
            foreach ($DB->request([
                'FROM'  => self::TABLE_USERS,
                'WHERE' => [self::TABLE_USERS . '.plugin_taskplus_occurrences_id' => $ids],
            ]) as $row) {
                $oid = (int) ($row['plugin_taskplus_occurrences_id'] ?? 0);
                $uid = (int) ($row['users_id'] ?? 0);
                $links[$oid][] = $uid;
                $uids[$uid]    = true;
            }
        }

        $labels = self::userLabels(array_keys($uids));

        foreach ($items as $i => $item) {
            $list = [];
            foreach ($links[(int) ($item['id'] ?? 0)] ?? [] as $uid) {
                $list[] = ['id' => $uid, 'label' => $labels[$uid] ?? '(usuário removido)'];
            }
            usort($list, static function (array $a, array $b): int {
                return strnatcasecmp($a['label'], $b['label']);
            });
            $items[$i]['collaborators'] = $list;
            $items[$i]['collaborator_ids'] = array_map(static function (array $c): int {
                return (int) $c['id'];
            }, $list);
        }

        return $items;
    }

    /** [users_id => nome de exibição] para os ids dados. */
    public static function userLabels(array $userIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $labels = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['glpi_users.id' => $userIds],
        ]) as $row) {
            $label = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['realname'] ?? ''));
            if ($label === '') {
                $label = (string) ($row['name'] ?? '');
            }
            $labels[(int) ($row['id'] ?? 0)] = $label;
        }
        return $labels;
    }

    // =====================================================================
    // Ações do endpoint ajax
    // =====================================================================

    /**
     * Despacha a ação do POST. Sempre ['success' => bool, 'message' =>
     * string]; o endpoint completa com csrf e payload.
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
            case 'move':
                return self::move($input, $usersId);
            case 'list':
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    /**
     * Escopo do setor para quem pede: ['group_id', 'name', 'can_manage']
     * ou string de erro. Setor fora do escopo = erro, não filtro mudo.
     */
    private static function scopedGroup(array $input, int $usersId): array|string
    {
        $gid    = (int) ($input['groups_id'] ?? 0);
        $groups = Access::teamBoardGroups($usersId);
        if ($gid <= 0 || !isset($groups[$gid])) {
            return __('Setor fora do seu escopo', 'taskplus');
        }
        return ['group_id' => $gid, 'name' => $groups[$gid]['name'], 'can_manage' => (bool) $groups[$gid]['can_manage']];
    }

    /**
     * Colaboradores válidos do POST (`collaborators[]`): ids únicos que
     * são MEMBROS do setor com direito de tarefa. Lista vazia é erro —
     * tarefa de equipe sem gente não existe. Regra pura (recebe os
     * membros) para o harness.
     */
    public static function cleanCollaborators($raw, array $memberIds): array|string
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return __('Escolha pelo menos um colaborador', 'taskplus');
        }
        foreach ($ids as $id) {
            if (!in_array($id, $memberIds, true)) {
                return __('Colaborador fora do setor', 'taskplus');
            }
        }
        return $ids;
    }

    private static function memberIds(int $groupId, string $groupName): array
    {
        return array_map('intval', array_keys(Team::membersOf([$groupId => $groupName])));
    }

    private static function add(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $scope = self::scopedGroup($input, $usersId);
        if (is_string($scope)) {
            return ['success' => false, 'message' => $scope];
        }
        $fields = Occurrence::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }
        $collab = self::cleanCollaborators(
            $input['collaborators'] ?? [],
            self::memberIds($scope['group_id'], $scope['name'])
        );
        if (is_string($collab)) {
            return ['success' => false, 'message' => $collab];
        }

        $now = date('Y-m-d H:i:s');
        $DB->insert(Occurrence::TABLE, $fields + [
            'groups_id'        => $scope['group_id'],
            'users_id'         => 0,
            'users_id_creator' => $usersId,
            'is_done'          => 0,
            'is_skipped'       => 0,
            'is_deleted'       => 0,
            'date_creation'    => $now,
            'date_mod'         => $now,
        ]);
        $occId = (int) $DB->insertId();
        self::saveCollaborators($occId, $collab);

        return ['success' => true, 'message' => __('Tarefa de equipe criada', 'taskplus')];
    }

    private static function update(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::teamRow((int) ($input['id'] ?? 0));
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        $scope = self::scopedGroup(['groups_id' => $row['groups_id']], $usersId);
        if (is_string($scope)) {
            return ['success' => false, 'message' => $scope];
        }
        $fields = Occurrence::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }
        $collab = self::cleanCollaborators(
            $input['collaborators'] ?? [],
            self::memberIds($scope['group_id'], $scope['name'])
        );
        if (is_string($collab)) {
            return ['success' => false, 'message' => $collab];
        }

        $DB->update(
            Occurrence::TABLE,
            $fields + ['date_mod' => date('Y-m-d H:i:s')],
            [Occurrence::TABLE . '.id' => (int) $row['id']]
        );
        self::saveCollaborators((int) $row['id'], $collab);

        return ['success' => true, 'message' => __('Tarefa de equipe atualizada', 'taskplus')];
    }

    /** Excluir (soft): criador ou gestor do setor. */
    private static function delete(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::teamRow((int) ($input['id'] ?? 0));
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        $scope = self::scopedGroup(['groups_id' => $row['groups_id']], $usersId);
        if (is_string($scope)) {
            return ['success' => false, 'message' => $scope];
        }
        if (!self::canDelete($row, $usersId, $scope['can_manage'])) {
            return ['success' => false, 'message' => __('Só quem criou ou o gestor do setor pode excluir', 'taskplus')];
        }

        $DB->update(
            Occurrence::TABLE,
            ['is_deleted' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            [Occurrence::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Tarefa de equipe excluída', 'taskplus')];
    }

    /** Regra pura: excluir = criador ou gestor do setor. */
    public static function canDelete(array $row, int $usersId, bool $canManage): bool
    {
        return $canManage || (int) ($row['users_id_creator'] ?? 0) === $usersId;
    }

    /**
     * 13c (decisão nº 66): pode MOVER/concluir/pendenciar e participar
     * do diálogo = colaborador da tarefa, ou gestor do setor dela
     * (admin conta como gestor de todos). Reavaliado a cada POST (T18).
     */
    public static function canAct(array $row, int $usersId): bool
    {
        if ($usersId <= 0) {
            return false;
        }
        $occId = (int) ($row['id'] ?? 0);
        if (in_array($usersId, self::collaboratorIds($occId), true)) {
            return true;
        }
        $gid    = (int) ($row['groups_id'] ?? 0);
        $groups = Access::teamBoardGroups($usersId);
        return $gid > 0 && !empty($groups[$gid]['can_manage']);
    }

    /**
     * Mover o card para a coluna $phases_id, SEMPRE com comentário
     * (`comment`, obrigatório) — decisão nº 66. Roteia pelo tipo da
     * coluna alvo, com as mesmas regras do Quadro pessoal (Board):
     *
     *   · Atrasadas: nunca recebe (é calculada);
     *   · Para hoje / fase do setor: grava a fase; desfaz conclusão se
     *     vinha de Concluídas; encerra a pendência se vinha de
     *     Pendentes; recusa card atrasado;
     *   · Concluídas: conclui PARA A EQUIPE TODA (users_id_done = quem
     *     soltou); encerra pendência ativa;
     *   · Pendentes: pede também `pending_until` + `pending_time`; o
     *     comentário é o motivo (Pending::set com usersId 0).
     *
     * O comentário vai ao diálogo como "[Movida para X] …".
     */
    private static function move(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::teamRow((int) ($input['id'] ?? 0));
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        if (!self::canAct($row, $usersId)) {
            return ['success' => false, 'message' => __('Só colaboradores da tarefa ou o gestor do setor podem movê-la', 'taskplus')];
        }

        $comment = trim((string) ($input['comment'] ?? ''));
        if ($comment === '') {
            return ['success' => false, 'message' => __('Escreva um comentário para mover a tarefa', 'taskplus')];
        }

        $gid      = (int) $row['groups_id'];
        $columns  = Phase::boardColumns([$gid]);
        $targetId = (int) ($input['phases_id'] ?? 0);
        $target   = null;
        foreach ($columns as $col) {
            if ((int) $col['id'] === $targetId) {
                $target = $col;
                break;
            }
        }
        if ($target === null) {
            return ['success' => false, 'message' => __('Fase inválida para este quadro', 'taskplus')];
        }

        $key       = !empty($target['is_system']) ? (string) $target['system_key'] : '';
        $isDone    = ((int) ($row['is_done'] ?? 0)) === 1;
        $isPending = self::hasTeamPending((int) $row['id']);
        $now       = date('Y-m-d H:i:s');
        $occId     = (int) $row['id'];

        if ($key === 'late') {
            return ['success' => false, 'message' => __('Atrasadas é automática — não recebe tarefas', 'taskplus')];
        }

        if ($key === 'done') {
            if ($isDone) {
                return ['success' => true, 'message' => __('Tarefa já estava concluída', 'taskplus')];
            }
            if ($isPending) {
                Pending::clear(Pending::TYPE_OCCURRENCE, $occId, self::TEAM_PENDING_USER);
            }
            $DB->update(Occurrence::TABLE, [
                'is_done'           => 1,
                'done_date'         => $now,
                'users_id_done'     => $usersId,
                'validation'        => 0,
                'users_id_validate' => 0,
                'validation_date'   => null,
                'date_mod'          => $now,
            ], [Occurrence::TABLE . '.id' => $occId]);
            Comment::addFromMove($occId, $usersId, (string) $target['name'], $comment);
            return ['success' => true, 'message' => __('Tarefa concluída para a equipe', 'taskplus')];
        }

        if ($key === 'pending') {
            if ($isDone) {
                return ['success' => false, 'message' => __('Tarefa concluída não pode ficar pendente', 'taskplus')];
            }
            $set = Pending::set(
                Pending::TYPE_OCCURRENCE,
                $occId,
                self::TEAM_PENDING_USER,
                ['reason' => $comment] + $input,
                $usersId
            );
            if (empty($set['success'])) {
                return $set;
            }
            Comment::addFromMove($occId, $usersId, (string) $target['name'], $comment);
            return ['success' => true, 'message' => __('Tarefa marcada como pendente para a equipe', 'taskplus')];
        }

        // Fase de trabalho (Para hoje ou fase do setor)
        if (!$isDone && !$isPending && self::isLateRow($row)) {
            return ['success' => false, 'message' => __('Tarefa atrasada: conclua, marque como pendente ou dê nova data', 'taskplus')];
        }
        if ($isPending) {
            Pending::clear(Pending::TYPE_OCCURRENCE, $occId, self::TEAM_PENDING_USER);
        }
        $fields = ['plugin_taskplus_phases_id' => $targetId, 'date_mod' => $now];
        if ($isDone) {
            $fields += [
                'is_done'           => 0,
                'done_date'         => null,
                'users_id_done'     => 0,
                'validation'        => 0,
                'users_id_validate' => 0,
                'validation_date'   => null,
            ];
        }
        $DB->update(Occurrence::TABLE, $fields, [Occurrence::TABLE . '.id' => $occId]);
        Comment::addFromMove($occId, $usersId, (string) $target['name'], $comment);

        if ($isDone && self::isLateRow($row)) {
            return ['success' => true, 'message' => __('Conclusão desfeita — a tarefa venceu e voltou para Atrasadas', 'taskplus')];
        }
        return ['success' => true, 'message' => sprintf(__('Tarefa movida para "%s"', 'taskplus'), (string) $target['name'])];
    }

    /** A tarefa tem pendência de EQUIPE ativa (e não vencida)? */
    private static function hasTeamPending(int $occId): bool
    {
        try {
            $map = Pending::activeMap(self::TEAM_PENDING_USER);
        } catch (\Throwable $e) {
            return false;
        }
        return isset($map[Pending::TYPE_OCCURRENCE . ':' . $occId]);
    }

    /** Atrasada AGORA? Mesma regra do Occurrence::format / Board. */
    private static function isLateRow(array $row): bool
    {
        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');
        $date    = (string) ($row['date'] ?? $today);
        $limit   = $row['time_limit'] ?? null;
        return $date < $today
            || ($date === $today && $limit !== null && $limit !== '' && $limit < $nowTime);
    }

    /**
     * Regrava o conjunto de colaboradores: apaga os que saíram, insere
     * os novos, mantém os que ficaram (a UNIQUE protege de duplicar).
     */
    private static function saveCollaborators(int $occId, array $ids): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($occId <= 0) {
            return;
        }
        $current = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE_USERS,
            'WHERE' => [self::TABLE_USERS . '.plugin_taskplus_occurrences_id' => $occId],
        ]) as $row) {
            $current[] = (int) ($row['users_id'] ?? 0);
        }
        $gone = array_diff($current, $ids);
        if ($gone !== []) {
            $DB->delete(self::TABLE_USERS, [
                self::TABLE_USERS . '.plugin_taskplus_occurrences_id' => $occId,
                self::TABLE_USERS . '.users_id'                       => array_values($gone),
            ]);
        }
        foreach (array_diff($ids, $current) as $uid) {
            $DB->insert(self::TABLE_USERS, [
                'plugin_taskplus_occurrences_id' => $occId,
                'users_id'                       => (int) $uid,
            ]);
        }
    }

    /** Tarefa de EQUIPE viva (groups_id > 0, não excluída), ou null. */
    public static function teamRow(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($id <= 0) {
            return null;
        }
        foreach ($DB->request([
            'FROM'  => Occurrence::TABLE,
            'WHERE' => [
                Occurrence::TABLE . '.id'         => $id,
                Occurrence::TABLE . '.groups_id'  => ['>', 0],
                Occurrence::TABLE . '.is_deleted' => 0,
            ],
        ]) as $row) {
            return $row;
        }
        return null;
    }

    /** Ids dos colaboradores da tarefa (13c/13d usam). */
    public static function collaboratorIds(int $occId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE_USERS,
            'WHERE' => [self::TABLE_USERS . '.plugin_taskplus_occurrences_id' => $occId],
        ]) as $row) {
            $ids[] = (int) ($row['users_id'] ?? 0);
        }
        return $ids;
    }
}
