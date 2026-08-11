<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — tarefas avulsas e ocorrências (Etapa 1; JOIN com rotinas na 2b).
 *
 * Camada única de dados/ações da tabela glpi_plugin_taskplus_occurrences,
 * usada pelo endpoint ajax/occurrence.php e pelo front/today.php. Fica em
 * classe própria (e não no endpoint) para ser validável por harness.
 *
 * Regras desta etapa:
 *  - o usuário só enxerga e mexe nas PRÓPRIAS ocorrências (users_id = ele);
 *    criar para terceiros é papel do gestor (Etapa 4);
 *  - avulsa = plugin_taskplus_routines_id NULL (decisão-chave de produto);
 *    editar/excluir só vale para avulsas — ocorrência de rotina (Etapa 2)
 *    aceita apenas concluir/desfazer;
 *  - exclusão é SEMPRE soft (is_deleted = 1): preserva a trilha auditável
 *    prometida para o Histórico (Etapa 5);
 *  - toda consulta usa coluna qualificada (tabela.coluna), mesmo sem JOIN —
 *    higiene herdada do ProjectPlus (erro 1052 quando o JOIN chegar).
 */
class Occurrence
{
    public const TABLE = 'glpi_plugin_taskplus_occurrences';

    // =====================================================================
    // Payload da tela Hoje (usado no carregamento e devolvido pelo ajax)
    // =====================================================================

