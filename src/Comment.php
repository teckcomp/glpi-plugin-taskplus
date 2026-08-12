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

    /**
     * 8e-3 — fábrica do Document do anexo, INJETÁVEL para o harness
     * (mesmo padrão do $raiser do Emails). null = implementação real
     * (createDocument). A de teste devolve int (id) ou string (erro).
     * @var null|callable(array):(int|string)
     */
    public static $documentFactory = null;

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

        // 8e-3: nomes dos anexos em UMA consulta (glpi_documents)
        $docIds = [];
        foreach ($rows as $row) {
            $did = (int) ($row['documents_id'] ?? 0);
            if ($did > 0) {
                $docIds[$did] = true;
            }
        }
        $docNames = [];
        if ($docIds !== []) {
            foreach ($DB->request([
                'FROM'  => 'glpi_documents',
                'WHERE' => ['glpi_documents.id' => array_keys($docIds) ?: [0]],
            ]) as $d) {
                $docNames[(int) ($d['id'] ?? 0)] = (string) ($d['filename'] ?? '');
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $authorId = (int) $row['users_id'];
            $when     = (string) ($row['date_creation'] ?? '');
            $did      = (int) ($row['documents_id'] ?? 0);
            $out[] = [
                'id'        => (int) $row['id'],
                'author'    => $labels[$authorId] ?? '',
                'own'       => $authorId === $viewerId,
                'date'      => $when !== '' ? date('d/m H:i', strtotime($when)) : '',
                'content'   => (string) ($row['content'] ?? ''),
                'file_name' => $did > 0 ? ($docNames[$did] ?? '') : '',
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
        $file    = (isset($input['_file']) && is_array($input['_file'])) ? $input['_file'] : null;

        // 8e-3: comentário precisa de TEXTO ou ANEXO (ou ambos)
        if ($content === '' && $file === null) {
            return ['success' => false, 'message' => __('Escreva o comentário ou anexe um arquivo', 'taskplus')];
        }

        $documentsId = 0;
        if ($file !== null) {
            $made = self::$documentFactory !== null
                ? (self::$documentFactory)($file)
                : self::createDocument($file);
            if (is_string($made)) {
                return ['success' => false, 'message' => $made];
            }
            $documentsId = (int) $made;
        }

        $DB->insert(self::TABLE, [
            'plugin_taskplus_occurrences_id' => $occId,
            'users_id'                       => $usersId,
            'content'                        => mb_substr($content, 0, self::MAX_LENGTH),
            'documents_id'                   => $documentsId,
            'is_deleted'                     => 0,
            'date_creation'                  => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'message' => __('Comentário enviado', 'taskplus')];
    }

    /**
     * 8e-3 — cria o Document NATIVO a partir do upload ($_FILES do
     * endpoint). Caminho validado contra o fonte do core 11.0.6:
     * Document->add() com `_filename`/`_prefix_filename` move o arquivo
     * de GLPI_TMP_DIR, valida a extensão em glpi_documenttypes
     * (isValidDoc) e deduplica o conteúdo por sha1 — armazenamento,
     * tipo e higiene ficam TODOS com o core. Devolve o id (int) ou a
     * MENSAGEM de erro (string), padrão cleanFields da casa.
     */
    private static function createDocument(array $file): int|string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $name = basename((string) ($file['name'] ?? ''));
        if ($name === '' || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return __('Falha no envio do arquivo', 'taskplus');
        }

        $maxMb = (int) ($CFG_GLPI['document_max_size'] ?? 20);
        if ((int) ($file['size'] ?? 0) > $maxMb * 1024 * 1024) {
            return sprintf(__('Arquivo maior que o limite de %d MB', 'taskplus'), $maxMb);
        }

        // Prefixo único no padrão do fileupload do core: o Document
        // remove o prefixo e guarda o nome original.
        $prefix  = uniqid('tpc', false) . '_';
        $tmpPath = GLPI_TMP_DIR . '/' . $prefix . $name;
        $moved   = is_uploaded_file((string) ($file['tmp_name'] ?? ''))
            ? move_uploaded_file($file['tmp_name'], $tmpPath)
            : @rename((string) ($file['tmp_name'] ?? ''), $tmpPath);
        if (!$moved) {
            return __('Falha ao gravar o arquivo temporário', 'taskplus');
        }

        $doc = new \Document();
        $id  = $doc->add([
            'name'                    => $name,
            'entities_id'             => (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'is_recursive'            => 1,
            '_filename'               => [$prefix . $name],
            '_prefix_filename'        => [$prefix],
            '_only_if_upload_succeed' => 1,
        ]);
        if (!$id) {
            @unlink($tmpPath);
            return __('Tipo de arquivo não permitido (Configurar → Tipos de documento)', 'taskplus');
        }
        return (int) $id;
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
