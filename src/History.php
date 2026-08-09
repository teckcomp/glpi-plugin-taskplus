<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — tela "Histórico" (Etapa 6c-1: trilha auditável, leitura).
 *
 * Decisão nº 9 (travada na abertura da Etapa 6): o Histórico exibe TUDO
 * que passou pelas tarefas próprias do usuário — concluídas, pendentes,
 * atrasadas, abertas, PULADAS e EXCLUÍDAS (soft) — com badge de estado
 * e autor. Desde a 6c-2, a tela tem UMA ação: "restaurar" a excluída
 * (decisão nº 9) — pulada NÃO se restaura; o resto segue leitura.
 *
 * Decisão nº 19 (abertura do 6c, sessão 18):
 *  - período herda o padrão de–até (mesma régua comum periodBound/
 *    periodRange), com default = últimos 30 DIAS (o Histórico é lista
 *    item a item — 90 dias viram parede) e o MESMO teto de 180 com
 *    encolhimento pelo início + flag `clamped`;
 *  - lista agrupada POR DIA, ancorada no `date` da ocorrência (a régua
 *    de todas as outras telas) — o carimbo do evento (conclusão, pulo)
 *    vai DENTRO do item, então tarefa de segunda concluída na quarta
 *    aparece na segunda, com "concluída em <quarta>";
 *  - dias mais recentes PRIMEIRO (leitura de extrato) e só dias com
 *    itens ganham cabeçalho.
 *
 * A tela NÃO reusa o modo-período do Occurrence::payload de propósito:
 * aquele caminho descarta excluídas e puladas porque é a régua do
 * trabalho vivo (Hoje/Quadro/Semana/Painel). Aqui a consulta é própria
 * — mesma tabela, mesmo SELECT explícito e qualificado, SEM filtro de
 * estado — e a agregação fica em assemble(), método PURO que o harness
 * valida linha a linha.
 *
 * As origens nativas (chamados/projetos) ficam FORA, como em todo
 * recorte por dia (decisão nº 3): são estado atual, sem trilha por dia
 * — a tela repete o aviso fixo.
 */
class History
{
    /** Bordas vazias → últimos N dias (terminando hoje). */
    public const DEFAULT_DAYS = 30;

    /** Teto do recorte: acima disso o payload encolhe e avisa. */
    public const MAX_DAYS = 180;

    /** Rótulos curtos de dia da semana, seg (índice 0) a dom (índice 6). */
    public const DOW_SHORT = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

    /**
     * Estados possíveis de um item do Histórico, na ORDEM DE PRECEDÊNCIA
     * (o primeiro que casar define o badge): excluída esconde tudo — a
     * linha saiu do fluxo; pulada idem (o dia acabou ali); concluída
     * vence pendência/atraso; pendente vence atraso (espera combinada).
     */
    public const STATES = ['deleted', 'skipped', 'done', 'pending', 'late', 'open'];

    // =====================================================================
    // Payload da tela Histórico
    // =====================================================================

    /**
     * Tudo que a tela precisa:
     *
     *   [
     *     'date'   => 'Y-m-d' (hoje),
     *     'period' => ['from','to','label','days','clamped'],
     *     'totals' => ['all','done','pending','late','open','skipped','deleted'],
     *     'days'   => [['date','label','is_today','items' => […]], …]  (desc),
     *   ]
     *
     * `$from`/`$to` chegam crus do POST — a normalização é do
     * historyRange (mesma régua do periodRange + default de 30 + teto).
     */
    public static function payload(int $usersId, ?string $from = null, ?string $to = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        [$from, $to, $clamped] = self::historyRange($from, $to);

        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');

        // Consulta própria do Histórico: TODAS as ocorrências do usuário
        // com `date` no intervalo — sem filtro de is_deleted/is_skipped/
        // is_done (é exatamente o que a tela existe para mostrar).
        // Duas restrições sobre a MESMA coluna (`date`) não podem
        // dividir a chave do array — entram aninhadas, que o iterator
        // ANDa (T21).
        $where = [
            Occurrence::TABLE . '.users_id' => $usersId,
        ];
        $where[] = [Occurrence::TABLE . '.date' => ['>=', $from]];
        $where[] = [Occurrence::TABLE . '.date' => ['<=', $to]];

        $items = [];
        foreach ($DB->request(self::baseQuery() + ['WHERE' => $where]) as $row) {
            $items[] = self::format($row, $today, $nowTime);
        }

        // Pendência ATIVA marca o estado "pendente" (com autor). As
        // linhas históricas de pendência já expiradas seguem no banco,
        // mas o badge do item é do estado ATUAL — trilha fina de
        // pendências passadas é evolução futura, se o uso pedir.
        $pendings = [];
        try {
            $pendings = Pending::activeMap($usersId, $today);
        } catch (\Throwable $e) {
            $pendings = [];
        }
        $items = self::applyPendings($items, $pendings, $usersId);

        // Nomes dos autores (conclusão / pendência / criação por outro
        // usuário — o gestor pela Equipe), resolvidos em LOTE.
        $items = self::fillActorLabels($items);

        return self::assemble($items, $from, $to, $clamped, $today);
    }