    /**
     * Tudo que a tela Hoje precisa para renderizar, já formatado:
     *
     *   [
     *     'date'    => 'Y-m-d' (hoje, para o input de data do modal),
     *     'kpis'    => ['today' => n, 'done' => n, 'late' => n],
     *     'today'   => [itens do dia, pendentes primeiro],
     *     'overdue' => [pendentes de dias anteriores, mais antigas primeiro],
     *   ]
     *
     * KPIs: today = total do dia (feitas + pendentes); done = concluídas do
     * dia; late = pendentes de dias anteriores + pendentes de hoje com o
     * horário-limite estourado.
     */
    public static function payload(int $usersId, ?string $from = null, ?string $to = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // 5b-2 pacote 2: período (de–até) OPCIONAL. Com os dois nulos o
        // caminho é EXATAMENTE o de sempre (premissa 1 da decisão
        // nº 14). Com período ativo, a lista vira "tarefas próprias com
        // data no intervalo" e os KPIs valem para esse conjunto
        // (premissa 2); as origens nativas seguem como estado atual,
        // fora do recorte (premissa 3).
        [$from, $to]  = self::periodRange($from, $to);
        $periodActive = ($from !== null || $to !== null);

        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');

        $todayRows   = [];
        $overdueRows = [];

        if ($periodActive) {
            // Uma consulta só: tudo do intervalo (aberta, concluída,
            // pendente), sem excluídas nem puladas. As linhas vão TODAS
            // para $todayRows — "atrasada" num intervalo é estado do
            // item (is_late), não uma lista à parte.
            $where = [
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
                self::TABLE . '.is_skipped' => 0,
            ];
            // Duas restrições sobre a MESMA coluna não podem dividir a
            // chave do array (a segunda sobrescreveria a primeira):
            // entram como critérios aninhados, que o iterator ANDa.
            if ($from !== null) {
                $where[] = [self::TABLE . '.date' => ['>=', $from]];
            }
            if ($to !== null) {
                $where[] = [self::TABLE . '.date' => ['<=', $to]];
            }
            foreach ($DB->request(self::baseQuery() + ['WHERE' => $where]) as $row) {
                $todayRows[] = self::format($row, $today, $nowTime);
            }
            // Cronológica: a leitura natural de um intervalo
            usort($todayRows, [self::class, 'compareOverdue']);
        } else {
        foreach ($DB->request(self::baseQuery() + [
            'WHERE' => [
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
                // Pulada sai da tela do dia (fica só no Histórico)
                self::TABLE . '.is_skipped' => 0,
                self::TABLE . '.date'       => $today,
            ],
        ]) as $row) {
            $todayRows[] = self::format($row, $today, $nowTime);
        }

        foreach ($DB->request(self::baseQuery() + [
            'WHERE' => [
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
                self::TABLE . '.is_done'    => 0,
                self::TABLE . '.is_skipped' => 0,
                self::TABLE . '.date'       => ['<', $today],
            ],
        ]) as $row) {
            $overdueRows[] = self::format($row, $today, $nowTime);
        }

        // Concluída HOJE, mas de dia anterior (4d-2): não casa com "do
        // dia" (date = hoje) nem com "atrasadas" (is_done = 0) — sem esta
        // terceira consulta ela sumia das duas telas ao ser concluída.
        // Entra na lista do dia marcada com `was_overdue`: vai para a
        // coluna Concluídas do quadro, para o "Mostrar concluídas" da
        // tela Hoje (com a data original no badge) e para o KPI de
        // Concluídas — sem inflar o KPI "Para hoje", que é do dia.
        foreach ($DB->request(self::baseQuery() + [
            'WHERE' => [
                self::TABLE . '.users_id'   => $usersId,
                self::TABLE . '.is_deleted' => 0,
                self::TABLE . '.is_done'    => 1,
                self::TABLE . '.is_skipped' => 0,
                self::TABLE . '.date'       => ['<', $today],
                self::TABLE . '.done_date'  => ['>=', $today . ' 00:00:00'],
            ],
        ]) as $row) {
            $item                = self::format($row, $today, $nowTime);
            $item['was_overdue'] = true;
            $todayRows[]         = $item;
        }

        // Ordenação em PHP, não no SQL: o controle fino ("NULL de
        // time_limit por último") é mais simples e testável aqui.
        usort($todayRows, [self::class, 'compareToday']);
        usort($overdueRows, [self::class, 'compareOverdue']);
        } // fim do modo-dia (sem período)


        // Origens nativas (Etapa 3): leitura + link, no fim da lista.
        // Falha de leitura não pode derrubar a tela — o usuário ainda
        // precisa das próprias tarefas. Cada origem tem o seu try: um
        // schema diferente em chamados não pode esconder os projetos.
        $native = [];
        try {
            $native = array_merge($native, Native::ticketTasks($usersId));
        } catch (\Throwable $e) {
            // origem indisponível: segue sem ela
        }
        try {
            $native = array_merge($native, Native::projectTasks($usersId));
        } catch (\Throwable $e) {
            // origem indisponível: segue sem ela
        }

        // Pendências (Etapa 4b): marcadas por usuário, valem para tarefa
        // própria e para item nativo. Expiram sozinhas — activeMap já
        // descarta as que passaram da data de retorno.
        $pendings = [];
        try {
            $pendings = Pending::activeMap($usersId, $today);
        } catch (\Throwable $e) {
            $pendings = [];
        }

        $todayRows   = self::applyPendings($todayRows, $pendings, Pending::TYPE_OCCURRENCE, $usersId);
        $overdueRows = self::applyPendings($overdueRows, $pendings, Pending::TYPE_OCCURRENCE, $usersId);
        $native      = self::applyPendings($native, $pendings, null, $usersId);

        // Auditoria (5b-1/5b-2): resolve o NOME de quem concluiu ou de
        // quem marcou a pendência, quando não foi o próprio dono (ação
        // do gestor pela Equipe). Uma consulta por lista, no máximo.
        $todayRows   = self::fillActorLabels($todayRows);
        $overdueRows = self::fillActorLabels($overdueRows);

        // KPIs contam APENAS as tarefas próprias (as nativas são leitura e
        // nunca migrariam para "Concluídas"), com uma exceção: pendência
        // vale para qualquer origem, então o KPI de pendentes soma todas.
        //
        // Tarefa pendente SAI de "Para hoje" e de "Atrasadas": é uma
        // espera declarada, com data de volta — senão o número do dia
        // nunca fecharia.
        $pendingCount = 0;
        $todayCount   = 0;
        $done         = 0;
        $lateToday    = 0;
        $overdueCount = 0;

        if ($periodActive) {
            // KPIs do CONJUNTO do período (premissa 2), SÓ tarefas
            // próprias: as nativas estão fora do recorte (premissa 3) e
            // por isso também ficam fora destes números — inclusive do
            // KPI de pendentes, que no modo-dia as soma. Precedência
            // igual à do Quadro: pendente · concluída · atrasada · aberta.
            foreach ($todayRows as $item) {
                if (!empty($item['is_pending'])) {
                    $pendingCount++;
                } elseif (!empty($item['is_done'])) {
                    $done++;
                } elseif (!empty($item['is_late'])) {
                    $overdueCount++;
                } else {
                    $todayCount++; // aberta no período (hoje ou futura)
                }
            }
        } else {
        foreach ($todayRows as $item) {
            if (!empty($item['is_pending'])) {
                $pendingCount++;
                continue;
            }
            if (!empty($item['was_overdue'])) {
                // De dia anterior, concluída hoje: conta em Concluídas
                // (foi trabalho de hoje), mas não em "Para hoje" — esse
                // KPI é do que estava agendado para o dia.
                if ($item['is_done']) {
                    $done++;
                }
                continue;
            }
            $todayCount++;
            if ($item['is_done']) {
                $done++;
            } elseif ($item['is_late']) {
                $lateToday++;
            }
        }

        foreach ($overdueRows as $item) {
            if (!empty($item['is_pending'])) {
                $pendingCount++;
                continue;
            }
            $overdueCount++;
        }

        foreach ($native as $item) {
            if (!empty($item['is_pending'])) {
                $pendingCount++;
            }
        }
        } // fim dos KPIs do modo-dia

        $kpis = [
            'late'    => $overdueCount + $lateToday,
            'today'   => $todayCount,
            'pending' => $pendingCount,
            'done'    => $done,
        ];

        return [
            'date'    => $today,
            'kpis'    => $kpis,
            'today'   => array_merge($todayRows, $native),
            'overdue' => $overdueRows,
            // Eco do recorte já NORMALIZADO (datas inválidas caem, par
            // invertido vira crescente): é a fonte da verdade que o JS
            // espelha nos inputs e no aviso. Chave nova → safeData.
            'period'  => [
                'from'   => $from ?? '',
                'to'     => $to ?? '',
                'active' => $periodActive,
            ],
        ];
    }

