<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — fases do quadro (Etapa 4c, revista na 4c-2: fases POR SETOR).
 *
 * Camada única de dados/ações da tabela glpi_plugin_taskplus_phases,
 * usada pelo endpoint ajax/phase.php e pelo front/config.form.php.
 * Mesmo padrão do Routine/Occurrence: payload()/handle() testáveis por
 * harness, o endpoint ajax/ fica FINO.
 *
 * MODELO DA 4c-2 (decisão de produto nº 8, revisada na sessão 7):
 *  - fases GLOBAIS são só as 4 de SISTEMA (late/today/pending/done), que
 *    espelham os KPIs; toda fase CUSTOMIZADA pertence a um SETOR — um
 *    grupo do GLPI (`groups_id` > 0). Não existe customizada global;
 *  - customizadas antigas (criadas na 4c, quando fases eram globais)
 *    ficam com `groups_id` = 0: aparecem SÓ para o admin, com a marca
 *    "sem setor", e podem ser excluídas/recriadas no setor certo — o
 *    setor é IMUTÁVEL, então não há "atribuir depois";
 *  - quem administra: admin (`config` UPDATE) mexe em tudo; gestor
 *    (`plugin_taskplus_manage` + `is_manager` em grupos) mexe só nas
 *    fases dos seus setores e NÃO edita as de sistema. O escopo chega
 *    pronto do endpoint ($isAdmin + $managedGroupIds) e é validado AQUI,
 *    POR AÇÃO — nunca só escondendo botão no JS;
 *  - nome único por setor (case-insensitive, entre ativas): setores
 *    diferentes PODEM repetir nome — no quadro cada fase leva a tag do
 *    setor dono;
 *  - o resto vale como na 4c: sistema renomeia/recolore mas não sai do
 *    lugar nem some; `system_key` nunca vem do POST; excluir é soft e
 *    devolve as tarefas à fase padrão; toda consulta usa coluna
 *    qualificada (erro 1052).
 *
 * ORDEM CANÔNICA das colunas do quadro:
 *
 *     Atrasadas · Para hoje · [setores em ordem de nome, cada um com a
 *     própria ordem interna] · Pendentes · Concluídas
 *
 * As legadas "sem setor" (nome de setor vazio) ordenam antes dos setores
 * nomeados — efeito colateral natural do sort por nome, e suficiente,
 * já que são transitórias. `renumber()` reescreve `position` (10, 20…)
 * de TODAS as ativas nessa ordem após cada operação estrutural; payload
 * e renumber usam o MESMO ordenador, então renomear um grupo no GLPI
 * reordena os setores na tela sem estado órfão.
 *
 * Leitura já definida para o 4d: o quadro do usuário = fases de sistema
 * + fases dos grupos em que ele é MEMBRO (união, com tag do setor).
 */
class Phase
{
    public const TABLE       = 'glpi_plugin_taskplus_phases';
    public const OCC_TABLE   = 'glpi_plugin_taskplus_occurrences';
    public const GROUP_TABLE = 'glpi_groups';

    /** Âncoras de sistema ANTES das customizadas, na ordem. */
    private const ORDER_HEAD = ['late', 'today'];

    /** Âncoras de sistema DEPOIS das customizadas, na ordem. */
    private const ORDER_TAIL = ['pending', 'done'];

    // =====================================================================
    // Payload da seção "Fases do quadro" (tela Configurações)
    // =====================================================================

