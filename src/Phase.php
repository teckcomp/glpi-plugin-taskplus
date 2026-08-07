<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — fases do quadro (Etapa 4c).
 *
 * Camada única de dados/ações da tabela glpi_plugin_taskplus_phases,
 * usada pelo endpoint ajax/phase.php e pelo front/config.form.php.
 * Mesmo padrão do Routine/Occurrence: payload()/handle() testáveis por
 * harness, o endpoint ajax/ fica FINO.
 *
 * As fases são as COLUNAS do Quadro (Etapa 4d) e são GLOBAIS (decisão
 * de produto nº 8): valem para todos os usuários, por isso a tela e o
 * endpoint são gateados pelo direito nativo `config` UPDATE — o mesmo
 * da tela de Configurações onde o CRUD mora.
 *
 * Regras (decisão nº 8 + escopo do 4c):
 *  - as 4 fases de SISTEMA (late/today/pending/done) podem mudar de nome
 *    e de cor, mas NÃO podem ser excluídas, NÃO mudam de posição e o
 *    `system_key` nunca é aceito do formulário;
 *  - a fase PADRÃO (`is_default`, hoje "Para hoje") é de sistema e
 *    portanto inexcluível — onde toda tarefa nasce no quadro;
 *  - fases CUSTOMIZADAS vivem SEMPRE entre "Para hoje" e "Pendentes"
 *    (ordem canônica abaixo) e só se reordenam entre si (sobe/desce);
 *  - excluir fase customizada é SOFT e devolve as tarefas dela à fase
 *    padrão (hoje `plugin_taskplus_phases_id` está NULL em todas as
 *    ocorrências — quem preenche é o 4d — mas a regra já fica pronta);
 *  - toda consulta usa coluna qualificada (tabela.coluna), mesmo sem
 *    JOIN — higiene padrão do plugin (erro 1052).
 *
 * ORDEM CANÔNICA das colunas do quadro:
 *
 *     Atrasadas · Para hoje · [customizadas…] · Pendentes · Concluídas
 *
 * `renumber()` reescreve `position` de TODAS as fases ativas nessa ordem
 * (10, 20, 30…) após cada operação estrutural (add/move/delete). O valor
 * numérico é interno; o que importa — e o que o payload entrega — é a
 * ordem resultante.
 */
class Phase
{
    public const TABLE     = 'glpi_plugin_taskplus_phases';
    public const OCC_TABLE = 'glpi_plugin_taskplus_occurrences';

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
     *   ['phases' => [itens na ordem do quadro]]
     */
    public static function payload(): array
    {
        $rows = [];
        foreach (self::activeRows() as $row) {
            $rows[] = self::format($row);
        }

        return ['phases' => $rows];
    }

