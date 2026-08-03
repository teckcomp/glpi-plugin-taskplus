<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — rotinas recorrentes (Etapa 2a).
 *
 * Camada única de dados/ações da tabela glpi_plugin_taskplus_routines,
 * usada pelo endpoint ajax/routine.php e pelo front/routines.php. Mesmo
 * padrão do Occurrence.php: payload()/handle() testáveis por harness, o
 * endpoint ajax/ fica FINO.
 *
 * Escopo desta etapa (2a): só CRUD + pausa/retomada. A leitura da rotina
 * para GERAR ocorrências do dia (isDueOn / cron taskplusgen) fica para a
 * 2b — não faz sentido misturar as duas áreas no mesmo bloco.
 *
 * Regras:
 *  - o usuário só enxerga/mexe nas PRÓPRIAS rotinas (users_id = ele);
 *    criar rotina para terceiros é papel do gestor (Etapa 4);
 *  - frequency: 'daily' | 'weekly' | 'monthly'; cada uma valida só os
 *    campos que lhe dizem respeito — os demais vão zerados/vazios;
 *  - monthly aceita OU dia fixo (monthday) OU posição
 *    (monthweek+monthweekday), nunca os dois nem nenhum;
 *  - exclusão é SEMPRE soft (is_deleted = 1) — mesma trilha do Histórico
 *    (Etapa 5) prometida para Occurrence;
 *  - toda consulta usa coluna qualificada (tabela.coluna), mesmo sem
 *    JOIN — higiene herdada do ProjectPlus (erro 1052 quando o JOIN da
 *    Etapa 2b/3 chegar).
 */
class Routine
{
    public const TABLE = 'glpi_plugin_taskplus_routines';

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** ISO-8601: 1 = segunda … 7 = domingo. */
    private const WEEKDAY_SHORT = [
        1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui',
        5 => 'Sex', 6 => 'Sáb', 7 => 'Dom',
    ];

    private const WEEKDAY_FULL = [
        1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira',
        4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo',
    ];

    private const POSITION_LABEL = [
        1 => '1ª', 2 => '2ª', 3 => '3ª', 4 => '4ª', 5 => '5ª', -1 => 'última',
    ];

    // =====================================================================
    // Payload da tela Rotinas
    // =====================================================================