    /**
     * Tudo que a seção de fases precisa para renderizar:
     *
     *   [
     *     'phases'         => [itens na ordem do quadro, já filtrados
     *                          pelo escopo de quem pede],
     *     'managed_groups' => [{id, name} dos setores onde quem pede
     *                          PODE criar fases, em ordem de nome],
     *     'is_admin'       => bool,
     *   ]
     *
     * $managedGroups: mapa id => nome vindo de Access::managedGroups()
     * (admin = todos os grupos; gestor = só os que gerencia).
     * ATENÇÃO safeData(): chave nova aqui precisa entrar no config.js.
     */
    public static function payload(bool $isAdmin, array $managedGroups): array
    {
        $groupNames = self::groupNames();
        $managedIds = array_map('intval', array_keys($managedGroups));

        $phases = [];
        foreach (self::orderedRows($groupNames) as $row) {
            $isSystem = ((int) ($row['is_system'] ?? 0)) === 1;
            $gid      = (int) ($row['groups_id'] ?? 0);

            // Gestor só vê o que administra (+ as de sistema, como
            // âncoras de contexto, sem botões — can_edit abaixo).
            if (!$isAdmin && !$isSystem && !in_array($gid, $managedIds, true)) {
                continue;
            }

            $phases[] = self::format($row, $groupNames, $isAdmin, $managedIds);
        }

        $managed = [];
        foreach ($managedGroups as $id => $name) {
            $managed[] = ['id' => (int) $id, 'name' => (string) $name];
        }
        usort($managed, static function (array $a, array $b): int {
            $cmp = mb_strtolower($a['name']) <=> mb_strtolower($b['name']);
            return ($cmp !== 0) ? $cmp : ($a['id'] <=> $b['id']);
        });

        return [
            'phases'         => $phases,
            'managed_groups' => $managed,
            'is_admin'       => $isAdmin,
        ];
    }

    /**
     * Fases ativas SEM ordenação de negócio (position ASC, id ASC — a
     * ordem "crua" do banco). A ordem canônica é de orderedRows().
     */
    private static function activeRows(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [self::TABLE . '.is_deleted' => 0],
            'ORDER' => [self::TABLE . '.position ASC', self::TABLE . '.id ASC'],
        ]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Fase ativa pelo id, ou null.
     */
    private static function activeRow(int $id): ?array
    {
        foreach (self::activeRows() as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Nome de todos os grupos do GLPI: [id => name]. A tabela de grupos
     * de qualquer instalação real é pequena — trazer tudo e resolver em
     * PHP é mais simples e cobre também os JOINs que não fazemos.
     */
    private static function groupNames(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $names = [];
        foreach ($DB->request(['FROM' => self::GROUP_TABLE]) as $row) {
            $names[(int) ($row['id'] ?? 0)] = (string) ($row['name'] ?? '');
        }

        return $names;
    }

    /**
     * Fases ativas na ORDEM CANÔNICA:
     * head de sistema · customizadas agrupadas por setor (setores por
     * nome, dentro do setor por position/id, ou pela ordem imposta em
     * $sectorOverride[gid] = [ids…], usada pelo move) · tail de sistema.
     * Sistema com system_key desconhecido vai para o fim — o ordenador
     * NUNCA perde linha.
     */
    private static function orderedRows(array $groupNames, array $sectorOverride = []): array
    {
        $byKey    = [];
        $unknown  = [];
        $bySector = []; // gid => rows (ordem crua: position, id)

        foreach (self::activeRows() as $row) {
            $isSystem = ((int) ($row['is_system'] ?? 0)) === 1;
            $key      = (string) ($row['system_key'] ?? '');

            if ($isSystem && in_array($key, array_merge(self::ORDER_HEAD, self::ORDER_TAIL), true)) {
                $byKey[$key] = $row;
            } elseif ($isSystem) {
                $unknown[] = $row;
            } else {
                $bySector[(int) ($row['groups_id'] ?? 0)][] = $row;
            }
        }

        // Ordem imposta dentro de um setor (move): reordena pela posição
        // do id na lista; id fora da lista (estado defasado) vai ao fim.
        foreach ($sectorOverride as $gid => $orderIds) {
            if (!isset($bySector[$gid])) {
                continue;
            }
            usort($bySector[$gid], static function (array $a, array $b) use ($orderIds): int {
                $ia = array_search((int) ($a['id'] ?? 0), $orderIds, true);
                $ib = array_search((int) ($b['id'] ?? 0), $orderIds, true);
                $ia = ($ia === false) ? PHP_INT_MAX : $ia;
                $ib = ($ib === false) ? PHP_INT_MAX : $ib;
                return $ia <=> $ib;
            });
        }

        // Setores em ordem de nome (case-insensitive; desempate por id).
        // Legadas "sem setor" têm nome vazio e caem naturalmente antes.
        $gids = array_keys($bySector);
        usort($gids, static function (int $a, int $b) use ($groupNames): int {
            $na  = mb_strtolower((string) ($groupNames[$a] ?? ''));
            $nb  = mb_strtolower((string) ($groupNames[$b] ?? ''));
            $cmp = $na <=> $nb;
            return ($cmp !== 0) ? $cmp : ($a <=> $b);
        });

        $ordered = [];
        foreach (self::ORDER_HEAD as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
            }
        }
        foreach ($gids as $gid) {
            foreach ($bySector[$gid] as $row) {
                $ordered[] = $row;
            }
        }
        foreach (self::ORDER_TAIL as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
            }
        }
        foreach ($unknown as $row) {
            $ordered[] = $row;
        }

        return $ordered;
    }