    // =====================================================================
    // Período (5b-2 pacote 2)
    // =====================================================================

    /**
     * Normaliza UMA borda de período vinda do POST: '' ou data inválida
     * viram null (borda ausente — o recorte fica aberto daquele lado).
     */
    public static function periodBound(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        return self::validDate($raw);
    }

    /**
     * Normaliza o PAR de bordas: cada uma pelo periodBound e, com as
     * duas presentes e invertidas (de > até), troca em vez de recusar —
     * a intenção do usuário é óbvia e a tela não precisa de um erro.
     * Usada aqui, no Board e no Team: a régua do recorte é UMA só.
     */
    public static function periodRange(?string $from, ?string $to): array
    {
        $from = self::periodBound($from);
        $to   = self::periodBound($to);

        if ($from !== null && $to !== null && $from > $to) {
            return [$to, $from];
        }
        return [$from, $to];
    }

    /**
     * Preenche `done_by_label` e `pending_by_label` nos itens cuja ação
     * (conclusão 5b-1 / pendência 5b-2) foi de OUTRO usuário: uma única
     * consulta para todos os ids, mesmo formato de nome da tela Equipe
     * (firstname+realname, fallback no login). Autor excluído do GLPI
     * deixa o label vazio — o front simplesmente não mostra o badge.
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

    /**
     * Marca em cada item se ele tem pendência ativa deste usuário.
     * `$forceType` fixa o itemtype (tarefas próprias); com null, usa o
     * `source` do item nativo (TicketTask / ProjectTask). `$ownerId`
     * (5b-2) é o DONO do payload: pendência com criador diferente dele
     * ganha a marca de autoria (gestor pela Equipe).
     */
    private static function applyPendings(array $items, array $pendings, ?string $forceType, int $ownerId = 0): array
    {
        $sourceMap = [
            'ticket'  => Pending::TYPE_TICKET_TASK,
            'project' => Pending::TYPE_PROJECT_TASK,
        ];

        foreach ($items as &$item) {
            $type = $forceType ?? ($sourceMap[$item['source'] ?? ''] ?? null);

            $item['pending_type']     = $type;
            $item['is_pending']       = false;
            $item['pending_reason']   = '';
            $item['pending_label']    = '';
            $item['pending_until']    = '';
            $item['pending_time']     = '';
            $item['pending_by_other'] = false;
            $item['pending_by_id']    = 0;
            $item['pending_by_label'] = '';

            if ($type === null) {
                continue;
            }

            $key = $type . ':' . (int) ($item['id'] ?? 0);
            if (isset($pendings[$key])) {
                $item['is_pending']     = true;
                $item['pending_reason'] = $pendings[$key]['reason'];
                $item['pending_label']  = $pendings[$key]['label'];
                $item['pending_until']  = $pendings[$key]['until'];
                $item['pending_time']   = $pendings[$key]['time'] ?? '';
                // 5b-2: pendência marcada por OUTRO usuário (gestor).
                // creator 0 = linha anterior à coluna → foi o dono.
                $creator = (int) ($pendings[$key]['creator'] ?? 0);
                $item['pending_by_id']    = $creator;
                $item['pending_by_other'] = $creator > 0 && $ownerId > 0 && $creator !== $ownerId;
                // Pendente não é atrasada: a espera foi combinada
                $item['is_late'] = false;
            }
        }
        unset($item);

        return $items;
    }