    /**
     * Tudo que a tela Rotinas precisa para renderizar:
     *
     *   [
     *     'today'    => 'Y-m-d' (para o input "Início" do modal),
     *     'routines' => [itens, nome ASC],
     *   ]
     */
    public static function payload(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
            ],
            'ORDER' => self::TABLE . '.name ASC',
        ]) as $row) {
            $rows[] = self::format($row);
        }

        return [
            'today'    => date('Y-m-d'),
            'routines' => $rows,
        ];
    }

    /**
     * Linha do banco → item do payload (formatos prontos para o JS).
     */
    private static function format(array $row): array
    {
        $frequency = (string) ($row['frequency'] ?? 'daily');

        return [
            'id'               => (int) ($row['id'] ?? 0),
            'name'             => (string) ($row['name'] ?? ''),
            'instructions'     => (string) ($row['instructions'] ?? ''),
            'frequency'        => $frequency,
            'frequency_label'  => self::frequencyLabel($frequency),
            'only_workdays'    => ((int) ($row['only_workdays'] ?? 0)) === 1,
            'weekdays'         => self::weekdaysToArray((string) ($row['weekdays'] ?? '')),
            'monthday'         => (int) ($row['monthday'] ?? 0),
            'monthweek'        => (int) ($row['monthweek'] ?? 0),
            'monthweekday'     => (int) ($row['monthweekday'] ?? 0),
            'recurrence_label' => self::recurrenceLabel($row),
            'time_limit'       => (!empty($row['time_limit'])) ? substr((string) $row['time_limit'], 0, 5) : null,
            'is_paused'        => ((int) ($row['is_paused'] ?? 0)) === 1,
            'date_begin'       => (!empty($row['date_begin'])) ? (string) $row['date_begin'] : null,
            'date_end'         => (!empty($row['date_end'])) ? (string) $row['date_end'] : null,
        ];
    }

    private static function frequencyLabel(string $frequency): string
    {
        switch ($frequency) {
            case 'daily':
                return __('Diária', 'taskplus');
            case 'weekly':
                return __('Semanal', 'taskplus');
            case 'monthly':
                return __('Mensal', 'taskplus');
            default:
                return $frequency;
        }
    }

    /**
     * Descrição legível da recorrência ("Seg, Qua, Sex" / "Dia 15 do mês" /
     * "última sexta-feira"). Composta em tempo de execução — por isso não
     * passa pelo __() (xgettext não extrai concatenação); é texto pt-BR
     * direto, mesmo padrão de "fonte da verdade = pt-BR" do restante do
     * plugin. Tradução desses fragmentos fica para quando entrar en-GB.
     */
    private static function recurrenceLabel(array $row): string
    {
        $frequency = (string) ($row['frequency'] ?? 'daily');

        if ($frequency === 'daily') {
            return ((int) ($row['only_workdays'] ?? 0)) === 1
                ? 'Todo dia útil'
                : 'Todo dia';
        }

        if ($frequency === 'weekly') {
            $days = self::weekdaysToArray((string) ($row['weekdays'] ?? ''));
            if (empty($days)) {
                return 'Nenhum dia selecionado';
            }
            $labels = array_map(static function (int $d): string {
                return self::WEEKDAY_SHORT[$d] ?? '?';
            }, $days);
            return implode(', ', $labels);
        }

        if ($frequency === 'monthly') {
            $monthday = (int) ($row['monthday'] ?? 0);
            if ($monthday > 0) {
                return 'Dia ' . $monthday . ' do mês';
            }
            $week    = (int) ($row['monthweek'] ?? 0);
            $weekday = (int) ($row['monthweekday'] ?? 0);
            if ($week !== 0 && $weekday !== 0) {
                $pos = self::POSITION_LABEL[$week] ?? '?';
                $wd  = self::WEEKDAY_FULL[$weekday] ?? '?';
                return $pos . ' ' . $wd;
            }
            return 'Sem posição definida';
        }

        return '';
    }

    // =====================================================================
    // Ações do endpoint ajax
    // =====================================================================

    /**
     * Despacha a ação vinda do POST. Sempre devolve
     * ['success' => bool, 'message' => string] — o endpoint completa com
     * o token CSRF novo e o payload atualizado.
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
            case 'pause':
                return self::pause($input, $usersId);
            case 'list':
                // Só quer o payload atualizado (o endpoint já o inclui)
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    private static function add(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $now = date('Y-m-d H:i:s');

        $DB->insert(self::TABLE, $fields + [
            'users_id'         => $usersId,
            'users_id_creator' => $usersId,
            'is_paused'        => 0,
            'is_deleted'       => 0,
            'date_creation'    => $now,
            'date_mod'         => $now,
        ]);

        return ['success' => true, 'message' => __('Rotina criada', 'taskplus')];
    }

    private static function update(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Rotina não encontrada', 'taskplus')];
        }

        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $DB->update(
            self::TABLE,
            $fields + ['date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Rotina atualizada', 'taskplus')];
    }

    private static function delete(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Rotina não encontrada', 'taskplus')];
        }

        // Soft delete sempre — preserva a trilha do Histórico (Etapa 5).
        // Ocorrências já geradas (Etapa 2b) não são tocadas aqui.
        $DB->update(
            self::TABLE,
            ['is_deleted' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Rotina excluída', 'taskplus')];
    }

    /**
     * Pausar (paused=1) ou retomar (paused=0) a rotina. Rotina pausada
     * não é considerada pelo cron taskplusgen (regra da Etapa 2b).
     */
    private static function pause(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Rotina não encontrada', 'taskplus')];
        }

        $paused = ((int) ($input['paused'] ?? 0)) === 1;

        $DB->update(
            self::TABLE,
            ['is_paused' => $paused ? 1 : 0, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return [
            'success' => true,
            'message' => $paused
                ? __('Rotina pausada', 'taskplus')
                : __('Rotina retomada', 'taskplus'),
        ];
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Valida e normaliza os campos editáveis da rotina. Devolve o array
     * pronto para insert/update, ou a MENSAGEM de erro (string) quando
     * algo inválido chegou.
     */
    private static function cleanFields(array $input): array|string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return __('Informe o nome da rotina', 'taskplus');
        }

        $frequency = (string) ($input['frequency'] ?? '');
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            return __('Frequência inválida', 'taskplus');
        }

        $onlyWorkdays = 0;
        $weekdays     = '';
        $monthday     = 0;
        $monthweek    = 0;
        $monthweekday = 0;

        if ($frequency === 'daily') {
            $onlyWorkdays = ((int) ($input['only_workdays'] ?? 0)) === 1 ? 1 : 0;
        } elseif ($frequency === 'weekly') {
            $days = self::cleanWeekdays((string) ($input['weekdays'] ?? ''));
            if ($days === null) {
                return __('Selecione ao menos um dia da semana', 'taskplus');
            }
            $weekdays = implode(',', $days);
        } elseif ($frequency === 'monthly') {
            $rawMonthday = trim((string) ($input['monthday'] ?? ''));
            $rawWeek     = trim((string) ($input['monthweek'] ?? ''));
            $rawWeekday  = trim((string) ($input['monthweekday'] ?? ''));

            $hasDay = $rawMonthday !== '' && (int) $rawMonthday > 0;
            $hasPos = $rawWeek !== '' && $rawWeekday !== '';

            // XOR: exatamente um dos dois modos, nunca os dois nem nenhum.
            if ($hasDay === $hasPos) {
                return __('Informe o dia fixo OU a posição do mês, não os dois', 'taskplus');
            }

            if ($hasDay) {
                $monthday = (int) $rawMonthday;
                if ($monthday < 1 || $monthday > 31) {
                    return __('Dia do mês inválido', 'taskplus');
                }
            } else {
                $monthweek    = (int) $rawWeek;
                $monthweekday = (int) $rawWeekday;
                if (!in_array($monthweek, [1, 2, 3, 4, 5, -1], true)) {
                    return __('Posição do mês inválida', 'taskplus');
                }
                if ($monthweekday < 1 || $monthweekday > 7) {
                    return __('Dia da semana inválido', 'taskplus');
                }
            }
        }

        $rawTime = trim((string) ($input['time_limit'] ?? ''));
        $time    = null;
        if ($rawTime !== '') {
            $time = self::validTime($rawTime);
            if ($time === null) {
                return __('Horário-limite inválido', 'taskplus');
            }
        }

        $rawBegin = trim((string) ($input['date_begin'] ?? ''));
        $begin    = $rawBegin === '' ? date('Y-m-d') : self::validDate($rawBegin);
        if ($begin === null) {
            return __('Data de início inválida', 'taskplus');
        }

        $rawEnd = trim((string) ($input['date_end'] ?? ''));
        $end    = null;
        if ($rawEnd !== '') {
            $end = self::validDate($rawEnd);
            if ($end === null) {
                return __('Data de término inválida', 'taskplus');
            }
            if ($end < $begin) {
                return __('Término não pode ser antes do início', 'taskplus');
            }
        }

        return [
            'name'          => mb_substr($name, 0, 255),
            'instructions'  => trim((string) ($input['instructions'] ?? '')),
            'frequency'     => $frequency,
            'only_workdays' => $onlyWorkdays,
            'weekdays'      => $weekdays,
            'monthday'      => $monthday,
            'monthweek'     => $monthweek,
            'monthweekday'  => $monthweekday,
            'time_limit'    => $time,
            'date_begin'    => $begin,
            'date_end'      => $end,
        ];
    }

    /**
     * 'Y-m-d' válido, ou null.
     */
    private static function validDate(string $raw): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $raw;
    }

    /**
     * 'HH:MM' (ou 'HH:MM:SS') → 'HH:MM:SS', ou null se inválido.
     */
    private static function validTime(string $raw): ?string
    {
        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            return null;
        }
        if ((int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }
        return $m[1] . ':' . $m[2] . ':00';
    }

    /**
     * Lista de dias ISO (string "1,3,5") → array de int válidos [1..7],
     * únicos, ordenados. Devolve null se nenhum dia sobrar (obrigatório
     * ao menos um em weekly).
     */
    private static function cleanWeekdays(string $raw): ?array
    {
        $days = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $n = (int) $part;
            if ($n >= 1 && $n <= 7 && !in_array($n, $days, true)) {
                $days[] = $n;
            }
        }
        if (empty($days)) {
            return null;
        }
        sort($days);
        return $days;
    }

    /**
     * "1,3,5" (do banco) → [1, 3, 5]. Sem validação (já validado ao
     * gravar) — defesa mínima contra lixo residual.
     */
    private static function weekdaysToArray(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = (int) $part;
            }
        }
        return $out;
    }

    /**
     * A rotina $id, se pertencer a $usersId e não estiver excluída.
     */
    private static function ownRow(int $id, int $usersId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($id <= 0) {
            return null;
        }

        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                self::TABLE . '.id'         => $id,
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
            ],
        ]) as $row) {
            return $row;
        }

        return null;
    }
}