    /**
     * Linha do banco → item do payload (formatos prontos para o JS).
     * `can_edit` já vem calculado do servidor — o JS só o usa para NÃO
     * desenhar botões inúteis; o endpoint revalida por ação de qualquer
     * jeito.
     */
    private static function format(array $row, array $groupNames, bool $isAdmin, array $managedIds): array
    {
        $isSystem = ((int) ($row['is_system'] ?? 0)) === 1;
        $gid      = (int) ($row['groups_id'] ?? 0);

        if ($isSystem) {
            $canEdit = $isAdmin;                       // renomear/recolorir sistema é só do admin
        } else {
            $canEdit = $isAdmin || in_array($gid, $managedIds, true);
        }

        $groupName = '';
        if (!$isSystem && $gid > 0) {
            // Grupo apagado do GLPI depois da criação da fase: mostra a
            // referência numérica em vez de sumir com a informação.
            $groupName = (string) ($groupNames[$gid] ?? ('Setor #' . $gid));
        }

        return [
            'id'         => (int) ($row['id'] ?? 0),
            'name'       => (string) ($row['name'] ?? ''),
            'color'      => self::normalizeColor((string) ($row['color'] ?? '')) ?? '#5a6b7b',
            'position'   => (int) ($row['position'] ?? 0),
            'is_system'  => $isSystem,
            'is_default' => ((int) ($row['is_default'] ?? 0)) === 1,
            'system_key' => (string) ($row['system_key'] ?? ''),
            'groups_id'  => $gid,
            'group_name' => $groupName,
            'can_edit'   => $canEdit,
        ];
    }

    // =====================================================================
    // Ações do endpoint ajax
    // =====================================================================