    /**
     * SELECT + FROM + LEFT JOIN comuns às consultas da tela Hoje
     * (Etapa 2b: a ocorrência passou a poder vir de rotina, e a tela
     * agrupa por Diárias/Semanais/Mensais).
     *
     * TODA coluna vai qualificada e o SELECT é explícito de propósito:
     * `id`, `name`, `date_mod` e `is_deleted` existem nas DUAS tabelas —
     * `SELECT *` num JOIN devolveria a coluna errada e o WHERE sem
     * qualificar daria erro 1052 ("Column ... is ambiguous").
     */
    private static function baseQuery(): array
    {
        return [
            'SELECT' => [
                self::TABLE . '.id',
                self::TABLE . '.plugin_taskplus_routines_id',
                self::TABLE . '.users_id',
                self::TABLE . '.users_id_done',
                self::TABLE . '.users_id_creator',
                self::TABLE . '.name',
                self::TABLE . '.description',
                self::TABLE . '.category',
                self::TABLE . '.date',
                self::TABLE . '.time_limit',
                self::TABLE . '.is_done',
                self::TABLE . '.done_date',
                self::TABLE . '.is_edited',
                self::TABLE . '.plugin_taskplus_phases_id',
                Routine::TABLE . '.name AS routine_name',
                Routine::TABLE . '.frequency AS routine_frequency',
            ],
            'FROM'      => self::TABLE,
            'LEFT JOIN' => [
                Routine::TABLE => [
                    'ON' => [
                        Routine::TABLE => 'id',
                        self::TABLE    => 'plugin_taskplus_routines_id',
                    ],
                ],
            ],
        ];
    }

