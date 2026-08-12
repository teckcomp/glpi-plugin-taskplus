<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — diálogo das tarefas (Etapa 8e-1, decisão nº 28).
 *
 * Comentários por ocorrência (avulsa ou dia de rotina). A régua de
 * quem interage é UMA só e vale para tudo:
 *
 *   participantes = dono (users_id) + criador (users_id_creator),
 *   quando distinto do dono.
 *
 * Isso cobre os três casos do produto: tarefa "single" (dono = criador,
 * só o dono fala), tarefa criada pelo gestor (gestor e técnico falam) e
 * tarefa de setor (cada cópia é individual — o membro dialoga NA SUA
 * cópia com o gestor que criou). Origens nativas ficam FORA: o diálogo
 * de chamado/projeto vive no objeto nativo do GLPI (decisão nº 3).
 *
 * Excluir comentário: SÓ o autor, soft delete (trilha preservada).
 * A posse é revalidada AQUI, a cada escrita (T18) — nunca herdada do
 * carregamento da página.
 */
class Comment
{
    public const TABLE = 'glpi_plugin_taskplus_comments';

    public const MAX_LENGTH = 2000;

    // ------------------------------------------------------------------
    // Régua de participação
    // ------------------------------------------------------------------

    /**
     * O usuário participa do diálogo desta ocorrência? Regra pura para
     * o harness: dono ou criador (quando distinto).
     */
    public static function canInteract(array $occ, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return $userId === (int) ($occ['users_id'] ?? 0)
            || $userId === (int) ($occ['users_id_creator'] ?? 0);
    }

    /**
     * Ocorrência VIVA do plugin PERTENCENTE ao dono informado, ou null.
     * É a validação de posse que a tela Equipe usa (8e-2): o gestor só
     * alcança o diálogo de ocorrência DO TÉCNICO já validado pelo
     * managedTech — nativas ficam de fora por construção (não estão na
     * tabela de ocorrências).
     */
    public static function occRowOwned(int $occId, int $ownerId): ?array
    {
        $occ = self::occRow($occId);
        if ($occ === null || (int) ($occ['users_id'] ?? 0) !== $ownerId) {
            return null;
        }
        return $occ;
    }

    /**
     * Ocorrência VIVA do plugin, ou null. Comentário em tarefa excluída
     * não existe — o modal nem abre para ela.
     */
    private static function occRow(int $occId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($occId <= 0) {
            return null;
        }
        foreach ($DB->request([
            'FROM'  => Occurrence::TABLE,
            'WHERE' => [
                Occurrence::TABLE . '.id'         => $occId,
                Occurrence::TABLE . '.is_deleted' => 0,
            ],
        ]) as $row) {
            return $row;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    /**
     * Comentários vivos da ocorrência, do mais antigo ao mais novo, com
     * o rótulo do autor resolvido (firstname+realname, fallback no
     * login — mesmo padrão do resto do plugin) e a flag `own` que
     * habilita o excluir no front. O gate de leitura é o MESMO da
     * escrita (8e-1: quem não participa não vê; a leitura do gestor de
     * setor chega com a tela Equipe no 8e-2).
     */
    public static function listFor(int $occId, int $viewerId, bool $managerRead = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $occ = self::occRow($occId);
        if ($occ === null) {
            return [];
        }
        // 8e-2: com $managerRead o ESCOPO já foi validado pelo chamador
        // (Team::commentTech, via managedTech — T18). Sem ele, vale a
        // régua de participação da decisão nº 28.
        if (!$managerRead && !self::canInteract($occ, $viewerId)) {
            return [];
        }

        $rows = [];
        $authorIds = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.plugin_taskplus_occurrences_id' => $occId,
                self::TABLE . '.is_deleted'                     => 0,
            ],
            'ORDER' => [self::TABLE . '.id ASC'],
        ]) as $row) {
            $rows[] = $row;
            $authorIds[(int) $row['users_id']] = true;
        }
        if ($rows === []) {
            return [];
        }

        $labels = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['glpi_users.id' => array_keys($authorIds) ?: [0]],
        ]) as $u) {
            $label = trim(
                (string) ($u['firstname'] ?? '') . ' ' . (string) ($u['realname'] ?? '')
            );
            if ($label === '') {
                $label = (string) ($u['name'] ?? '');
            }
            $labels[(int) ($u['id'] ?? 0)] = $label;
        }

        $out = [];
        foreach ($rows as $row) {
            $authorId = (int) $row['users_id'];
            $when     = (string) ($row['date_creation'] ?? '');
            $out[] = [
                'id'      => (int) $row['id'],
                'author'  => $labels[$authorId] ?? '',
                'own'     => $authorId === $viewerId,
                'date'    => $when !== '' ? date('d/m H:i', strtotime($when)) : '',
                'content' => (string) ($row['content'] ?? ''),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Escrita
    // ------------------------------------------------------------------

    public static function handle(string $action, array $input, int $usersId): array
    {
        switch ($action) {
            case 'list':
                return ['success' => true, 'message' => ''];
            case 'add':
                return self::add($input, $usersId);
            case 'delete':
                return self::delete($input, $usersId);
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    private static function add(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $occId = (int) ($input['occurrences_id'] ?? 0);
        $occ   = self::occRow($occId);
        if ($occ === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        if (!self::canInteract($occ, $usersId)) {
            return ['success' => false, 'message' => __('Você não participa desta tarefa', 'taskplus')];
        }

        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '') {
            return ['success' => false, 'message' => __('Escreva o comentário', 'taskplus')];
        }

        $DB->insert(self::TABLE, [
            'plugin_taskplus_occurrences_id' => $occId,
            'users_id'                       => $usersId,
            'content'                        => mb_substr($content, 0, self::MAX_LENGTH),
            'is_deleted'                     => 0,
            'date_creation'                  => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'message' => __('Comentário enviado', 'taskplus')];
    }

    private static function delete(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $id  = (int) ($input['id'] ?? 0);
        $row = null;
        if ($id > 0) {
            foreach ($DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => [
                    self::TABLE . '.id'         => $id,
                    self::TABLE . '.is_deleted' => 0,
                ],
            ]) as $r) {
                $row = $r;
            }
        }
        if ($row === null) {
            return ['success' => false, 'message' => __('Comentário não encontrado', 'taskplus')];
        }

        // SÓ o autor exclui o próprio comentário (decisão nº 28) —
        // posse na hora da escrita, nunca herdada (T18).
        if ((int) $row['users_id'] !== $usersId) {
            return ['success' => false, 'message' => __('Só o autor pode excluir o comentário', 'taskplus')];
        }

        $DB->update(
            self::TABLE,
            ['is_deleted' => 1],
            [self::TABLE . '.id' => $id]
        );

        return ['success' => true, 'message' => __('Comentário excluído', 'taskplus')];
    }
}