    /**
     * Despacha a ação vinda do POST. Sempre devolve
     * ['success' => bool, 'message' => string] — o endpoint completa com
     * o token CSRF novo e o payload atualizado.
     *
     * O ESCOPO de quem pede chega pronto do endpoint:
     *  - $isAdmin: direito nativo `config` UPDATE;
     *  - $managedGroupIds: ids dos grupos que o ator gerencia
     *    (Access::managedGroups — para admin ele traz todos, mas aqui o
     *    $isAdmin já libera tudo sozinho, por clareza da regra).
     * $usersId segue por simetria com os demais handle() do plugin.
     */
    public static function handle(string $action, array $input, int $usersId, bool $isAdmin, array $managedGroupIds): array
    {
        $managedGroupIds = array_map('intval', $managedGroupIds);

        switch ($action) {
            case 'add':
                return self::add($input, $isAdmin, $managedGroupIds);
            case 'update':
                return self::update($input, $isAdmin, $managedGroupIds);
            case 'delete':
                return self::delete($input, $isAdmin, $managedGroupIds);
            case 'move':
                return self::move($input, $isAdmin, $managedGroupIds);
            case 'list':
                // Só quer o payload atualizado (o endpoint já o inclui)
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    private static function add(array $input, bool $isAdmin, array $managedGroupIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // 4c-2: toda customizada nasce com dono. O setor vem do POST só
        // na CRIAÇÃO — depois é imutável (update ignora groups_id).
        $gid = (int) ($input['groups_id'] ?? 0);
        if ($gid <= 0) {
            return ['success' => false, 'message' => __('Informe o setor da fase', 'taskplus')];
        }

        $groupNames = self::groupNames();
        if (!isset($groupNames[$gid])) {
            return ['success' => false, 'message' => __('Setor inválido', 'taskplus')];
        }
        if (!$isAdmin && !in_array($gid, $managedGroupIds, true)) {
            return ['success' => false, 'message' => __('Sem permissão para este setor', 'taskplus')];
        }

        $fields = self::cleanFields($input, 0, $gid);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $now = date('Y-m-d H:i:s');

        // position provisória alta: renumber() coloca a fase nova no fim
        // do trecho do SEU setor (a ordem entre setores é por nome).
        $DB->insert(self::TABLE, $fields + [
            'groups_id'     => $gid,
            'position'      => 1000,
            'is_default'    => 0,
            'is_system'     => 0,
            'system_key'    => '',
            'is_deleted'    => 0,
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);

        self::renumber();

        return ['success' => true, 'message' => __('Fase criada', 'taskplus')];
    }

    private static function update(array $input, bool $isAdmin, array $managedGroupIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::activeRow((int) ($input['id'] ?? 0));
        if ($row === null) {
            return ['success' => false, 'message' => __('Fase não encontrada', 'taskplus')];
        }

        $denied = self::checkScope($row, $isAdmin, $managedGroupIds);
        if ($denied !== null) {
            return $denied;
        }

        $fields = self::cleanFields($input, (int) $row['id'], (int) ($row['groups_id'] ?? 0));
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        // Só nome e cor passam por aqui — groups_id (setor IMUTÁVEL),
        // position, is_default, is_system e system_key NUNCA são aceitos
        // do formulário (proteção das fases de sistema, da ordem canônica
        // e do dono da fase).
        $DB->update(
            self::TABLE,
            $fields + ['date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Fase atualizada', 'taskplus')];
    }

    private static function delete(array $input, bool $isAdmin, array $managedGroupIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::activeRow((int) ($input['id'] ?? 0));
        if ($row === null) {
            return ['success' => false, 'message' => __('Fase não encontrada', 'taskplus')];
        }
        if (((int) ($row['is_system'] ?? 0)) === 1) {
            return ['success' => false, 'message' => __('Fases de sistema não podem ser excluídas', 'taskplus')];
        }
        if (((int) ($row['is_default'] ?? 0)) === 1) {
            // Guarda dupla: hoje a padrão é de sistema, mas a regra é da
            // fase padrão em si (decisão nº 8), não do system_key.
            return ['success' => false, 'message' => __('A fase padrão não pode ser excluída', 'taskplus')];
        }

        $denied = self::checkScope($row, $isAdmin, $managedGroupIds);
        if ($denied !== null) {
            return $denied;
        }

        // Decisão nº 8: excluir fase joga as tarefas dela na padrão.
        // (Pré-4d isso é no-op — plugin_taskplus_phases_id está NULL em
        // todas as ocorrências — mas a regra já nasce correta.)
        $default = self::defaultRow();
        if ($default !== null) {
            $DB->update(
                self::OCC_TABLE,
                ['plugin_taskplus_phases_id' => (int) $default['id']],
                [self::OCC_TABLE . '.plugin_taskplus_phases_id' => (int) $row['id']]
            );
        }

        // Soft delete sempre — mesma trilha do Histórico (Etapa 6).
        $DB->update(
            self::TABLE,
            ['is_deleted' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        self::renumber();

        return ['success' => true, 'message' => __('Fase excluída', 'taskplus')];
    }

    private static function move(array $input, bool $isAdmin, array $managedGroupIds): array
    {
        $row = self::activeRow((int) ($input['id'] ?? 0));
        if ($row === null) {
            return ['success' => false, 'message' => __('Fase não encontrada', 'taskplus')];
        }
        if (((int) ($row['is_system'] ?? 0)) === 1) {
            return ['success' => false, 'message' => __('Fases de sistema não mudam de posição', 'taskplus')];
        }

        $denied = self::checkScope($row, $isAdmin, $managedGroupIds);
        if ($denied !== null) {
            return $denied;
        }

        $dir = (string) ($input['dir'] ?? '');
        if ($dir !== 'up' && $dir !== 'down') {
            return ['success' => false, 'message' => __('Direção inválida', 'taskplus')];
        }

        // 4c-2: as setas só andam DENTRO do setor da fase — a ordem entre
        // setores é por nome do grupo, não é editável.
        $gid        = (int) ($row['groups_id'] ?? 0);
        $groupNames = self::groupNames();

        $sectorIds = [];
        foreach (self::orderedRows($groupNames) as $r) {
            if (((int) ($r['is_system'] ?? 0)) !== 1 && ((int) ($r['groups_id'] ?? 0)) === $gid) {
                $sectorIds[] = (int) $r['id'];
            }
        }

        $idx = array_search((int) $row['id'], $sectorIds, true);
        if ($idx === false) {
            return ['success' => false, 'message' => __('Fase não encontrada', 'taskplus')];
        }

        $target = ($dir === 'up') ? $idx - 1 : $idx + 1;
        if ($target < 0 || $target >= count($sectorIds)) {
            // Já está na ponta do setor: no-op amigável (o JS desabilita
            // o botão, isto só protege contra estado defasado).
            return ['success' => true, 'message' => ''];
        }

        [$sectorIds[$idx], $sectorIds[$target]] = [$sectorIds[$target], $sectorIds[$idx]];

        self::renumber([$gid => $sectorIds]);

        return ['success' => true, 'message' => __('Ordem atualizada', 'taskplus')];
    }

    // =====================================================================
    // Auxiliares
    // =====================================================================

    /**
     * Escopo por ação (4c-2): admin mexe em tudo; gestor só em fase
     * CUSTOMIZADA de setor que gerencia. Devolve o array de erro pronto,
     * ou null quando pode seguir.
     */
    private static function checkScope(array $row, bool $isAdmin, array $managedGroupIds): ?array
    {
        if ($isAdmin) {
            return null;
        }

        if (((int) ($row['is_system'] ?? 0)) === 1) {
            return ['success' => false, 'message' => __('Fases de sistema só podem ser alteradas pelo administrador', 'taskplus')];
        }

        $gid = (int) ($row['groups_id'] ?? 0);
        if (!in_array($gid, $managedGroupIds, true)) {
            return ['success' => false, 'message' => __('Sem permissão para este setor', 'taskplus')];
        }

        return null;
    }

    /**
     * Fase padrão ativa (is_default = 1), ou null.
     */
    private static function defaultRow(): ?array
    {
        foreach (self::activeRows() as $row) {
            if (((int) ($row['is_default'] ?? 0)) === 1) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Valida e normaliza os campos editáveis (nome e cor).
     * Devolve o array de campos OU a mensagem de erro (string) — mesmo
     * contrato do Routine::cleanFields.
     *
     * 4c-2: nome único POR SETOR ($scopeGid) — setores diferentes podem
     * repetir nome (no quadro cada fase leva a tag do setor). Fases de
     * sistema e legadas "sem setor" compartilham o escopo groups_id = 0.
     */
    private static function cleanFields(array $input, int $selfId, int $scopeGid)
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return __('Informe o nome da fase', 'taskplus');
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $color = self::normalizeColor((string) ($input['color'] ?? ''));
        if ($color === null) {
            return __('Cor inválida', 'taskplus');
        }

        foreach (self::activeRows() as $row) {
            if ((int) ($row['id'] ?? 0) === $selfId) {
                continue;
            }
            if (((int) ($row['groups_id'] ?? 0)) !== $scopeGid) {
                continue;
            }
            if (mb_strtolower((string) ($row['name'] ?? '')) === mb_strtolower($name)) {
                return __('Já existe uma fase com esse nome neste setor', 'taskplus');
            }
        }

        return ['name' => $name, 'color' => $color];
    }

    /**
     * '#abc' / '#AABBCC' → '#aabbcc'; qualquer outra coisa → null.
     * (O input type=color do navegador manda #rrggbb, mas o endpoint não
     * confia no cliente.)
     */
    private static function normalizeColor(string $color): ?string
    {
        $color = strtolower(trim($color));

        if (preg_match('/^#[0-9a-f]{6}$/', $color)) {
            return $color;
        }
        if (preg_match('/^#([0-9a-f]{3})$/', $color, $m)) {
            $c = $m[1];
            return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }
        return null;
    }

    /**
     * Reescreve `position` (10, 20, 30…) de TODAS as fases ativas na
     * ordem canônica de orderedRows(). $sectorOverride impõe a ordem das
     * customizadas de UM setor (usado pelo move).
     */
    private static function renumber(array $sectorOverride = []): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $pos = 10;
        foreach (self::orderedRows(self::groupNames(), $sectorOverride) as $row) {
            if ((int) ($row['position'] ?? 0) !== $pos) {
                $DB->update(
                    self::TABLE,
                    ['position' => $pos],
                    [self::TABLE . '.id' => (int) ($row['id'] ?? 0)]
                );
            }
            $pos += 10;
        }
    }
}