    /**
     * Linha do banco → item do payload (formatos prontos para o JS).
     */
    private static function format(array $row, string $today, string $nowTime): array
    {
        $isDone = ((int) ($row['is_done'] ?? 0)) === 1;
        $date   = (string) ($row['date'] ?? $today);
        $limit  = $row['time_limit'] ?? null; // 'HH:MM:SS' ou NULL

        // Atrasada = pendente E (dia já passou OU horário-limite de hoje
        // estourado). Concluída nunca é atrasada.
        $isLate = !$isDone && (
            $date < $today
            || ($date === $today && $limit !== null && $limit !== '' && $limit < $nowTime)
        );

        $isRoutine = ($row['plugin_taskplus_routines_id'] ?? null) !== null;

        // Grupo da tela Hoje: 'daily'|'weekly'|'monthly' para ocorrência de
        // rotina, 'avulsa' para o resto. Rotina excluída depois de gerar a
        // ocorrência do dia deixa o JOIN sem par → cai em 'avulsa' de
        // propósito: a tarefa continua existindo e concluível, só perde o
        // agrupamento.
        $group = 'avulsa';
        if ($isRoutine && !empty($row['routine_frequency'])) {
            $group = (string) $row['routine_frequency'];
        }

        return [
            'id'          => (int) ($row['id'] ?? 0),
            // Ocorrência de rotina não é editável/excluível na tela Hoje
            'is_routine'  => $isRoutine,
            // Origem própria do Task+ (as nativas vêm de Native.php)
            'is_native'   => false,
            'is_edited'   => ((int) ($row['is_edited'] ?? 0)) === 1,
            // Fase de trabalho do Quadro (Etapa 4d). NULL/0 = padrão.
            'phases_id'   => (int) ($row['plugin_taskplus_phases_id'] ?? 0),
            'group'       => $group,
            // 6b: id da rotina-mãe (0 = avulsa), para o Painel agrupar a
            // taxa por rotina SEM colidir nomes iguais. Chave nova é
            // inofensiva para os safeItem existentes (whitelist ignora).
            'routines_id' => (int) ($row['plugin_taskplus_routines_id'] ?? 0),
            'routine_name' => (string) ($row['routine_name'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'category'    => (string) ($row['category'] ?? ''),
            'date'        => $date,
            'date_label'  => substr($date, 8, 2) . '/' . substr($date, 5, 2),
            'time_limit'  => ($limit !== null && $limit !== '') ? substr((string) $limit, 0, 5) : null,
            'is_done'     => $isDone,
            'done_time'   => !empty($row['done_date']) ? substr((string) $row['done_date'], 11, 5) : null,
            // Auditoria da 5b-1: quem concluiu (users_id_done, gravado
            // desde a Etapa 1). `done_by_other` = concluída por OUTRO
            // usuário (gestor pela tela Equipe); o nome é resolvido em
            // lote por fillDoneBy() no payload — aqui fica vazio.
            'done_by_other' => $isDone
                && ((int) ($row['users_id_done'] ?? 0)) > 0
                && ((int) ($row['users_id_done'] ?? 0)) !== ((int) ($row['users_id'] ?? 0)),
            'done_by_id'    => (int) ($row['users_id_done'] ?? 0),
            'done_by_label' => '',
            // Autoria da CRIAÇÃO (5c-1): tarefa criada por OUTRO usuário
            // (gestor pela tela Equipe). `users_id_creator` existe desde
            // a Etapa 1 e até o 5c era sempre = dono — usar como autoria
            // é só mudança de leitura, sem schema. Ocorrência de rotina
            // herda o criador da rotina no generateForDate, então rotina
            // criada pelo gestor (5c-2) ganhará o badge de graça. O nome
            // é resolvido em lote por fillActorLabels — aqui fica vazio.
            'created_by_other' => ((int) ($row['users_id_creator'] ?? 0)) > 0
                && ((int) ($row['users_id_creator'] ?? 0)) !== ((int) ($row['users_id'] ?? 0)),
            'created_by_id'    => (int) ($row['users_id_creator'] ?? 0),
            'created_by_label' => '',
            'is_late'     => $isLate,
            // Setada como true só na consulta "concluída hoje, de dia
            // anterior" (4d-2); presente em todo item pela mesma higiene
            // do resto do payload (chave usada nunca pode faltar).
            'was_overdue' => false,
        ];
    }

    /**
     * Hoje: pendentes antes das feitas; dentro do grupo, horário-limite
     * crescente (sem limite vai para o fim); empate resolve por id.
     */
    private static function compareToday(array $a, array $b): int
    {
        if ($a['is_done'] !== $b['is_done']) {
            return $a['is_done'] ? 1 : -1;
        }
        $ta = $a['time_limit'] ?? null;
        $tb = $b['time_limit'] ?? null;
        if ($ta !== $tb) {
            if ($ta === null) {
                return 1;
            }
            if ($tb === null) {
                return -1;
            }
            return strcmp($ta, $tb);
        }
        return $a['id'] <=> $b['id'];
    }

    /**
     * Atrasadas: as mais antigas primeiro.
     */
    private static function compareOverdue(array $a, array $b): int
    {
        return [$a['date'], $a['time_limit'] ?? '99:99', $a['id']]
            <=> [$b['date'], $b['time_limit'] ?? '99:99', $b['id']];
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
            case 'toggle':
                return self::toggle($input, $usersId);
            case 'skip':
                return self::skip($input, $usersId);
            case 'unskip':
                return self::unskip($input, $usersId);
            case 'pending':
                return self::setPending($input, $usersId);
            case 'unpending':
                return self::clearPending($input, $usersId);
            case 'list':
                // Só quer o payload atualizado (o endpoint já o inclui)
                return ['success' => true, 'message' => ''];
            default:
                return ['success' => false, 'message' => __('Ação desconhecida', 'taskplus')];
        }
    }

    private static function add(array $input, int $usersId): array
    {
        // Dono criando para si: dono = criador
        return self::addFor($input, $usersId, $usersId);
    }

    /**
     * Cria uma AVULSA para o dono $ownerId com $creatorId como AUTOR
     * (5c-1: gestor pela tela Equipe). A tarefa é DO TÉCNICO — aparece
     * na tela Hoje dele, com badge "criada pelo gestor" quando o
     * criador difere do dono (mesmo padrão de auditoria do 5b). A
     * validação de escopo (técnico de setor gerido) já foi feita no
     * Team::handle; os CAMPOS são validados aqui pelo mesmo
     * cleanFields do caminho próprio.
     */
    public static function addFor(array $input, int $ownerId, int $creatorId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        // 8d — trava de duplicadas (aviso com confirmação, não bloqueio):
        // vale SÓ para a criação própria (tela Hoje). A criação pelo
        // gestor (5c) fica fora de propósito — o destino "todos do
        // setor" dispararia N confirmações e travaria o fluxo. Com
        // `force_duplicate` no POST (o JS reenvia após o confirm), a
        // criação segue. A chave `duplicate` na resposta é o que o JS
        // usa para distinguir do erro comum.
        if (
            $ownerId === $creatorId
            && empty($input['force_duplicate'])
            && self::duplicateExists($ownerId, $fields['name'], $fields['date'])
        ) {
            return [
                'success'   => false,
                'duplicate' => true,
                'message'   => __('Já existe uma tarefa aberta igual nesta data', 'taskplus'),
            ];
        }

        $now = date('Y-m-d H:i:s');

        // plugin_taskplus_routines_id fica FORA de propósito: o DEFAULT
        // NULL da coluna é o que marca a tarefa como avulsa.
        $DB->insert(self::TABLE, $fields + [
            'users_id'         => $ownerId,
            'users_id_creator' => $creatorId,
            'is_done'          => 0,
            'is_skipped'       => 0,
            'is_deleted'       => 0,
            'date_creation'    => $now,
            'date_mod'         => $now,
        ]);

        return ['success' => true, 'message' => __('Tarefa criada', 'taskplus')];
    }

    private static function update(array $input, int $usersId): array
    {
        return self::updateFor($input, $usersId);
    }

    /**
     * Edita a ocorrência do dono $ownerId (5b-2: a tela Equipe chama com
     * o TÉCNICO como dono — a validação de escopo já foi feita no
     * Team::handle; posse e regras de rotina são reverificadas aqui, na
     * hora da escrita, como manda a T18).
     */
    public static function updateFor(array $input, int $ownerId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $ownerId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        $fields = self::cleanFields($input);
        if (is_string($fields)) {
            return ['success' => false, 'message' => $fields];
        }

        $isRoutine = ($row['plugin_taskplus_routines_id'] ?? null) !== null;
        if ($isRoutine) {
            // Editar ocorrência de rotina altera SÓ o dia: a rotina segue
            // intacta e amanhã o cron gera uma nova, limpa. Mudar a DATA
            // fica de fora — ela é metade da UNIQUE `routine_day`, e mover
            // o dia colidiria com a ocorrência que já existe lá.
            // (Revisado e CONFIRMADO na homologação do 4d, 08/08: tarefa
            // atrasada não ganha data nova — ou se conclui, ou vira
            // pendência, que já carrega a data/hora de retorno.)
            unset($fields['date']);
            $fields['is_edited'] = 1;
        }

        $DB->update(
            self::TABLE,
            $fields + ['date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return [
            'success' => true,
            'message' => $isRoutine
                ? __('Tarefa de hoje atualizada (a rotina nao mudou)', 'taskplus')
                : __('Tarefa atualizada', 'taskplus'),
        ];
    }

    /**
     * "Pular hoje": a tarefa não se aplicava neste dia (servidor em
     * manutenção, cliente ausente, feriado interno). Sai da lista sem
     * contar como concluída nem como atrasada, e o motivo fica gravado
     * para o Histórico. A rotina segue normal e gera amanhã.
     */
    private static function skip(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        if (((int) ($row['is_done'] ?? 0)) === 1) {
            return ['success' => false, 'message' => __('Tarefa concluída não pode ser pulada', 'taskplus')];
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            return ['success' => false, 'message' => __('Informe o motivo', 'taskplus')];
        }

        $now = date('Y-m-d H:i:s');
        $DB->update(self::TABLE, [
            'is_skipped'    => 1,
            'skip_reason'   => $reason,
            'skip_date'     => $now,
            'users_id_skip' => $usersId,
            'date_mod'      => $now,
        ], [self::TABLE . '.id' => (int) $row['id']]);

        return ['success' => true, 'message' => __('Tarefa pulada hoje', 'taskplus')];
    }

    /** Desfaz o "pular" (clicou no card errado, ou o dia mudou). */
    private static function unskip(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        // skip_reason/skip_date ficam: a trilha do Histórico mostra que
        // houve um pulo desfeito, não um dia sem nada.
        $DB->update(
            self::TABLE,
            ['is_skipped' => 0, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Tarefa voltou para o dia', 'taskplus')];
    }

    /**
     * Marca pendência. Aceita tarefa própria E item nativo (chamado ou
     * tarefa de projeto): a pendência mora em tabela do plugin, então
     * nada é gravado no GLPI.
     */
    private static function setPending(array $input, int $usersId): array
    {
        // Dono marcando a própria pendência: dono = autor
        return self::setPendingFor($input, $usersId, $usersId);
    }

    /**
     * Marca pendência em nome do dono $ownerId, com $creatorId como
     * AUTOR (5b-2: gestor pela Equipe). A pendência é DO DONO — é na
     * tela Hoje dele que ela aparece e expira; a autoria fica na trilha
     * e vira o badge "pendência pelo gestor". Posse reverificada na
     * hora (T18); item nativo só chega pelo caminho do próprio dono
     * (a Equipe nunca envia itemtype — decisão nº 12).
     */
    public static function setPendingFor(array $input, int $ownerId, int $creatorId): array
    {
        $itemtype = (string) ($input['itemtype'] ?? Pending::TYPE_OCCURRENCE);
        $itemsId  = (int) ($input['id'] ?? 0);

        // Só a tarefa PRÓPRIA passa pela checagem de dono: item nativo já
        // chega filtrado pela consulta do Native (users_id_tech / equipe).
        if ($itemtype === Pending::TYPE_OCCURRENCE && self::ownRow($itemsId, $ownerId) === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        return Pending::set($itemtype, $itemsId, $ownerId, $input, $creatorId);
    }

    private static function clearPending(array $input, int $usersId): array
    {
        return self::clearPendingFor($input, $usersId);
    }

    /**
     * Encerra a pendência do dono $ownerId (5b-2: a Equipe chama com o
     * técnico como dono — "liberar pendência" devolve a tarefa ao fluxo).
     */
    public static function clearPendingFor(array $input, int $ownerId): array
    {
        return Pending::clear(
            (string) ($input['itemtype'] ?? Pending::TYPE_OCCURRENCE),
            (int) ($input['id'] ?? 0),
            $ownerId
        );
    }

    private static function delete(array $input, int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow((int) ($input['id'] ?? 0), $usersId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }
        if (($row['plugin_taskplus_routines_id'] ?? null) !== null) {
            return ['success' => false, 'message' => __('Ocorrência de rotina não pode ser excluída aqui', 'taskplus')];
        }

        // Soft delete sempre — preserva a trilha do Histórico (Etapa 5)
        $DB->update(
            self::TABLE,
            ['is_deleted' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return ['success' => true, 'message' => __('Tarefa excluída', 'taskplus')];
    }

    /**
     * Concluir (done=1) ou desfazer (done=0) em 1 clique.
     * Vale para QUALQUER ocorrência do próprio usuário (avulsa ou, no
     * futuro, de rotina) — grava autor e hora, exigência da auditoria.
     */
    private static function toggle(array $input, int $usersId): array
    {
        $done = ((int) ($input['done'] ?? 0)) === 1;

        // Dono agindo sobre a própria tarefa: dono = autor da conclusão
        return self::toggleFor((int) ($input['id'] ?? 0), $usersId, $usersId, $done);
    }

    /**
     * Concluir/desfazer a ocorrência $occId do usuário $ownerId, tendo
     * $actorId como AUTOR da ação (5b-1). É o único caminho de escrita
     * do toggle: a tela Hoje passa dono = autor; a tela Equipe passa o
     * TÉCNICO como dono e o GESTOR como autor — `users_id_done` é a
     * trilha de auditoria ("quem concluiu"), exibida quando difere do
     * dono. Público de propósito: Team::handle valida o ESCOPO (técnico
     * de setor gerido) e delega a validação de posse/estado para cá,
     * reverificada NA HORA da escrita (T18).
     */
    public static function toggleFor(int $occId, int $ownerId, int $actorId, bool $done): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::ownRow($occId, $ownerId);
        if ($row === null) {
            return ['success' => false, 'message' => __('Tarefa não encontrada', 'taskplus')];
        }

        $DB->update(
            self::TABLE,
            [
                'is_done'       => $done ? 1 : 0,
                'done_date'     => $done ? date('Y-m-d H:i:s') : null,
                'users_id_done' => $done ? $actorId : 0,
                'date_mod'      => date('Y-m-d H:i:s'),
            ],
            [self::TABLE . '.id' => (int) $row['id']]
        );

        return [
            'success' => true,
            'message' => $done
                ? __('Tarefa concluída', 'taskplus')
                : __('Conclusão desfeita', 'taskplus'),
        ];
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Valida e normaliza os campos editáveis da avulsa.
     * Devolve o array pronto para insert/update, ou a MENSAGEM de erro
     * (string) quando algo inválido chegou.
     */
    /**
     * 8d — existe avulsa VIVA (não excluída, não concluída, não pulada)
     * do dono nesta data com o mesmo título normalizado? Concluídas e
     * excluídas ficam de fora de propósito: refazer uma tarefa já
     * fechada no mesmo dia é legítimo. A comparação normalizada roda em
     * PHP sobre as poucas linhas do dono no dia — colapsar espaços e
     * tirar acento não tem equivalente simples no SQL.
     */
    public static function duplicateExists(int $ownerId, string $name, string $date): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $needle = self::normTitle($name);
        if ($needle === '') {
            return false;
        }

        $rows = $DB->request([
            'SELECT' => [self::TABLE . '.name'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                self::TABLE . '.users_id'   => $ownerId,
                self::TABLE . '.date'       => $date,
                self::TABLE . '.is_deleted' => 0,
                self::TABLE . '.is_done'    => 0,
                self::TABLE . '.is_skipped' => 0,
            ],
        ]);

        foreach ($rows as $row) {
            if (self::normTitle((string) $row['name']) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Título normalizado para comparação de duplicada: minúsculas, sem
     * acentos (mapa pt-BR explícito — não depende da extensão intl) e
     * espaços internos colapsados. Espelha o norm() do JS da busca.
     */
    public static function normTitle(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }

    private static function cleanFields(array $input): array|string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return __('Informe o título da tarefa', 'taskplus');
        }

        $rawDate = trim((string) ($input['date'] ?? ''));
        if ($rawDate === '') {
            $date = date('Y-m-d'); // sem data = hoje
        } else {
            $date = self::validDate($rawDate);
            if ($date === null) {
                return __('Data inválida', 'taskplus');
            }
        }

        $rawTime = trim((string) ($input['time_limit'] ?? ''));
        if ($rawTime === '') {
            $time = null; // NULL = sem horário-limite
        } else {
            $time = self::validTime($rawTime);
            if ($time === null) {
                return __('Horário-limite inválido', 'taskplus');
            }
        }

        return [
            'name'        => mb_substr($name, 0, 255),
            'description' => trim((string) ($input['description'] ?? '')),
            'category'    => mb_substr(trim((string) ($input['category'] ?? '')), 0, 255),
            'date'        => $date,
            'time_limit'  => $time,
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
     * A ocorrência $id, se pertencer a $usersId e não estiver excluída —
     * versão PÚBLICA de ownRow, para o Board (Etapa 4d) validar dono e
     * estado sem duplicar a consulta.
     */
    public static function findOwn(int $id, int $usersId): ?array
    {
        return self::ownRow($id, $usersId);
    }

    /**
     * A ocorrência $id, se pertencer a $usersId e não estiver excluída.
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