    /**
     * Normaliza o recorte do Histórico a partir das bordas cruas do POST
     * — mesmíssima régua do Panel::panelRange, só com o default de 30:
     *
     *  1. cada borda passa pela régua comum (periodBound via
     *     periodRange — inválida cai, par invertido desinverte);
     *  2. "até" ausente vira hoje (ou o próprio "de", se futuro);
     *  3. "de" ausente vira "até − (DEFAULT_DAYS − 1)" → os últimos 30;
     *  4. recorte maior que MAX_DAYS encolhe pelo INÍCIO e liga `clamped`.
     *
     * Devolve [from, to, clamped] — sempre datas concretas.
     */
    public static function historyRange(?string $from, ?string $to): array
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
     * Agrega os itens já formatados em totais + dias. PURA (sem banco):
     * é o método que o harness valida — precedência dos estados nos
     * totais, agrupamento por dia ancorado no `date`, ordem dos dias
     * (desc) e dos itens dentro do dia, rótulos.
     */
    public static function assemble(array $items, string $from, string $to, bool $clamped, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');

        $totals = [
            'all'     => 0,
            'done'    => 0,
            'pending' => 0,
            'late'    => 0,
            'open'    => 0,
            'skipped' => 0,
            'deleted' => 0,
        ];

        $byDay = []; // 'Y-m-d' => [itens]
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $state = (string) ($item['state'] ?? 'open');
            if (!in_array($state, self::STATES, true)) {
                $state = 'open';
            }
            $totals['all']++;
            $totals[$state]++;

            $date = (string) ($item['date'] ?? '');
            if ($date === '') {
                continue; // sem dia não há onde ancorar
            }
            $byDay[$date][] = $item;
        }

        // Dias mais recentes primeiro (extrato); dentro do dia, a MESMA
        // leitura das outras telas: horário-limite crescente (sem limite
        // por último), empate por id — ordem estável e testável.
        krsort($byDay, SORT_STRING);

        $days = [];
        foreach ($byDay as $date => $dayItems) {
            usort($dayItems, [self::class, 'compareInDay']);
            $days[] = [
                'date'     => (string) $date,
                'label'    => self::dayLabel((string) $date),
                'is_today' => ($date === $today),
                'items'    => $dayItems,
            ];
        }

