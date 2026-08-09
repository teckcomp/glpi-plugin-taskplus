<?php

namespace GlpiPlugin\Taskplus;

use NotificationEvent;

/**
 * Task+ — alertas (Etapa 7a: horário-limite estourado, canal sino).
 *
 * Camada de DOMÍNIO do cron `taskplusalerts` (Cron.php só orquestra e
 * loga): tudo que decide "quem alerta" e "o que gravar" mora aqui, em
 * métodos estáticos exercitáveis por harness.
 *
 * Regras travadas na abertura do 7a:
 *  - candidata = ocorrência de HOJE, com `time_limit` não nulo e já
 *    estourado no instante da rodada, VIVA (não concluída, não pulada,
 *    não excluída) e ainda sem alerta enviado (`date_alert_limit` NULL);
 *  - pendência ATIVA tira do alerta: pendência é adiamento negociado
 *    com motivo, data e hora — alertar por cima dela seria ruído. A
 *    régua de "ativa" é a MESMA da tela (Pending::activeMap — expira
 *    por data+hora);
 *  - UMA tentativa por ocorrência: a trilha `date_alert_limit` é
 *    carimbada após a tentativa, tenha o raiseEvent aceitado ou não
 *    (notificação desativada no GLPI = tentativa consumida; ninguém
 *    quer rajada de alertas velhos quando o sino for ligado depois);
 *  - item NATIVO fica fora (decisão nº 3: não há recorte por dia).
 *
 * Canais (7a-2, a pedido do uso real — sino igual ao do ProjectPlus):
 *  - SINO próprio: linha em glpi_plugin_taskplus_alerts, exibida pelo
 *    widget da sidebar (bell.js + ajax/alerts.php). Sempre grava.
 *  - Navegador: NotificationEvent nativo (pop-up do SO) — opcional, o
 *    admin controla em Administração → Notificações.
 */
class Alerts
{
    public const EVENT_TIME_LIMIT = 'time_limit';

    /** Tabela do sino (padrão trazido do ProjectPlus). */
    public const TABLE = 'glpi_plugin_taskplus_alerts';

