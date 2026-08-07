<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — pendências (Etapa 4b).
 *
 * "Pendente" = vou fazer, mas não agora: o usuário informa um motivo e a
 * data em que a tarefa volta ao fluxo. Diferente de "pular" (não era para
 * fazer naquele dia, acabou ali) e de "concluir".
 *
 * Mora em tabela própria, e não em colunas da ocorrência, porque também
 * vale para tarefa de chamado e de projeto — linhas do GLPI, onde o
 * plugin não escreve. Daí o par itemtype/items_id.
 *
 * A pendência é SEMPRE do usuário que a marcou: marcar um chamado como
 * pendente não muda nada para os outros técnicos nem no chamado em si.
 *
 * Expira sozinha: passada a `pending_until`, a tarefa volta ao fluxo
 * normal sem precisar de cron nem de ação do usuário — a leitura filtra
 * por data. A linha fica no banco para a trilha do Histórico (Etapa 5).
 */
class Pending
{
    public const TABLE = 'glpi_plugin_taskplus_pendings';

    /** Tipos que aceitam pendência. */
    public const TYPE_OCCURRENCE   = 'Occurrence';
    public const TYPE_TICKET_TASK  = 'TicketTask';
    public const TYPE_PROJECT_TASK = 'ProjectTask';

    public const TYPES = [
        self::TYPE_OCCURRENCE,
        self::TYPE_TICKET_TASK,
        self::TYPE_PROJECT_TASK,
    ];

    /**
     * Pendências ATIVAS do usuário, indexadas por "itemtype:items_id"
     * para o payload cruzar com os itens sem consulta por linha.
     *
     * Ajuste 2 do 4b: a expiração agora é por DATA+HORA, não só data.
     * `pending_until >= $today` no WHERE é só um pré-filtro grosso (deixa
     * o MySQL descartar o grosso das linhas antigas); a comparação fina
     * contra o minuto atual é feita em PHP logo abaixo — mesma lógica que
     * o resto do plugin já usa para não depender de expressão SQL frágil
     * entre DATE e TIME.
     */
    public static function activeMap(int $usersId, ?string $today = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $today   = $today ?? date('Y-m-d');
        $nowStamp = date('Y-m-d H:i:s');
        $map     = [];

        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.users_id'      => $usersId,
                self::TABLE . '.is_active'     => 1,
                self::TABLE . '.pending_until' => ['>=', $today],
            ],
        ]) as $row) {
            $until = (string) ($row['pending_until'] ?? '');
            $time  = self::normalizeTime((string) ($row['pending_time'] ?? ''));
            $stamp = $until . ' ' . $time . ':00';

            // Mesmo dia, mas o horário já passou: já não é mais pendente.
            if ($stamp < $nowStamp) {
                continue;
            }

            $key = (string) $row['itemtype'] . ':' . (int) $row['items_id'];
            $map[$key] = [
                'id'     => (int) $row['id'],
                'reason' => (string) ($row['reason'] ?? ''),
                'until'  => $until,
                'time'   => $time,
                'label'  => self::label($until, $time),
            ];
        }

        return $map;
    }

    /** "2026-08-12", "14:30" → "volta em 12/08 14:30" */
    private static function label(string $until, string $time = ''): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $until, $m)) {
            return '';
        }
        $label = 'volta em ' . $m[3] . '/' . $m[2];
        if ($time !== '') {
            $label .= ' ' . $time;
        }
        return $label;
    }

    /** "14:30:00" ou "14:30" → "14:30". Vazio/lixo vira '23:59' (fim do dia). */
    private static function normalizeTime(string $raw): string
    {
        if (preg_match('/^(\d{2}):(\d{2})/', $raw, $m)) {
            return $m[1] . ':' . $m[2];
        }
        return '23:59';
    }

    /**
     * Marca (ou atualiza) a pendência do item para este usuário.
     * Devolve ['success' => bool, 'message' => string].
     */
    public static function set(string $itemtype, int $itemsId, int $usersId, array $input): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!in_array($itemtype, self::TYPES, true) || $itemsId <= 0) {
            return ['success' => false, 'message' => __('Item inválido para pendência', 'taskplus')];
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            return ['success' => false, 'message' => __('Informe o motivo da pendência', 'taskplus')];
        }

        $until = trim((string) ($input['pending_until'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $until, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['success' => false, 'message' => __('Data de retorno inválida', 'taskplus')];
        }

        // Hora obrigatória a partir do ajuste 2 do 4b: sem ela, uma
        // pendência para "hoje" não tem como saber se já venceu ou não.
        $time = trim((string) ($input['pending_time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $mt)) {
            return ['success' => false, 'message' => __('Informe a hora de retorno (HH:MM)', 'taskplus')];
        }
        $time = $mt[1] . ':' . $mt[2];

        if ($until . ' ' . $time . ':00' < date('Y-m-d H:i:s')) {
            return ['success' => false, 'message' => __('A data/hora de retorno não pode ser no passado', 'taskplus')];
        }

        $now      = date('Y-m-d H:i:s');
        $existing = self::rowFor($itemtype, $itemsId, $usersId);

        if ($existing !== null) {
            // Repactuar a pendência reaproveita a linha: o Histórico
            // mostra uma pendência com nova data, não duas soltas.
            $DB->update(self::TABLE, [
                'reason'        => $reason,
                'pending_until' => $until,
                'pending_time'  => $time,
                'is_active'     => 1,
                'date_mod'      => $now,
            ], [self::TABLE . '.id' => (int) $existing['id']]);
        } else {
            $DB->insert(self::TABLE, [
                'itemtype'      => $itemtype,
                'items_id'      => $itemsId,
                'users_id'      => $usersId,
                'reason'        => $reason,
                'pending_until' => $until,
                'pending_time'  => $time,
                'is_active'     => 1,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        }

        return ['success' => true, 'message' => __('Tarefa marcada como pendente', 'taskplus')];
    }

    /**
     * Encerra a pendência (o usuário resolveu antes da data).
     * Mantém a linha com is_active = 0 para a trilha do Histórico.
     */
    public static function clear(string $itemtype, int $itemsId, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::rowFor($itemtype, $itemsId, $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Pendência não encontrada', 'taskplus')];
        }

        $DB->update(
            self::TABLE,
            ['is_active' => 0, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Pendência encerrada', 'taskplus')];
    }

    /** Pendência ATIVA do par item+usuário, se houver. */
    private static function rowFor(string $itemtype, int $itemsId, int $usersId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.itemtype'  => $itemtype,
                self::TABLE . '.items_id'  => $itemsId,
                self::TABLE . '.users_id'  => $usersId,
                self::TABLE . '.is_active' => 1,
            ],
        ]) as $row) {
            return $row;
        }

        return null;
    }
}
