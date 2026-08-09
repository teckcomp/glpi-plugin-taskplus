<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — tela "Painel" (Etapa 6b: indicadores pessoais).
 *
 * Mesmo desenho da Semana (6a): a tela NÃO tem consulta própria. O
 * payload reusa o modo-período do Occurrence::payload (5b-2 p2) e
 * delega a agregação a assemble() — método PURO, sem banco, que o
 * harness valida linha a linha.
 *
 * Recorte travado na abertura do 6b (sessão 17):
 *  - período herda o padrão de–até; bordas vazias caem em "últimos 90
 *    dias" e o recorte é LIMITADO a 180 dias (o payload avisa com
 *    `period.clamped` quando encolheu o pedido);
 *  - SÓ o painel pessoal nesta fase — visão do gestor sobre a equipe e
 *    taxa por responsável são o 6b-2;
 *  - conteúdo: taxa de conclusão do período, heatmap calendário (estilo
 *    GitHub: colunas = semanas, linhas = seg–dom), melhor dia da
 *    semana e taxa por rotina (top 10);
 *  - origens nativas FICAM FORA de tudo (decisão nº 3): são estado
 *    atual, sem dado por dia — a tela repete o aviso fixo.
 *
 * Régua dos números (a MESMA precedência do modo-período e do Quadro:
 * pendente · concluída · atrasada · aberta): item PENDENTE sai da conta
 * — é espera declarada, com volta marcada; contá-lo como "devida e não
 * feita" puniria justamente quem registrou a pendência. Por isso:
 *
 *   devidas (due) = concluídas + atrasadas + abertas   (sem pendentes)
 *   taxa          = concluídas ÷ devidas               (null se due = 0)
 */
class Panel
{
    /** Bordas vazias → últimos N dias (terminando hoje). */
    public const DEFAULT_DAYS = 90;

    /** Teto do recorte: acima disso o payload encolhe e avisa. */
    public const MAX_DAYS = 180;

    /** Rótulos curtos, seg (índice 0) a dom (índice 6). */
    public const DOW_SHORT = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

    /** Meses abreviados (índice 1–12), para o rótulo das semanas. */
    public const MONTH_SHORT = [
        1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];

    /** Máximo de rotinas na tabela (o resto vira contagem "e mais N"). */
    public const TOP_ROUTINES = 10;

    // =====================================================================
    // Payload da tela Painel
    // =====================================================================

    /**
     * Tudo que a tela precisa, já agregado:
     *
     *   [
     *     'date'     => 'Y-m-d' (hoje),
     *     'period'   => ['from','to','label','days','clamped'],
     *     'totals'   => ['due','done','pending','late','open','rate'],
     *     'heatmap'  => ['weeks' => [[7 × célula], …], 'max_done'],
     *     'weekdays' => [7 × ['label','due','done']],
     *     'best_day' => ['label','done'] | null,
     *     'routines' => ['rows' => [até 10 × linha], 'more' => n],
     *   ]
     *
     * `$from`/`$to` chegam crus do POST — a normalização é do
     * panelRange (mesma régua do periodRange + default + teto).
     */
    public static function payload(int $usersId, ?string $from = null, ?string $to = null): array
    {
        [$from, $to, $clamped] = self::panelRange($from, $to);

        // Modo-período do Occurrence: tudo do intervalo (aberta,
        // concluída, pendente), sem puladas nem excluídas, pendências
        // aplicadas. As nativas vêm junto (estado atual, fora do
        // recorte) e o assemble() as descarta — decisão nº 3.
        $base = Occurrence::payload($usersId, $from, $to);

        return self::assemble($base, $from, $to, $clamped);
    }

    /**
     * Normaliza o recorte do Painel a partir das bordas cruas do POST:
     *
     *  1. cada borda passa pela régua comum (periodBound via
     *     periodRange — inválida cai, par invertido desinverte);
     *  2. "até" ausente vira hoje (ou o próprio "de", se futuro — um
     *     recorte não pode terminar antes de começar);
     *  3. "de" ausente vira "até − (DEFAULT_DAYS − 1)" → os últimos 90;
     *  4. recorte maior que MAX_DAYS encolhe pelo INÍCIO (o fim é o que
     *     o usuário pediu ver) e liga a flag `clamped`.
     *
     * Devolve [from, to, clamped] — sempre datas concretas: o heatmap
     * precisa de um intervalo fechado para desenhar.
     */
    public static function panelRange(?string $from, ?string $to): array
    {
        [$from, $to] = Occurrence::periodRange($from, $to);

        $today = date('Y-m-d');
        if ($to === null) {
            $to = ($from !== null && $from > $today) ? $from : $today;
        }
        if ($from === null) {
            $from = self::addDays($to, -(self::DEFAULT_DAYS - 1));
        }

        $clamped = false;
        if (self::spanDays($from, $to) > self::MAX_DAYS) {
            $from    = self::addDays($to, -(self::MAX_DAYS - 1));
            $clamped = true;
        }

        return [$from, $to, $clamped];
    }