    /**
     * Ocorrências que devem alertar horário-limite no instante $now
     * ('Y-m-d H:i:s'; NULL = agora). Ordenadas por id (determinismo).
     */
    public static function limitCandidates(?string $now = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now   = $now ?? date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $time  = substr($now, 11, 8);

        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                Occurrence::TABLE . '.id',
                Occurrence::TABLE . '.name',
                Occurrence::TABLE . '.users_id',
                Occurrence::TABLE . '.date',
                Occurrence::TABLE . '.time_limit',
            ],
            'FROM'  => Occurrence::TABLE,
            'WHERE' => [
                Occurrence::TABLE . '.date'             => $today,
                Occurrence::TABLE . '.is_done'          => 0,
                Occurrence::TABLE . '.is_skipped'       => 0,
                Occurrence::TABLE . '.is_deleted'       => 0,
                Occurrence::TABLE . '.date_alert_limit' => null,
                // Duas restrições sobre a MESMA coluna: entradas
                // separadas (T21) — nunca duas chaves iguais no array.
                ['NOT' => [Occurrence::TABLE . '.time_limit' => null]],
                [Occurrence::TABLE . '.time_limit' => ['<=', $time]],
            ],
            'ORDER' => Occurrence::TABLE . '.id ASC',
        ]) as $row) {
            $rows[] = $row;
        }

        if ($rows === []) {
            return [];
        }

        // Pendência ativa tira do alerta — mesma régua da tela, por
        // usuário (activeMap é por dono; agrupa para não consultar por
        // linha) e reordenado por id ao final.
        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['users_id']][] = $row;
        }

        $out = [];
        foreach ($byUser as $usersId => $items) {
            $map = Pending::activeMap($usersId, $today);
            foreach ($items as $row) {
                $key = Pending::TYPE_OCCURRENCE . ':' . (int) $row['id'];
                if (isset($map[$key])) {
                    continue;
                }
                $out[] = $row;
            }
        }

        usort($out, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));

        return $out;
    }

    /**
     * Rodada completa: candidatas → grava no SINO → dispara o canal
     * navegador → carimba a trilha.
     *
     * $raiser (harness) recebe o id da ocorrência e devolve bool;
     * NULL = raiseTimeLimit real. A trilha é carimbada para TODAS as
     * candidatas da rodada (uma tentativa por ocorrência — ver topo).
     *
     * Retorno: ['candidates' => n, 'bell' => n, 'raised' => n, 'marked' => n].
     */
    public static function process(?string $now = null, ?callable $raiser = null): array
    {
        $now        = $now ?? date('Y-m-d H:i:s');
        $candidates = self::limitCandidates($now);
        $raiser     = $raiser ?? [self::class, 'raiseTimeLimit'];

        $bell   = 0;
        $raised = 0;
        $ids    = [];

        // 7a-3: além do dono, o estouro avisa os GESTORES dos setores do
        // técnico (pedido do uso real — o gestor acompanha a equipe e
        // precisa saber do estouro sem abrir técnico por técnico).
        $managers = self::managersByOwner($candidates);
        $names    = self::ownerNames($candidates);

        foreach ($candidates as $row) {
            $id  = (int) $row['id'];
            $uid = (int) ($row['users_id'] ?? 0);

            if (self::addBellAlert($row, $now)) {
                $bell++;
            }

            foreach ($managers[$uid] ?? [] as $managerId) {
                $limit = (string) ($row['time_limit'] ?? '');
                $limit = ($limit !== '') ? substr($limit, 0, 5) : '';
                $name  = trim((string) ($row['name'] ?? ''));
                $who   = (string) ($names[$uid] ?? '');

                $message = ($limit !== '')
                    ? sprintf(__('Horário-limite %s estourado: %s (%s)', 'taskplus'), $limit, $name, $who)
                    : sprintf(__('Horário-limite estourado: %s (%s)', 'taskplus'), $name, $who);

                if (self::addBellAlertFor($managerId, $row, $message, $now)) {
                    $bell++;
                }
            }

            if ($raiser($id) === true) {
                $raised++;
            }
            $ids[] = $id;
        }

        return [
            'candidates' => count($candidates),
            'bell'       => $bell,
            'raised'     => $raised,
            'marked'     => self::markSent($ids, $now),
        ];
    }

    /**
     * Grava o aviso do SINO para o DONO da ocorrência. A mensagem nasce
     * PRONTA — o widget só exibe.
     */
    public static function addBellAlert(array $row, ?string $now = null): bool
    {
        $limit = (string) ($row['time_limit'] ?? '');
        $limit = ($limit !== '') ? substr($limit, 0, 5) : '';
        $name  = trim((string) ($row['name'] ?? ''));

        $message = ($limit !== '')
            ? sprintf(__('Horário-limite %s estourado: %s', 'taskplus'), $limit, $name)
            : sprintf(__('Horário-limite estourado: %s', 'taskplus'), $name);

        return self::addBellAlertFor((int) ($row['users_id'] ?? 0), $row, $message, $now);
    }

    /**
     * Núcleo da gravação: um aviso para $usersId sobre a ocorrência da
     * $row. Idempotente na aplicação (procura antes de inserir) E no
     * banco (UNIQUE dedup por users_id+itemtype+items_id+kind — o MESMO
     * estouro pode avisar dono E gestores porque o users_id difere).
     */
    public static function addBellAlertFor(int $usersId, array $row, string $message, ?string $now = null): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $itemsId = (int) ($row['id'] ?? 0);
        if ($itemsId <= 0 || $usersId <= 0) {
            return false;
        }

        $exists = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.users_id' => $usersId,
                self::TABLE . '.itemtype' => Pending::TYPE_OCCURRENCE,
                self::TABLE . '.items_id' => $itemsId,
                self::TABLE . '.kind'     => self::EVENT_TIME_LIMIT,
            ],
        ]);
        foreach ($exists as $ignored) {
            return false; // já avisado
        }

        $DB->insert(self::TABLE, [
            'users_id'      => $usersId,
            'itemtype'      => Pending::TYPE_OCCURRENCE,
            'items_id'      => $itemsId,
            'kind'          => self::EVENT_TIME_LIMIT,
            'message'       => $message,
            'is_read'       => 0,
            'date_creation' => $now ?? date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Dispara o evento no mecanismo nativo. O item vai RECARREGADO do
     * banco (getFromDB) — o template renderiza o estado real da linha,
     * não o retrato da consulta de candidatas.
     */
    public static function raiseTimeLimit(int $occurrenceId): bool
    {
        $item = new OccurrenceAlert();
        if ($occurrenceId <= 0 || !$item->getFromDB($occurrenceId)) {
            return false;
        }

        return (bool) NotificationEvent::raiseEvent(self::EVENT_TIME_LIMIT, $item);
    }

    /**
     * Carimba `date_alert_limit` (e `date_mod`) nas linhas alertadas.
     */
    public static function markSent(array $ids, ?string $now = null): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        $now = $now ?? date('Y-m-d H:i:s');

        $DB->update(
            Occurrence::TABLE,
            [
                'date_alert_limit' => $now,
                'date_mod'         => $now,
            ],
            ['id' => $ids]
        );

        return count($ids);
    }

    /**
     * Gestores por dono: [users_id do técnico => [ids dos gestores]].
     *
     * Gestor aqui = usuário com `is_manager` em glpi_groups_users num
     * grupo de que o técnico é MEMBRO — a mesma marca que sustenta o
     * Access::managedGroups (a tela Equipe). O próprio técnico nunca
     * entra (dono já recebe o aviso dele). Se o marcado como gestor não
     * tiver direito no Task+, a linha fica dormente: o endpoint do sino
     * é gateado pelo direito e ele nem abre as telas.
     *
     * Duas consultas em lote e junção em PHP (higiene da casa: sem JOIN
     * com colunas ambíguas; lista vazia nunca vira filtro que "some").
     */
    public static function managersByOwner(array $candidates): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $owners = [];
        foreach ($candidates as $row) {
            $uid = (int) ($row['users_id'] ?? 0);
            if ($uid > 0) {
                $owners[$uid] = true;
            }
        }
        if ($owners === []) {
            return [];
        }

        // 1) grupos de cada técnico
        $groupsByOwner = [];
        $allGroups     = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => ['glpi_groups_users.users_id' => array_keys($owners) ?: [0]],
        ]) as $row) {
            $uid = (int) ($row['users_id'] ?? 0);
            $gid = (int) ($row['groups_id'] ?? 0);
            if ($uid > 0 && $gid > 0) {
                $groupsByOwner[$uid][$gid] = true;
                $allGroups[$gid] = true;
            }
        }
        if ($allGroups === []) {
            return [];
        }

        // 2) gestores desses grupos
        $managersByGroup = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => [
                'glpi_groups_users.groups_id'  => array_keys($allGroups) ?: [0],
                'glpi_groups_users.is_manager' => 1,
            ],
        ]) as $row) {
            $gid = (int) ($row['groups_id'] ?? 0);
            $mid = (int) ($row['users_id'] ?? 0);
            if ($gid > 0 && $mid > 0) {
                $managersByGroup[$gid][$mid] = true;
            }
        }

        // 3) união por dono, sem o próprio técnico
        $out = [];
        foreach ($groupsByOwner as $uid => $gids) {
            $set = [];
            foreach (array_keys($gids) as $gid) {
                foreach (array_keys($managersByGroup[$gid] ?? []) as $mid) {
                    if ($mid !== $uid) {
                        $set[$mid] = true;
                    }
                }
            }
            if ($set !== []) {
                $mids = array_keys($set);
                sort($mids);
                $out[$uid] = $mids;
            }
        }

        return $out;
    }

    /**
     * Nome exibível por dono candidato (mesma régua do Team.php:
     * firstname + realname; vazio cai no login).
     */
    public static function ownerNames(array $candidates): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $owners = [];
        foreach ($candidates as $row) {
            $uid = (int) ($row['users_id'] ?? 0);
            if ($uid > 0) {
                $owners[$uid] = true;
            }
        }
        if ($owners === []) {
            return [];
        }

        $names = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['glpi_users.id' => array_keys($owners) ?: [0]],
        ]) as $row) {
            $uid   = (int) ($row['id'] ?? 0);
            $label = trim(
                (string) ($row['firstname'] ?? '') . ' ' . (string) ($row['realname'] ?? '')
            );
            if ($label === '') {
                $label = (string) ($row['name'] ?? '');
            }
            if ($uid > 0) {
                $names[$uid] = $label;
            }
        }

        return $names;
    }

    // =====================================================================
    // API do SINO (consumida por ajax/alerts.php via handle())
    // =====================================================================
    /**
     * Conteúdo do sino: não lidas (até 20) + lidas recentes (até 10),
     * mais novas primeiro, SÓ do próprio usuário. Cada item sai pronto
     * para o widget: id, message, date_creation, is_read.
     */
    public static function bellPayload(int $usersId): array
    {
        return [
            'unread' => self::bellRows($usersId, 0, 20),
            'read'   => self::bellRows($usersId, 1, 10),
        ];
    }

    private static function bellRows(int $usersId, int $isRead, int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                self::TABLE . '.id',
                self::TABLE . '.message',
                self::TABLE . '.date_creation',
                self::TABLE . '.is_read',
            ],
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.users_id' => $usersId,
                self::TABLE . '.is_read'  => $isRead,
            ],
            'ORDER' => self::TABLE . '.id DESC',
            'LIMIT' => $limit,
        ]) as $row) {
            $rows[] = [
                'id'            => (int) $row['id'],
                'message'       => (string) ($row['message'] ?? ''),
                'date_creation' => (string) ($row['date_creation'] ?? ''),
                'is_read'       => (int) ($row['is_read'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Roteia as ações do endpoint. Posse revalidada NA CONSULTA de cada
     * escrita (users_id no WHERE — T18): marcar alerta alheio é no-op.
     *
     * Retorno SEMPRE com success/message + o payload novo do sino (o
     * widget re-renderiza de graça a cada resposta).
     */
    public static function handle(string $action, array $post, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        switch ($action) {
            case 'list':
                return ['success' => true, 'message' => '', 'data' => self::bellPayload($usersId)];

            case 'read':
                $id = (int) ($post['id'] ?? 0);
                if ($id > 0) {
                    $DB->update(
                        self::TABLE,
                        ['is_read' => 1],
                        ['id' => $id, 'users_id' => $usersId]
                    );
                }
                return ['success' => true, 'message' => '', 'data' => self::bellPayload($usersId)];

            case 'read_all':
                $DB->update(
                    self::TABLE,
                    ['is_read' => 1],
                    ['users_id' => $usersId, 'is_read' => 0]
                );
                return ['success' => true, 'message' => '', 'data' => self::bellPayload($usersId)];
        }

        return ['success' => false, 'message' => __('Ação desconhecida.', 'taskplus'), 'data' => self::bellPayload($usersId)];
    }
}