        return [
            'date'   => $today,
            'period' => [
                'from'    => $from,
                'to'      => $to,
                'label'   => self::brDate($from) . ' a ' . self::brDate($to),
                'days'    => self::spanDays($from, $to),
                'clamped' => $clamped,
            ],
            'totals' => $totals,
            'days'   => $days,
        ];
    }

    // =====================================================================
    // Consulta e formatação (padrão do Occurrence, com as colunas da trilha)
    // =====================================================================

    /**
     * SELECT explícito e 100% qualificado (id/name/date_mod/is_deleted
     * existem nas DUAS tabelas do JOIN — regra de sempre contra o 1052).
     * Além das colunas da tela Hoje, vêm as da trilha: skip_reason/
     * skip_date/users_id_skip, is_deleted e date_creation.
     */
    private static function baseQuery(): array
    {
        return [
            'SELECT' => [
                Occurrence::TABLE . '.id',
                Occurrence::TABLE . '.plugin_taskplus_routines_id',
                Occurrence::TABLE . '.users_id',
                Occurrence::TABLE . '.users_id_done',
                Occurrence::TABLE . '.users_id_creator',
                Occurrence::TABLE . '.name',
                Occurrence::TABLE . '.description',
                Occurrence::TABLE . '.category',
                Occurrence::TABLE . '.date',
                Occurrence::TABLE . '.time_limit',
                Occurrence::TABLE . '.is_done',
                Occurrence::TABLE . '.done_date',
                Occurrence::TABLE . '.is_skipped',
                Occurrence::TABLE . '.skip_reason',
                Occurrence::TABLE . '.skip_date',
                Occurrence::TABLE . '.is_edited',
                Occurrence::TABLE . '.is_deleted',
                Occurrence::TABLE . '.date_creation',
                Routine::TABLE . '.name AS routine_name',
            ],
            'FROM'      => Occurrence::TABLE,
            'LEFT JOIN' => [
                Routine::TABLE => [
                    'ON' => [
                        Routine::TABLE   => 'id',
                        Occurrence::TABLE => 'plugin_taskplus_routines_id',
                    ],
                ],
            ],
        ];
    }

    /**
     * Linha do banco → item do Histórico (formatos prontos para o JS).
     * O `state` sai daqui SEM considerar pendência (applyPendings ajusta
     * depois — pendente só existe para item vivo, aberto/atrasado).
     */
    public static function format(array $row, string $today, string $nowTime): array
    {
        $isDeleted = ((int) ($row['is_deleted'] ?? 0)) === 1;
        $isSkipped = ((int) ($row['is_skipped'] ?? 0)) === 1;
        $isDone    = ((int) ($row['is_done'] ?? 0)) === 1;
        $date      = (string) ($row['date'] ?? $today);
        $limit     = $row['time_limit'] ?? null;

        // Mesma régua de "atrasada" do Occurrence::format — só vale para
        // item vivo (a precedência abaixo já corta o resto).
        $isLate = !$isDone && (
            $date < $today
            || ($date === $today && $limit !== null && $limit !== '' && $limit < $nowTime)
        );

        if ($isDeleted) {
            $state = 'deleted';
        } elseif ($isSkipped) {
            $state = 'skipped';
        } elseif ($isDone) {
            $state = 'done';
        } elseif ($isLate) {
            $state = 'late';
        } else {
            $state = 'open';
        }

        // Carimbo da conclusão DENTRO do item (decisão nº 19): no mesmo
        // dia da ocorrência = "às HH:MM"; em OUTRO dia = "em DD/MM HH:MM"
        // — é o que deixa a âncora pelo `date` honesta.
        $doneStamp = (string) ($row['done_date'] ?? '');
        $doneLabel = '';
        if ($isDone && strlen($doneStamp) >= 16) {
            $doneDay  = substr($doneStamp, 0, 10);
            $doneTime = substr($doneStamp, 11, 5);
            $doneLabel = ($doneDay === $date)
                ? 'às ' . $doneTime
                : 'em ' . self::brDate($doneDay) . ' ' . $doneTime;
        }

        // Carimbo do pulo (skip_date fica gravado mesmo se o pulo foi
        // desfeito — mas o badge só aparece com is_skipped = 1).
        $skipStamp = (string) ($row['skip_date'] ?? '');
        $skipLabel = '';
        if ($isSkipped && strlen($skipStamp) >= 16) {
            $skipLabel = 'em ' . self::brDate(substr($skipStamp, 0, 10)) . ' ' . substr($skipStamp, 11, 5);
        }

        $isRoutine = ($row['plugin_taskplus_routines_id'] ?? null) !== null;

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'is_routine'   => $isRoutine,
            'routine_name' => (string) ($row['routine_name'] ?? ''),
            'name'         => (string) ($row['name'] ?? ''),
            'description'  => (string) ($row['description'] ?? ''),
            'category'     => (string) ($row['category'] ?? ''),
            'date'         => $date,
            'date_label'   => self::brDate($date),
            'time_limit'   => ($limit !== null && $limit !== '') ? substr((string) $limit, 0, 5) : null,
            'state'        => $state,
            'is_edited'    => ((int) ($row['is_edited'] ?? 0)) === 1,
            // Conclusão (com autoria da 5b-1)
            'done_label'    => $doneLabel,
            'done_by_other' => $isDone
                && ((int) ($row['users_id_done'] ?? 0)) > 0
                && ((int) ($row['users_id_done'] ?? 0)) !== ((int) ($row['users_id'] ?? 0)),
            'done_by_id'    => (int) ($row['users_id_done'] ?? 0),
            'done_by_label' => '',
            // Pulo (4b — sempre ação do próprio dono, sem badge de autor)
            'skip_reason'  => $isSkipped ? (string) ($row['skip_reason'] ?? '') : '',
            'skip_label'   => $skipLabel,
            // Pendência (preenchida por applyPendings)
            'is_pending'       => false,
            'pending_reason'   => '',
            'pending_label'    => '',
            'pending_by_other' => false,
            'pending_by_id'    => 0,
            'pending_by_label' => '',
            // Autoria da criação (5c — badge "criada pelo gestor")
            'created_by_other' => ((int) ($row['users_id_creator'] ?? 0)) > 0
                && ((int) ($row['users_id_creator'] ?? 0)) !== ((int) ($row['users_id'] ?? 0)),
            'created_by_id'    => (int) ($row['users_id_creator'] ?? 0),
            'created_by_label' => '',
        ];
    }

    /**
     * Marca o estado "pendente" nos itens vivos com pendência ativa.
     * Excluída/pulada/concluída NÃO viram pendentes — a precedência do
     * badge é a dos STATES; a pendência ativa nesses casos é resquício
     * (o item saiu do fluxo por outro caminho) e fica de fora.
     */
    public static function applyPendings(array $items, array $pendings, int $ownerId): array
    {
        foreach ($items as &$item) {
            $state = (string) ($item['state'] ?? 'open');
            if ($state !== 'open' && $state !== 'late') {
                continue;
            }
            $key = Pending::TYPE_OCCURRENCE . ':' . (int) ($item['id'] ?? 0);
            if (!isset($pendings[$key])) {
                continue;
            }
            $item['state']          = 'pending';
            $item['is_pending']     = true;
            $item['pending_reason'] = (string) ($pendings[$key]['reason'] ?? '');
            $item['pending_label']  = (string) ($pendings[$key]['label'] ?? '');
            $creator = (int) ($pendings[$key]['creator'] ?? 0);
            $item['pending_by_id']    = $creator;
            $item['pending_by_other'] = $creator > 0 && $ownerId > 0 && $creator !== $ownerId;
        }
        unset($item);

        return $items;
    }

    /**
     * Resolve em LOTE os nomes dos autores (conclusão, pendência e
     * criação por OUTRO usuário) — mesmo desenho do Occurrence, que
     * mantém o seu privado; repetir aqui custa menos que abrir a
     * visibilidade de um método interno de outra classe.
     */
    private static function fillActorLabels(array $rows): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($rows as $item) {
            if (!empty($item['done_by_other']) && (int) ($item['done_by_id'] ?? 0) > 0) {
                $ids[(int) $item['done_by_id']] = true;
            }
            if (!empty($item['pending_by_other']) && (int) ($item['pending_by_id'] ?? 0) > 0) {
                $ids[(int) $item['pending_by_id']] = true;
            }
            if (!empty($item['created_by_other']) && (int) ($item['created_by_id'] ?? 0) > 0) {
                $ids[(int) $item['created_by_id']] = true;
            }
        }
        if ($ids === []) {
            return $rows;
        }

        $labels = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['glpi_users.id' => array_keys($ids) ?: [0]],
        ]) as $row) {
            $label = trim(
                (string) ($row['firstname'] ?? '') . ' ' . (string) ($row['realname'] ?? '')
            );
            if ($label === '') {
                $label = (string) ($row['name'] ?? '');
            }
            $labels[(int) ($row['id'] ?? 0)] = $label;
        }

        foreach ($rows as &$item) {
            if (!empty($item['done_by_other'])) {
                $item['done_by_label'] = $labels[(int) ($item['done_by_id'] ?? 0)] ?? '';
            }
            if (!empty($item['pending_by_other'])) {
                $item['pending_by_label'] = $labels[(int) ($item['pending_by_id'] ?? 0)] ?? '';
            }
            if (!empty($item['created_by_other'])) {
                $item['created_by_label'] = $labels[(int) ($item['created_by_id'] ?? 0)] ?? '';
            }
        }
        unset($item);

        return $rows;
    }

    // =====================================================================
    // Réguas puras
    // =====================================================================

    /**
     * Ordem DENTRO do dia: horário-limite crescente (sem limite por
     * último), empate por id — a mesma leitura do resto do plugin.
     */
    public static function compareInDay(array $a, array $b): int
    {
        $la = $a['time_limit'] ?? null;
        $lb = $b['time_limit'] ?? null;
        if ($la !== $lb) {
            if ($la === null) {
                return 1;
            }
            if ($lb === null) {
                return -1;
            }
            return strcmp((string) $la, (string) $lb);
        }
        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    }

    /** '2026-08-07' → 'sex · 07/08'. */
    public static function dayLabel(string $date): string
    {
        $dow = (int) date('N', (int) strtotime($date . ' 12:00:00')) - 1;
        return (self::DOW_SHORT[$dow] ?? '') . ' · ' . self::brDate($date);
    }

    /** Total de dias do intervalo FECHADO (from = to → 1). */
    public static function spanDays(string $from, string $to): int
    {
        $a = (int) strtotime($from . ' 12:00:00');
        $b = (int) strtotime($to . ' 12:00:00');
        return (int) round(($b - $a) / 86400) + 1;
    }

    /**
     * Soma de dias em data 'Y-m-d', ancorada ao meio-dia — mesma defesa
     * do Week/Panel contra o pulo de dia em troca de horário de verão.
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
     * Ações da tela: `list` (o endpoint anexa o payload sozinho) e,
     * desde a 6c-2, `restore` — a resposta de AMBAS volta com o payload
     * completo do período, então o JS só re-renderiza.
     */
    public static function handle(string $action, array $input, int $usersId): array
    {
        switch ($action) {
            case 'list':
                return ['success' => true, 'message' => ''];
            case 'restore':
                return self::restore($input, $usersId);
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    /**
     * 6c-2 — restaura uma tarefa EXCLUÍDA (decisão nº 9): SÓ zera
     * `is_deleted` (conclusão, pendência e datas ficam intactas — a
     * tarefa volta ao estado que tinha) e atualiza `date_mod`. Ela
     * reaparece nas telas vivas conforme a própria data.
     *
     * Regras revalidadas a CADA POST (T18), nunca herdadas da tela:
     *  - posse: a linha tem que ser do PRÓPRIO usuário — o restaurar
     *    pelo gestor fica para quando existir trilha na visão da
     *    equipe (backlog, junto do 6b-2);
     *  - só EXCLUÍDA (`is_deleted` = 1) se restaura — pulada não;
     *  - só AVULSA: o delete atual já recusa ocorrência de rotina, mas
     *    a dupla checagem fica aqui de defesa — se um dia surgir rotina
     *    excluída por outro caminho, recusar é mais honesto que
     *    restaurar um órfão.
     */
    private static function restore(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        // Busca da linha do PRÓPRIO usuário SEM filtro de is_deleted —
        // o ownRow do Occurrence filtra is_deleted = 0 de propósito
        // (régua do trabalho vivo); aqui a excluída é exatamente o alvo.
        $row = null;
        foreach ($DB->request([
            'FROM'  => Occurrence::TABLE,
            'WHERE' => [
                Occurrence::TABLE . '.id'       => $id,
                Occurrence::TABLE . '.users_id' => $usersId,
            ],
        ]) as $r) {
            $row = $r;
            break;
        }
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        if (((int) ($row['is_deleted'] ?? 0)) !== 1) {
            return ['success' => false, 'message' => __('A tarefa não está excluída', 'taskplus')];
        }
        if (($row['plugin_taskplus_routines_id'] ?? null) !== null) {
            return ['success' => false, 'message' => __('Ocorrência de rotina não pode ser restaurada', 'taskplus')];
        }

        $DB->update(
            Occurrence::TABLE,
            ['is_deleted' => 0, 'date_mod' => date('Y-m-d H:i:s')],
            [Occurrence::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Tarefa restaurada', 'taskplus')];
    }
}
