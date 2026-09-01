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
 * Etapa 9b-1: a tela ganhou ALVO. O gestor escolhe "Ver histórico de:"
 * e lê a trilha de um técnico do escopo — mesma régua da Equipe e do
 * Painel (Team::scope), revalidada a CADA payload (T18). Só técnicos,
 * sem modo equipe (decisão nº 36).
 *
 * Etapa 9b-2: no modo técnico a tela deixa de ser leitura pura — o
 * gestor restaura a tarefa EXCLUÍDA do técnico, fechando a decisão
 * nº 9. A posse deixa de ser "só o dono" e passa por dono OU técnico
 * do escopo, revalidado dentro do próprio restore().
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
     *
     * `$viewKind`/`$viewId` (9b-1) são o alvo pedido pelo GESTOR:
     * 'self' = histórico próprio; 'user' + users_id = trilha de um
     * técnico do escopo. NÃO existe modo equipe aqui (decisão nº 36):
     * o Histórico é lista item a item — a união de N técnicos vira
     * parede e a restauração é sempre individual. Vêm crus do POST e
     * são validados aqui — o front nunca é fonte de escopo.
     *
     * ATENÇÃO safeData(): chave nova aqui precisa entrar no history.js.
     */
    public static function payload(
        int $usersId,
        ?string $from = null,
        ?string $to = null,
        string $viewKind = 'self',
        int $viewId = 0
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        [$from, $to, $clamped] = self::historyRange($from, $to);

        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');

        // Escopo consultado AGORA (T18): quem perdeu a gestão do setor
        // entre o carregamento da tela e este POST perde o acesso já
        // nesta resposta. Régua idêntica à do Painel e à da Equipe.
        $scope   = Access::canTeam() ? Team::scope($usersId) : ['groups' => [], 'members' => []];
        $options = self::optionsFor($scope, $usersId);
        $view    = self::resolveView($usersId, $viewKind, $viewId, $options);

        // Dono da trilha lida: o próprio ou o técnico já validado.
        $ownerId = (int) $view['id'];

        // Consulta própria do Histórico: TODAS as ocorrências do usuário
        // com `date` no intervalo — sem filtro de is_deleted/is_skipped/
        // is_done (é exatamente o que a tela existe para mostrar).
        // Duas restrições sobre a MESMA coluna (`date`) não podem
        // dividir a chave do array — entram aninhadas, que o iterator
        // ANDa (T21).
        $where = [
            Occurrence::TABLE . '.users_id' => $ownerId,
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
            $pendings = Pending::activeMap($ownerId, $today);
        } catch (\Throwable $e) {
            $pendings = [];
        }
        $items = self::applyPendings($items, $pendings, $ownerId);

        // Nomes dos autores (conclusão / pendência / criação por outro
        // usuário — o gestor pela Equipe), resolvidos em LOTE.
        $items = self::fillActorLabels($items);

        // 11a: trilha do diálogo — contagem de comentários e de anexos
        // por item, em UMA consulta para o recorte inteiro. É o que
        // decide se a linha ganha o botão "Diálogo".
        $counts = [];
        try {
            $counts = self::dialogCountsFor(array_column($items, 'id'));
        } catch (\Throwable $e) {
            $counts = [];
        }
        $items = self::applyDialog($items, $counts);

        $out             = self::assemble($items, $from, $to, $clamped, $today);
        $view['options'] = $options;
        $out['view']     = $view;

        return $out;
    }

    // =====================================================================
    // Escopo do "Ver histórico de:" (9b-1)
    // =====================================================================

    /**
     * Opções do seletor a partir do escopo já consultado. PURA.
     *
     * Só TÉCNICOS (decisão nº 36 — sem opção de equipe): a tela é lista
     * item a item e a única ação possível é individual. Sai o próprio
     * gestor, que já é a opção fixa "Meu histórico" do front — repetido
     * viraria duas entradas para a mesma pessoa.
     *
     * Lista vazia = a tela não mostra seletor nenhum (técnico comum, ou
     * gestor cujo setor ficou sem membros ativos).
     *
     *   [ ['kind' => 'user', 'id' => int, 'label' => string,
     *      'groups' => 'Setor A · B'], … ]
     */
    public static function optionsFor(array $scope, int $usersId): array
    {
        $members = (array) ($scope['members'] ?? []);
        unset($members[$usersId]);

        if ($members === []) {
            return [];
        }

        $techs = [];
        foreach ($members as $id => $info) {
            $techs[] = [
                'kind'   => 'user',
                'id'     => (int) $id,
                'label'  => (string) ($info['label'] ?? ''),
                'groups' => implode(' · ', (array) ($info['groups'] ?? [])),
            ];
        }
        // Alfabética pelo nome exibido; homônimo desempata por id —
        // MESMA ordem da Equipe e do Painel, para o gestor achar a
        // pessoa no mesmo lugar em todas as telas.
        usort($techs, static function (array $a, array $b): int {
            return [mb_strtolower($a['label']), $a['id']]
                <=> [mb_strtolower($b['label']), $b['id']];
        });

        return $techs;
    }

    /** Atalho com consulta (usado por harness e por chamadas avulsas). */
    public static function viewOptions(int $usersId): array
    {
        // Mesmo gate da tela Equipe e do Painel: admin (`config` UPDATE)
        // ou gestor com is_manager em algum setor.
        if (!Access::canTeam()) {
            return [];
        }

        return self::optionsFor(Team::scope($usersId), $usersId);
    }

    /**
     * Resolve o alvo pedido contra as opções permitidas. PURA (sem
     * banco): o harness cobre todos os caminhos.
     *
     *  - 'self', id 0/negativo ou o próprio id → histórico próprio;
     *  - 'user' presente nas opções            → trilha do técnico;
     *  - qualquer outro                        → histórico próprio com
     *    `denied` (id inexistente, técnico de outro setor, setor que
     *    saiu da gestão entre o carregamento e o POST). Cair no próprio
     *    histórico em vez de devolver tela vazia mantém a leitura
     *    utilizável enquanto o front avisa.
     *
     * `can_restore` é a permissão de AÇÃO da tela. Desde o 9b-2 ela
     * acompanha o alvo: quem chegou até aqui no modo 'user' passou pelo
     * escopo do gestor recém-consultado, então pode restaurar a excluída
     * do técnico (decisão nº 9, enfim fechada). O flag é conveniência de
     * INTERFACE — quem decide de verdade é o restore(), que refaz a
     * validação inteira a cada POST (T18).
     *
     * `label` fica vazio no modo próprio — quem escreve "Meu histórico"
     * é o front (texto de interface não vem do domínio).
     */
    public static function resolveView(int $usersId, string $viewKind, int $viewId, array $options): array
    {
        $self = [
            'kind'        => 'self',
            'id'          => $usersId,
            'label'       => '',
            'is_self'     => true,
            'denied'      => false,
            'can_restore' => true,
        ];

        if ($viewKind === 'self' || $viewId <= 0 || $viewId === $usersId) {
            return $self;
        }
        if ($viewKind !== 'user') {
            return $self; // valor estranho no POST: próprio, sem alarme
        }

        foreach ($options as $opt) {
            if ((string) ($opt['kind'] ?? '') !== 'user' || (int) ($opt['id'] ?? 0) !== $viewId) {
                continue;
            }
            return array_merge($self, [
                'kind'        => 'user',
                'id'          => $viewId,
                'label'       => (string) ($opt['label'] ?? ''),
                'is_self'     => false,
                'can_restore' => true,
            ]);
        }

        return array_merge($self, ['denied' => true]);
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

    // =====================================================================
    // 11a — trilha do diálogo (leitura no Histórico)
    // =====================================================================

    /**
     * 11a — quantos comentários VIVOS e quantos ANEXOS cada ocorrência
     * tem. UMA consulta para o recorte inteiro: a tela já lista o
     * período todo de um alvo só, e uma consulta por item devolveria o
     * Histórico de 180 dias da produção em N+1.
     *
     * Conta em PHP de propósito: COUNT com GROUPBY no iterator do GLPI
     * 11 DESCARTA os campos do SELECT — é a armadilha de sempre.
     *
     * Devolve SEMPRE uma chave por ocorrência pedida (zeros quando não
     * há diálogo), para o chamador nunca precisar de `??`.
     */
    public static function dialogCountsFor(array $occIds): array
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
            $out[$id] = ['comments' => 0, 'files' => 0];
        }
        if ($out === []) {
            return $out;
        }

        foreach ($DB->request([
            'SELECT' => [
                Comment::TABLE . '.plugin_taskplus_occurrences_id',
                Comment::TABLE . '.documents_id',
            ],
            'FROM'  => Comment::TABLE,
            'WHERE' => [
                Comment::TABLE . '.plugin_taskplus_occurrences_id' => array_keys($ids),
                Comment::TABLE . '.is_deleted'                     => 0,
            ],
        ]) as $row) {
            $occId = (int) ($row['plugin_taskplus_occurrences_id'] ?? 0);
            if (!isset($out[$occId])) {
                continue;
            }
            $out[$occId]['comments']++;
            if (((int) ($row['documents_id'] ?? 0)) > 0) {
                $out[$occId]['files']++;
            }
        }

        return $out;
    }

    /**
     * 11a — carimba `dialog_count` e `file_count` em cada item. PURA
     * (sem banco): é o que o harness valida.
     *
     * Item EXCLUÍDO sai sempre com zero, e isso é decisão, não
     * descuido: o Comment::listFor só enxerga ocorrência viva
     * (Comment::occRow filtra `is_deleted = 0`), então oferecer botão
     * de diálogo numa tarefa excluída abriria um modal vazio. O
     * "Restaurar" da própria tela devolve a tarefa — e o diálogo volta
     * junto, porque o comentário nunca foi apagado.
     */
    public static function applyDialog(array $items, array $counts): array
    {
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $id  = (int) ($item['id'] ?? 0);
            $row = $counts[$id] ?? ['comments' => 0, 'files' => 0];

            $isDeleted = ((string) ($item['state'] ?? '')) === 'deleted';

            $item['dialog_count'] = $isDeleted ? 0 : (int) ($row['comments'] ?? 0);
            $item['file_count']   = $isDeleted ? 0 : (int) ($row['files'] ?? 0);
        }
        unset($item);

        return $items;
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
            case 'dialog':
                return self::dialogFor($input, $usersId);
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    /**
     * 11a — LEITURA do diálogo de uma ocorrência a partir do Histórico.
     *
     * A tela existe para auditar o que já aconteceu, então esta ação é
     * estritamente de leitura: devolve a thread e nada mais. Quem
     * escreve continua sendo a tela do dia (Hoje/Quadro/Equipe), onde
     * a régua da decisão nº 28 vale inteira.
     *
     * Escopo revalidado AQUI, a cada POST (T18) — nunca herdado do
     * `view` que o front mandou:
     *  - dono = o próprio usuário: leitura pela régua de participação
     *    do Comment::listFor (dono ou criador);
     *  - dono = técnico do escopo do gestor: `$managerRead`, com o
     *    escopo resolvido AGORA pelo mesmo resolveView do restore.
     * Falha de escopo devolve a MESMA mensagem neutra de "não
     * encontrada": a resposta não conta ao gestor que a tarefa existe.
     *
     * NÃO marca a thread como lida, ao contrário da Equipe (9a-2).
     * Auditar não é participar: se ler no Histórico zerasse o não lido,
     * o gestor perderia na tela Equipe o aviso de que alguém falou com
     * ele — e o sino existe para o trabalho do dia, não para a trilha.
     */
    private static function dialogFor(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $notFound = [
            'success'  => false,
            'message'  => __('Tarefa não encontrada', 'taskplus'),
            'comments' => [],
        ];

        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return $notFound;
        }

        // SEM filtro de dono: a posse é decidida abaixo, porque o dono
        // legítimo pode ser o próprio OU um técnico do escopo.
        $row = null;
        foreach ($DB->request([
            'FROM'  => Occurrence::TABLE,
            'WHERE' => [Occurrence::TABLE . '.id' => $id],
        ]) as $r) {
            $row = $r;
            break;
        }
        if ($row === null) {
            return $notFound;
        }

        $ownerId = (int) ($row['users_id'] ?? 0);
        $isSelf  = ($ownerId === $usersId);

        if (!$isSelf) {
            $scope = Access::canTeam() ? Team::scope($usersId) : ['groups' => [], 'members' => []];
            $view  = self::resolveView($usersId, 'user', $ownerId, self::optionsFor($scope, $usersId));
            if ($view['is_self'] || (int) $view['id'] !== $ownerId) {
                return $notFound;
            }
        }

        return [
            'success'  => true,
            'message'  => '',
            'id'       => $id,
            // Ocorrência excluída não tem diálogo legível (o occRow do
            // Comment só enxerga viva) — devolve lista vazia, coerente
            // com o applyDialog, que nem oferece o botão nesse caso.
            'comments' => Comment::listFor($id, $usersId, !$isSelf),
        ];
    }

    /**
     * 6c-2 — restaura uma tarefa EXCLUÍDA (decisão nº 9): SÓ zera
     * `is_deleted` (conclusão, pendência e datas ficam intactas — a
     * tarefa volta ao estado que tinha) e atualiza `date_mod`. Ela
     * reaparece nas telas vivas conforme a própria data.
     *
     * 9b-2 — a tarefa pode ser do PRÓPRIO usuário ou de um técnico do
     * escopo do gestor. Regras revalidadas a CADA POST (T18), nunca
     * herdadas da tela nem do `view` que o front mandou:
     *  - posse: dono = o próprio, OU dono presente nas opções montadas
     *    AGORA a partir do Team::scope (quem perdeu a gestão do setor
     *    entre o carregamento e este POST perde a ação já aqui). Falha
     *    de escopo devolve a MESMA mensagem neutra de "não encontrada"
     *    — a resposta não conta ao gestor que a tarefa existe;
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

        $notFound = ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];

        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return $notFound;
        }

        // Busca da linha SEM filtro de is_deleted — o ownRow do
        // Occurrence filtra is_deleted = 0 de propósito (régua do
        // trabalho vivo); aqui a excluída é exatamente o alvo. E SEM
        // filtro de dono: a posse é decidida logo abaixo, porque o
        // dono legítimo pode ser o gestor OU o técnico dele.
        $row = null;
        foreach ($DB->request([
            'FROM'  => Occurrence::TABLE,
            'WHERE' => [
                Occurrence::TABLE . '.id' => $id,
            ],
        ]) as $r) {
            $row = $r;
            break;
        }
        if ($row === null) {
            return $notFound;
        }

        $ownerId = (int) ($row['users_id'] ?? 0);
        if ($ownerId !== $usersId) {
            // 9b-2: caminho do gestor. Escopo consultado AGORA e o
            // mesmo resolveView da leitura — a ação nunca alcança quem
            // o seletor não ofereceria.
            $scope   = Access::canTeam() ? Team::scope($usersId) : ['groups' => [], 'members' => []];
            $view    = self::resolveView($usersId, 'user', $ownerId, self::optionsFor($scope, $usersId));
            if ($view['is_self'] || (int) $view['id'] !== $ownerId) {
                return $notFound;
            }
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