    /**
     * Agrega o payload-período do Occurrence nos números do Painel.
     * PURA (sem banco): é o método que o harness valida — precedência
     * dos estados, montagem do heatmap semana a semana, melhor dia e
     * agrupamento por rotina.
     */
    public static function assemble(array $base, string $from, string $to, bool $clamped): array
    {
        $today = (string) ($base['date'] ?? date('Y-m-d'));

        // ---- 1. Varredura única dos itens do período -----------------
        $due = 0;
        $done = 0;
        $pending = 0;
        $late = 0;
        $open = 0;

        $byDay = [];      // 'Y-m-d' => ['due' => n, 'done' => n]
        $byRoutine = [];  // routines_id => ['name','due','done','pending']

        foreach (($base['today'] ?? []) as $item) {
            if (!is_array($item) || !empty($item['is_native'])) {
                continue; // decisão nº 3: nativas fora de TUDO no Painel
            }

            $date = (string) ($item['date'] ?? '');
            $isPending = !empty($item['is_pending']);
            $isDone = !empty($item['is_done']);

            $routineId = (int) ($item['routines_id'] ?? 0);
            if (!empty($item['is_routine']) && $routineId > 0) {
                if (!isset($byRoutine[$routineId])) {
                    $name = (string) ($item['routine_name'] ?? '');
                    $byRoutine[$routineId] = [
                        'name'    => ($name !== '') ? $name : __('(rotina excluída)', 'taskplus'),
                        'due'     => 0,
                        'done'    => 0,
                        'pending' => 0,
                    ];
                }
            } else {
                $routineId = 0;
            }

            // Precedência do modo-período: pendente · concluída ·
            // atrasada · aberta. Pendente sai da régua (e das células).
            if ($isPending) {
                $pending++;
                if ($routineId > 0) {
                    $byRoutine[$routineId]['pending']++;
                }
                continue;
            }

            $due++;
            if ($routineId > 0) {
                $byRoutine[$routineId]['due']++;
            }
            if ($date !== '' && $date >= $from && $date <= $to) {
                if (!isset($byDay[$date])) {
                    $byDay[$date] = ['due' => 0, 'done' => 0];
                }
                $byDay[$date]['due']++;
            }

            if ($isDone) {
                $done++;
                if ($routineId > 0) {
                    $byRoutine[$routineId]['done']++;
                }
                if (isset($byDay[$date])) {
                    $byDay[$date]['done']++;
                }
            } elseif (!empty($item['is_late'])) {
                $late++;
            } else {
                $open++;
            }
        }

        // ---- 2. Heatmap calendário (colunas = semanas, linhas seg–dom)
        // A grade cobre da SEGUNDA da semana do início ao DOMINGO da
        // semana do fim; célula fora do recorte vai com in_range=false
        // (o JS a desenha apagada, só para a coluna fechar).
        $gridStart = self::mondayOf($from);
        $gridEnd   = self::addDays(self::mondayOf($to), 6);

        $weeks = [];
        $week = [];
        $maxDone = 0;
        $prevMonth = 0;
        $weekdays = [];
        for ($i = 0; $i < 7; $i++) {
            $weekdays[$i] = ['label' => self::DOW_SHORT[$i], 'due' => 0, 'done' => 0];
        }

        for ($d = $gridStart; $d <= $gridEnd; $d = self::addDays($d, 1)) {
            $inRange = ($d >= $from && $d <= $to);
            $cellDue = $inRange ? (int) ($byDay[$d]['due'] ?? 0) : 0;
            $cellDone = $inRange ? (int) ($byDay[$d]['done'] ?? 0) : 0;

            if ($inRange) {
                $dow = (int) date('N', (int) strtotime($d . ' 12:00:00')) - 1;
                $weekdays[$dow]['due'] += $cellDue;
                $weekdays[$dow]['done'] += $cellDone;
                if ($cellDone > $maxDone) {
                    $maxDone = $cellDone;
                }
            }

            $week[] = [
                'date'     => $d,
                'due'      => $cellDue,
                'done'     => $cellDone,
                // null = dia sem nada devida; 0..3 = taxa do dia em 4
                // faixas (nada · <metade · <tudo · tudo)
                'level'    => self::level($cellDue, $cellDone),
                'in_range' => $inRange,
                'is_today' => ($d === $today),
                'future'   => ($d > $today),
            ];

            if (count($week) === 7) {
                // Rótulo do mês na PRIMEIRA semana de cada mês (mês da
                // segunda-feira mudou em relação à semana anterior)
                $month = (int) substr($week[0]['date'], 5, 2);
                $weeks[] = [
                    'label' => ($month !== $prevMonth) ? (self::MONTH_SHORT[$month] ?? '') : '',
                    'days'  => $week,
                ];
                $prevMonth = $month;
                $week = [];
            }
        }

        // ---- 3. Melhor dia da semana (mais conclusões; empate = 1º) --
        $bestDay = null;
        foreach ($weekdays as $wd) {
            if ($wd['done'] > 0 && ($bestDay === null || $wd['done'] > $bestDay['done'])) {
                $bestDay = ['label' => $wd['label'], 'done' => $wd['done']];
            }
        }

        // ---- 4. Taxa por rotina (top N por volume, resto = "mais N") -
        $rows = [];
        foreach ($byRoutine as $id => $r) {
            $rows[] = [
                'id'      => (int) $id,
                'name'    => $r['name'],
                'due'     => $r['due'],
                'done'    => $r['done'],
                'pending' => $r['pending'],
                'rate'    => self::rate($r['due'], $r['done']),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            // Volume total primeiro (pendente incluso: é atividade da
            // rotina); nome desempata para a ordem ser estável
            $va = $a['due'] + $a['pending'];
            $vb = $b['due'] + $b['pending'];
            if ($va !== $vb) {
                return $vb <=> $va;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        $more = max(0, count($rows) - self::TOP_ROUTINES);
        $rows = array_slice($rows, 0, self::TOP_ROUTINES);

        return [
            'date'   => $today,
            'period' => [
                'from'    => $from,
                'to'      => $to,
                'label'   => self::brDate($from) . ' a ' . self::brDate($to),
                'days'    => self::spanDays($from, $to),
                'clamped' => $clamped,
            ],
            'totals' => [
                'due'     => $due,
                'done'    => $done,
                'pending' => $pending,
                'late'    => $late,
                'open'    => $open,
                'rate'    => self::rate($due, $done),
            ],
            'heatmap' => [
                'weeks'    => $weeks,
                'max_done' => $maxDone,
            ],
            'weekdays' => array_values($weekdays),
            'best_day' => $bestDay,
            'routines' => [
                'rows' => $rows,
                'more' => $more,
            ],
        ];
    }

    // =====================================================================
    // Réguas puras
    // =====================================================================

    /** Taxa 0–100 arredondada; null quando não há devidas (sem régua). */
    public static function rate(int $due, int $done): ?int
    {
        if ($due <= 0) {
            return null;
        }
        return (int) round($done * 100 / $due);
    }

    /**
     * Faixa da célula do heatmap: null = nada devida no dia; 0 = nada
     * concluída; 1 = menos da metade; 2 = metade ou mais (sem fechar);
     * 3 = tudo concluído.
     */
    public static function level(int $due, int $done): ?int
    {
        if ($due <= 0) {
            return null;
        }
        if ($done <= 0) {
            return 0;
        }
        if ($done >= $due) {
            return 3;
        }
        return ($done * 2 >= $due) ? 2 : 1;
    }

    /** Total de dias do intervalo FECHADO (from = to → 1). */
    public static function spanDays(string $from, string $to): int
    {
        $a = (int) strtotime($from . ' 12:00:00');
        $b = (int) strtotime($to . ' 12:00:00');
        return (int) round(($b - $a) / 86400) + 1;
    }

    /** Segunda-feira da semana que contém a data. */
    private static function mondayOf(string $date): string
    {
        $dow = (int) date('N', (int) strtotime($date . ' 12:00:00'));
        return self::addDays($date, -($dow - 1));
    }

    /**
     * Soma de dias em data 'Y-m-d', ancorada ao meio-dia — mesma defesa
     * do Week contra o pulo de dia em troca de horário de verão.
     */
    private static function addDays(string $date, int $days): string
    {
        return date('Y-m-d', (int) strtotime($date . ' 12:00:00 ' . ($days >= 0 ? '+' : '') . $days . ' day'));
    }

    /** '2026-08-10' → '10/08'. */
    private static function brDate(string $iso): string
    {
        return substr($iso, 8, 2) . '/' . substr($iso, 5, 2);
    }

    // =====================================================================
    // Ações (contrato do endpoint ajax/)
    // =====================================================================

    /**
     * 6b é leitura pura: a única ação é `list` (o endpoint anexa o
     * payload sozinho). O switch fica pelo padrão das outras telas —
     * o "ver como" do gestor (6b-2) entraria aqui.
     */
    public static function handle(string $action, array $input, int $usersId): array
    {
        switch ($action) {
            case 'list':
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }
}
