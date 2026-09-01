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

    /**
     * 9a-1 — marca de leitura da thread: uma linha por (ocorrência,
     * leitor). O "não lido" NÃO é estado do comentário (cada thread tem
     * vários leitores possíveis: dono, criador e, pela Equipe, os
     * gestores do setor) — é estado do PAR.
     */
    public const TABLE_READS = 'glpi_plugin_taskplus_comment_reads';

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
    // Não lidos (Etapa 9a-1)
    // ------------------------------------------------------------------

    /**
     * Quantos comentários NÃO LIDOS cada ocorrência tem para este leitor.
     *
     * Régua (decisão nº 30): conta comentário VIVO de OUTRO autor criado
     * DEPOIS da última abertura da thread pelo leitor. Nunca conta o que
     * o próprio leitor escreveu — quem escreveu já leu.
     *
     * Devolve SEMPRE uma chave por ocorrência pedida (0 quando não há
     * nada novo), para o chamador nunca precisar de `??`.
     *
     * Duas consultas simples e junção em PHP, de propósito: COUNT com
     * GROUPBY no iterator do GLPI 11 descarta os campos do SELECT.
     *
     * Limite conhecido e aceito: `date_read` é TIMESTAMP (1 segundo) e a
     * comparação é estrita, então comentário gravado no MESMO segundo em
     * que o leitor abriu a thread não reacende o contador. A janela é
     * mínima e o comentário seguinte volta a contar; eliminá-la exigiria
     * guardar o id do último comentário lido, não a hora.
     */
    public static function unreadFor(array $occIds, int $viewerId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($occIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        $out = [];
        foreach (array_keys($ids) as $id) {
            $out[$id] = 0;
        }
        if ($out === [] || $viewerId <= 0) {
            return $out;
        }

        // Última leitura do viewer em cada ocorrência (pode não existir:
        // nunca abriu → tudo que houver é novo).
        $reads = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE_READS,
            'WHERE' => [
                self::TABLE_READS . '.plugin_taskplus_occurrences_id' => array_keys($ids),
                self::TABLE_READS . '.users_id'                       => $viewerId,
            ],
        ]) as $row) {
            $reads[(int) $row['plugin_taskplus_occurrences_id']] = (string) ($row['date_read'] ?? '');
        }

        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.plugin_taskplus_occurrences_id' => array_keys($ids),
                self::TABLE . '.is_deleted'                     => 0,
            ],
        ]) as $row) {
            // Autor filtrado em PHP: uma restrição a menos no WHERE e o
            // mesmo resultado (a lista já é a da própria ocorrência).
            if ((int) ($row['users_id'] ?? 0) === $viewerId) {
                continue;
            }
            $occId = (int) ($row['plugin_taskplus_occurrences_id'] ?? 0);
            if (!isset($out[$occId])) {
                continue;
            }
            $when = (string) ($row['date_creation'] ?? '');
            $read = $reads[$occId] ?? '';
            // 'Y-m-d H:i:s' compara direito como string (ordem
            // lexicográfica = ordem cronológica).
            if ($read === '' || $when > $read) {
                $out[$occId]++;
            }
        }

        return $out;
    }

    /**
     * Registra que o leitor acabou de ver a thread desta ocorrência.
     * Upsert manual (o iterator do GLPI não tem ON DUPLICATE KEY): a
     * UNIQUE `occ_user` é a rede de segurança contra corrida.
     */
    public static function markRead(int $occId, int $viewerId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($occId <= 0 || $viewerId <= 0) {
            return;
        }

        $now      = date('Y-m-d H:i:s');
        $existing = 0;
        foreach ($DB->request([
            'FROM'  => self::TABLE_READS,
            'WHERE' => [
                self::TABLE_READS . '.plugin_taskplus_occurrences_id' => $occId,
                self::TABLE_READS . '.users_id'                       => $viewerId,
            ],
        ]) as $row) {
            $existing = (int) ($row['id'] ?? 0);
        }

        if ($existing > 0) {
            $DB->update(
                self::TABLE_READS,
                ['date_read' => $now],
                [self::TABLE_READS . '.id' => $existing]
            );
            return;
        }

        $DB->insert(self::TABLE_READS, [
            'plugin_taskplus_occurrences_id' => $occId,
            'users_id'                       => $viewerId,
            'date_read'                      => $now,
        ]);
    }

    /**
     * Marca leitura SÓ se o leitor de fato alcança a thread pela régua
     * da decisão nº 28 (dono ou criador). É o caminho da tela Hoje: o
     * endpoint não pode gravar leitura de quem não veria nada.
     *
     * A Equipe NÃO usa este método — lá o escopo (gestor → técnico
     * gerido → ocorrência do técnico) já foi revalidado no POST pelo
     * Team::commentTech, e o gestor leitor não é participante.
     */
    public static function markReadIfVisible(int $occId, int $viewerId): bool
    {
        $occ = self::occRow($occId);
        if ($occ === null || !self::canInteract($occ, $viewerId)) {
            return false;
        }
        self::markRead($occId, $viewerId);
        return true;
    }

    /**
     * Injeta `unread` (int) em cada item das listas `today`/`overdue` de
     * um payload da tela Hoje, do ponto de vista do LEITOR informado.
     *
     * Fica FORA do Occurrence::payload de propósito (T32): o payload é
     * compartilhado com Quadro, Semana e Equipe, e na Equipe o leitor é
     * o GESTOR, não o dono das tarefas — contar lá dentro daria o número
     * errado para a tela errada. Cada consumidor decora com o seu leitor.
     *
     * Item nativo recebe 0 sempre (o diálogo dele vive no objeto do
     * GLPI — decisão nº 28).
     */
    public static function withUnread(array $payload, int $viewerId): array
    {
        $keys = ['today', 'overdue'];

        $ids = [];
        foreach ($keys as $key) {
            foreach ((array) ($payload[$key] ?? []) as $item) {
                if (is_array($item) && empty($item['is_native']) && (int) ($item['id'] ?? 0) > 0) {
                    $ids[] = (int) $item['id'];
                }
            }
        }

        $counts = ($ids === []) ? [] : self::unreadFor($ids, $viewerId);

        foreach ($keys as $key) {
            if (!is_array($payload[$key] ?? null)) {
                continue;
            }
            foreach ($payload[$key] as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? 0);
                $payload[$key][$i]['unread'] = (empty($item['is_native']) && $id > 0)
                    ? (int) ($counts[$id] ?? 0)
                    : 0;
            }
        }

        return $payload;
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

    /**
     * 11b (decisão nº 61) — comentário OBRIGATÓRIO da reprovação,
     * gravado no próprio diálogo da tarefa.
     *
     * Fica fora do add() de propósito: a régua da decisão nº 28 (só
     * dono e criador escrevem) NÃO cobre o gestor de setor que valida
     * sem ser o criador — e a decisão nº 61 manda exatamente ele
     * explicar a reprovação. O ESCOPO já foi revalidado pelo chamador
     * (Team::rejectTech: managedTech + occRowOwned + estado aguardando
     * — T18), no MESMO padrão do $managerRead do listFor. O prefixo
     * deixa a trilha autoexplicativa no diálogo e no Histórico.
     */
    public static function addFromValidation(int $occId, int $authorId, string $content): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->insert(self::TABLE, [
            'plugin_taskplus_occurrences_id' => $occId,
            'users_id'                       => $authorId,
            'content'                        => mb_substr(
                __('[Reprovação] ', 'taskplus') . $content,
                0,
                self::MAX_LENGTH
            ),
            'documents_id'                   => 0,
            'is_deleted'                     => 0,
            'date_creation'                  => date('Y-m-d H:i:s'),
        ]);
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