    /**
     * Fases ativas na ordem do quadro (position ASC, id ASC).
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
     * Linha do banco → item do payload (formatos prontos para o JS).
     */
    private static function format(array $row): array
    {
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'name'       => (string) ($row['name'] ?? ''),
            'color'      => self::normalizeColor((string) ($row['color'] ?? '')) ?? '#5a6b7b',
            'position'   => (int) ($row['position'] ?? 0),
            'is_system'  => ((int) ($row['is_system'] ?? 0)) === 1,
            'is_default' => ((int) ($row['is_default'] ?? 0)) === 1,
            'system_key' => (string) ($row['system_key'] ?? ''),
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
     * $usersId chega por simetria com os demais handle() do plugin; as
     * fases são globais, então ele não entra em WHERE nenhum aqui.
     */
    public static function handle(string $action, array $input, int $usersId): array
    {
        switch ($action) {
            case 'add':
                return self::add($input);
            case 'update':
                return self::update($input);
            case 'delete':
                return self::delete($input);
            case 'move':
                return self::move($input);
            case 'list':
                // Só quer o payload atualizado (o endpoint já o inclui)
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    private static function add(array $input): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $fields = self::cleanFields($input, 0);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $now = date('Y-m-d H:i:s');

        // position provisória alta: renumber() coloca a fase nova no fim
        // do trecho das customizadas (antes de "Pendentes").
        $DB->insert(self::TABLE, $fields + [
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

    private static function update(array $input): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::activeRow((int) ($input['id'] ?? 0));
        if ($row === null) {
            return ['success' => false, 'message' => __('Fase não encontrada', 'taskplus')];
        }

        $fields = self::cleanFields($input, (int) $row['id']);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        // Só nome e cor passam por aqui — position, is_default, is_system
        // e system_key NUNCA são aceitos do formulário (proteção das
        // fases de sistema e da ordem canônica).
        $DB->update(
            self::TABLE,
            $fields + ['date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Fase atualizada', 'taskplus')];
    }

    private static function delete(array $input): array
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

    private static function move(array $input): array
    {
        $row = self::activeRow((int) ($input['id'] ?? 0));
        if ($row === null) {
            return ['success' => false, 'message' => __('Fase não encontrada', 'taskplus')];
        }
        if (((int) ($row['is_system'] ?? 0)) === 1) {
            return ['success' => false, 'message' => __('Fases de sistema não mudam de posição', 'taskplus')];
        }

        $dir = (string) ($input['dir'] ?? '');
        if ($dir !== 'up' && $dir !== 'down') {
            return ['success' => false, 'message' => __('Direção inválida', 'taskplus')];
        }

        // Ordem atual só das customizadas
        $customIds = [];
        foreach (self::activeRows() as $r) {
            if (((int) ($r['is_system'] ?? 0)) !== 1) {
                $customIds[] = (int) $r['id'];
            }
        }

        $idx = array_search((int) $row['id'], $customIds, true);
        if ($idx === false) {
            return ['success' => false, 'message' => __('Fase não encontrada', 'taskplus')];
        }

        $target = ($dir === 'up') ? $idx - 1 : $idx + 1;
        if ($target < 0 || $target >= count($customIds)) {
            // Já está na ponta: no-op amigável (o JS desabilita o botão,
            // isto só protege contra estado defasado).
            return ['success' => true, 'message' => ''];
        }

        [$customIds[$idx], $customIds[$target]] = [$customIds[$target], $customIds[$idx]];

        self::renumber($customIds);

        return ['success' => true, 'message' => __('Ordem atualizada', 'taskplus')];
    }

    // =====================================================================
    // Auxiliares
    // =====================================================================

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
     */
    private static function cleanFields(array $input, int $selfId)
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

        // Nome único entre as ativas (case-insensitive): duas colunas
        // "Em andamento" no quadro só confundem.
        foreach (self::activeRows() as $row) {
            if ((int) ($row['id'] ?? 0) === $selfId) {
                continue;
            }
            if (mb_strtolower((string) ($row['name'] ?? '')) === mb_strtolower($name)) {
                return __('Já existe uma fase com esse nome', 'taskplus');
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
     * Reescreve `position` de TODAS as fases ativas na ordem canônica:
     * âncoras de sistema (head) · customizadas · âncoras de sistema (tail).
     *
     * $customOrderIds, quando presente, dita a ordem das customizadas
     * (usado pelo move); ausente, vale a ordem atual (position, id).
     * Fase de sistema com system_key desconhecido (não deveria existir)
     * é preservada no fim — renumber NUNCA perde linha.
     */
    private static function renumber(?array $customOrderIds = null): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $byKey   = [];
        $customs = [];
        $unknown = [];

        foreach (self::activeRows() as $row) {
            $isSystem = ((int) ($row['is_system'] ?? 0)) === 1;
            $key      = (string) ($row['system_key'] ?? '');

            if ($isSystem && in_array($key, array_merge(self::ORDER_HEAD, self::ORDER_TAIL), true)) {
                $byKey[$key] = $row;
            } elseif ($isSystem) {
                $unknown[] = $row;
            } else {
                $customs[] = $row;
            }
        }

        if ($customOrderIds !== null) {
            usort($customs, static function (array $a, array $b) use ($customOrderIds): int {
                $ia = array_search((int) ($a['id'] ?? 0), $customOrderIds, true);
                $ib = array_search((int) ($b['id'] ?? 0), $customOrderIds, true);
                $ia = ($ia === false) ? PHP_INT_MAX : $ia;
                $ib = ($ib === false) ? PHP_INT_MAX : $ib;
                return $ia <=> $ib;
            });
        }

        $ordered = [];
        foreach (self::ORDER_HEAD as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
            }
        }
        foreach ($customs as $row) {
            $ordered[] = $row;
        }
        foreach (self::ORDER_TAIL as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
            }
        }
        foreach ($unknown as $row) {
            $ordered[] = $row;
        }

        $pos = 10;
        foreach ($ordered as $row) {
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
