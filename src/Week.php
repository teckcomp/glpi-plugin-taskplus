<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — tela "Semana" (Etapa 6a: grade seg–dom, SÓ leitura).
 *
 * A tela não tem consulta própria: o payload REUSA o modo-período do
 * Occurrence::payload (5b-2 p2), já testado, com de–até = segunda–domingo
 * da semana pedida. Assim a Semana herda de graça: pendências aplicadas,
 * badges de auditoria (5b) e de criação (5c), exclusão de puladas e
 * soft-deleted, e os KPIs do conjunto (pendente · concluída · atrasada ·
 * aberta) — a MESMA régua das outras telas.
 *
 * Regras da 6a:
 *  - grade sempre de SEGUNDA a DOMINGO ("só dias úteis" das rotinas já é
 *    seg–sex por decisão nº 4; a grade mostra os 7 para o sábado/domingo
 *    de rotina semanal não sumir);
 *  - origens nativas (chamados/projetos) FICAM FORA da grade: são estado
 *    atual, sem dado por dia (decisão nº 3) — a tela mostra o aviso fixo;
 *  - leitura pura: nenhuma ação de escrita nasce aqui (concluir pela
 *    Semana seria um 6a-2, se aprovado).
 */
class Week
{
    /** Rótulos da grade, seg (índice 0) a dom (índice 6). */
    public const DOW_LABELS = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

    // =====================================================================
    // Payload da tela Semana
    // =====================================================================

    /**
     * Tudo que a tela precisa, já formatado:
     *
     *   [
     *     'date' => 'Y-m-d' (hoje),
     *     'kpis' => ['late','today','pending','done'] — do CONJUNTO da semana,
     *     'week' => ['start','end','label','prev','next','is_current'],
     *     'days' => [7 × ['date','date_label','dow_label','is_today','items']],
     *   ]
     *
     * `$anchor` é QUALQUER data dentro da semana desejada ('Y-m-d');
     * vazio/inválido cai na semana corrente — o JS navega mandando
     * `week.prev`/`week.next` de volta como âncora.
     */
    public static function payload(int $usersId, ?string $anchor = null): array
    {
        [$start, $end] = self::weekRange($anchor);

        // Modo-período do Occurrence: tudo da semana (aberta, concluída,
        // pendente), sem puladas nem excluídas, com pendências e nomes
        // de autoria já resolvidos. As nativas vêm junto (estado atual,
        // fora do recorte) e o assemble() as descarta da grade.
        $base = Occurrence::payload($usersId, $start, $end);

        return self::assemble($base, $start, $end);
    }

    /**
     * Monta o payload da Semana a partir do payload-período do
     * Occurrence. PURA (sem banco): é o método que o harness valida —
     * distribuição por dia, exclusão das nativas e das fora da semana,
     * flags e navegação.
     */
    public static function assemble(array $base, string $start, string $end): array
    {
        $today = (string) ($base['date'] ?? date('Y-m-d'));

        $days  = [];
        $index = [];
        for ($i = 0; $i < 7; $i++) {
            $date = self::addDays($start, $i);
            $index[$date] = $i;
            $days[] = [
                'date'       => $date,
                'date_label' => substr($date, 8, 2) . '/' . substr($date, 5, 2),
                'dow_label'  => self::DOW_LABELS[$i],
                'is_today'   => ($date === $today),
                'items'      => [],
            ];
        }

        // No modo-período, TUDO chega em `today` (as "atrasadas" viram
        // estado is_late do item, não lista à parte). Nativa fica fora
        // da grade (decisão nº 3); item com data fora da semana (não
        // deveria existir, mas o payload pode evoluir) é descartado em
        // vez de estourar índice.
        foreach (($base['today'] ?? []) as $item) {
            if (!is_array($item) || !empty($item['is_native'])) {
                continue;
            }
            $date = (string) ($item['date'] ?? '');
            if (!isset($index[$date])) {
                continue;
            }
            $days[$index[$date]]['items'][] = $item;
        }

        $kpis = (array) ($base['kpis'] ?? []);

        return [
            'date' => $today,
            'kpis' => [
                'late'    => (int) ($kpis['late'] ?? 0),
                'today'   => (int) ($kpis['today'] ?? 0),
                'pending' => (int) ($kpis['pending'] ?? 0),
                'done'    => (int) ($kpis['done'] ?? 0),
            ],
            'week' => [
                'start'      => $start,
                'end'        => $end,
                'label'      => self::brDate($start) . ' a ' . self::brDate($end),
                'prev'       => self::addDays($start, -7),
                'next'       => self::addDays($start, 7),
                'is_current' => ($today >= $start && $today <= $end),
            ],
            'days' => $days,
        ];
    }

    // =====================================================================
    // Semana (segunda–domingo) a partir de uma âncora
    // =====================================================================

    /**
     * ['Y-m-d' segunda, 'Y-m-d' domingo] da semana que contém `$anchor`.
     * Âncora vazia/inválida (mesma régua do período — periodBound) cai
     * na semana corrente.
     */
    public static function weekRange(?string $anchor): array
    {
        $anchor = Occurrence::periodBound($anchor) ?? date('Y-m-d');

        // date('N') = 1 (segunda) … 7 (domingo)
        $dow   = (int) date('N', (int) strtotime($anchor . ' 12:00:00'));
        $start = self::addDays($anchor, -($dow - 1));

        return [$start, self::addDays($start, 6)];
    }

    /**
     * Soma de dias em data 'Y-m-d', sem depender de fuso: âncora ao
     * meio-dia evita o clássico bug de horário de verão pulando um dia.
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
    // Ações (contrato dos endpoints ajax/)
    // =====================================================================

    /**
     * 6a é leitura pura: a única ação é `list` (o endpoint anexa o
     * payload sozinho). O switch existe pelo padrão das outras telas —
     * as ações de escrita de um eventual 6a-2 entrariam aqui.
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
