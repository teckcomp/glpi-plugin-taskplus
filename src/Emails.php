<?php

namespace GlpiPlugin\Taskplus;

use Config as CoreConfig;
use NotificationEvent;

/**
 * Task+ — e-mails (Etapa 7b; decisão nº 21).
 *
 * Camada de DOMÍNIO dos e-mails do cron `taskplusalerts` (Cron.php só
 * orquestra e loga). Nesta etapa:
 *
 *  - 7b-1: fim de dia ao TÉCNICO — o que ficou aberto de hoje,
 *    atrasado de dias anteriores e pendente (adiado com motivo/data/hora),
 *    só do Task+ (nativas FORA — decisão nº 3, mesma régua do 7a) e
 *    concluídas FORA (o e-mail é chamada para ação, não diário de bordo);
 *  - 7b-2 (decisão nº 22): resumo matinal ao GESTOR — uma linha por
 *    técnico dos grupos onde ele é `is_manager`, com o MESMO trio do fim
 *    de dia agregado (abertas de hoje / atrasadas / pendentes), sem
 *    listar tarefas. Técnico zerado SOME da linha; gestor com todos os
 *    técnicos zerados não recebe nada; a linha do PRÓPRIO gestor entra
 *    quando ele é membro do grupo e tem conteúdo (diferente do sino do
 *    7a, que não se auto-avisa). Destinatário via alvo CUSTOM
 *    (TARGET_MANAGER + addSpecificTargets no target — validado no fonte
 *    do 11.0.6: items_id fora das constantes do core cai no default do
 *    addForTarget, e as $options do raiseEvent chegam intactas lá).
 *
 * Regras travadas na abertura da 7b:
 *  - horário de disparo em chave da Config (`email_eod_time`, default
 *    18:00), editável só pelo admin na tela Configurações;
 *  - o cron roda a cada 10 min: envia na PRIMEIRA rodada em que o
 *    horário já passou E ainda não enviou hoje (trilha `email_eod_last`
 *    na própria Config — sem coluna nova);
 *  - "só envia se houver conteúdo": técnico com dia zerado não recebe
 *    nada. A trilha do dia é carimbada MESMO com envio falho (uma
 *    tentativa por dia — filosofia do 7a: sem rajada retroativa);
 *  - canal = cadeia NATIVA de notificação (decisão nº 21): evento
 *    `end_of_day` no target do 7a, modo `mailing` (o core ENFILEIRA em
 *    glpi_queuednotifications e o cron nativo `queuednotification`
 *    despacha; opt-out por usuário e liga/desliga do admin de graça).
 *
 * O conteúdo do e-mail nasce aqui (templateRows) no formato do motor de
 * templates do core: tags escalares `##taskplus.*##` + três listas
 * (`open`, `overdue`, `pending`) para os blocos `##FOREACHxxx##` do
 * modelo — cada valor é escapado pelo PRÓPRIO core na hora de montar o
 * HTML (getDataForHtml), então aqui vai texto puro, nunca HTML pronto.
 */
class Emails
{
    /** Evento nativo do e-mail de fim de dia (target do 7a). */
    public const EVENT_END_OF_DAY = 'end_of_day';

    /** Evento nativo do resumo matinal ao gestor (7b-2). */
    public const EVENT_MORNING_DIGEST = 'morning_digest';

    /**
     * Alvo CUSTOM "Gestor (resumo matinal)" (7b-2). O core roteia
     * USER_TYPE por items_id: as constantes nativas vão de 1 a 34 e
     * qualquer valor fora delas cai no default do addForTarget →
     * addSpecificTargets do NOSSO target, que lê o gestor das $options
     * do raiseEvent. Valor alto de propósito para nunca colidir com
     * constante futura do core.
     */
    public const TARGET_MANAGER = 97531;

    /** Chave da trilha "já enviei hoje" no contexto de Config do plugin. */
    public const LAST_EOD_KEY = 'email_eod_last';

    /** Trilha diária do resumo matinal (7b-2) — mesma mecânica. */
    public const LAST_DIGEST_KEY = 'email_digest_last';

    // =====================================================================
    // Régua de disparo
    // =====================================================================

    /**
     * Devolve o horário configurado do fim de dia ('HH:MM'), caindo no
     * default quando a chave estiver ausente ou com lixo.
     */
    public static function eodTime(array $config): string
    {
        $raw = (string) ($config['email_eod_time'] ?? '');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw)) {
            return $raw;
        }
        return (string) Config::DEFAULTS['email_eod_time'];
    }

    /**
     * É hora de enviar o fim de dia?  Sim quando: e-mails ligados
     * (`email_enabled`), o relógio já passou do horário configurado E a
     * trilha diz que HOJE ainda não foi enviado.
     *
     * Comparação de 'HH:MM' por string funciona porque o formato é fixo
     * com zero à esquerda — mesma técnica do resto do plugin.
     */
    public static function shouldSendEod(array $config, ?string $now = null): bool
    {
        $now   = $now ?? date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $time  = substr($now, 11, 5);

        if ((int) ($config['email_enabled'] ?? 0) !== 1) {
            return false;
        }
        if ($time < self::eodTime($config)) {
            return false;
        }
        if ((string) ($config[self::LAST_EOD_KEY] ?? '') === $today) {
            return false;
        }

        return true;
    }

    /**
     * Carimba a trilha do dia. Escrita direta no contexto de Config —
     * NÃO passa por Config::set (que só aceita chaves do formulário).
     */
    public static function markEodSent(string $today): void
    {
        CoreConfig::setConfigurationValues(Config::CONTEXT, [self::LAST_EOD_KEY => $today]);
    }

    /**
     * Horário configurado do resumo matinal ('HH:MM'), caindo no default
     * quando a chave estiver ausente ou com lixo — espelho de eodTime.
     */
    public static function digestTime(array $config): string
    {
        $raw = (string) ($config['email_digest_time'] ?? '');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw)) {
            return $raw;
        }
        return (string) Config::DEFAULTS['email_digest_time'];
    }

    /**
     * É hora de enviar o resumo matinal? Mesma régua do fim de dia:
     * e-mails ligados + relógio ≥ `email_digest_time` + trilha
     * `email_digest_last` ≠ hoje. As duas réguas são independentes —
     * o mesmo cron de 10 min atende as duas sem interferência.
     */
    public static function shouldSendDigest(array $config, ?string $now = null): bool
    {
        $now   = $now ?? date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $time  = substr($now, 11, 5);

        if ((int) ($config['email_enabled'] ?? 0) !== 1) {
            return false;
        }
        if ($time < self::digestTime($config)) {
            return false;
        }
        if ((string) ($config[self::LAST_DIGEST_KEY] ?? '') === $today) {
            return false;
        }

        return true;
    }

    /**
     * Carimba a trilha do dia do resumo matinal — mesma escrita direta
     * no contexto de Config (não passa por Config::set).
     */
    public static function markDigestSent(string $today): void
    {
        CoreConfig::setConfigurationValues(Config::CONTEXT, [self::LAST_DIGEST_KEY => $today]);
    }

    // =====================================================================
    // Conteúdo
    // =====================================================================

    /**
     * Usuários com QUALQUER ocorrência viva devida até hoje (aberta de
     * hoje, atrasada ou pendente — pendência é camada por cima de linha
     * viva, então esta consulta única cobre as três seções).
     * Ordenado por id (determinismo).
     */
    public static function eodUsers(?string $today = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $today = $today ?? date('Y-m-d');

        $users = [];
        foreach ($DB->request([
            'SELECT' => [Occurrence::TABLE . '.users_id'],
            'FROM'   => Occurrence::TABLE,
            'WHERE'  => [
                Occurrence::TABLE . '.is_done'    => 0,
                Occurrence::TABLE . '.is_skipped' => 0,
                Occurrence::TABLE . '.is_deleted' => 0,
                Occurrence::TABLE . '.date'       => ['<=', $today],
            ],
        ]) as $row) {
            $uid = (int) ($row['users_id'] ?? 0);
            if ($uid > 0) {
                $users[$uid] = true;
            }
        }

        $out = array_keys($users);
        sort($out);

        return $out;
    }

    /**
     * As três seções do fim de dia de UM técnico:
     *
     *  - open    → abertas de HOJE (sem pendência ativa);
     *  - overdue → vivas de dias ANTERIORES (sem pendência ativa),
     *              mais antigas primeiro;
     *  - pending → as com pendência ATIVA (de hoje ou atrasadas), com
     *              motivo e rótulo "volta em…" — mesma régua da tela
     *              (Pending::activeMap, expira por data+hora).
     *
     * `anchor` = id da primeira ocorrência (é o item em que o evento
     * nativo é disparado — o destinatário AUTHOR lê o users_id dela).
     * anchor = 0 ⇒ dia zerado, nada a enviar para este técnico.
     */
    public static function eodData(int $usersId, ?string $now = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now   = $now ?? date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);

        $select = [
            Occurrence::TABLE . '.id',
            Occurrence::TABLE . '.name',
            Occurrence::TABLE . '.date',
            Occurrence::TABLE . '.time_limit',
            Occurrence::TABLE . '.users_id',
            Occurrence::TABLE . '.users_id_creator',
        ];
        $alive = [
            Occurrence::TABLE . '.users_id'   => $usersId,
            Occurrence::TABLE . '.is_done'    => 0,
            Occurrence::TABLE . '.is_skipped' => 0,
            Occurrence::TABLE . '.is_deleted' => 0,
        ];

        $openRows = [];
        foreach ($DB->request([
            'SELECT' => $select,
            'FROM'   => Occurrence::TABLE,
            'WHERE'  => $alive + [Occurrence::TABLE . '.date' => $today],
            'ORDER'  => Occurrence::TABLE . '.id ASC',
        ]) as $row) {
            $openRows[] = $row;
        }

        $overdueRows = [];
        foreach ($DB->request([
            'SELECT' => $select,
            'FROM'   => Occurrence::TABLE,
            'WHERE'  => $alive + [Occurrence::TABLE . '.date' => ['<', $today]],
            'ORDER'  => [Occurrence::TABLE . '.date ASC', Occurrence::TABLE . '.id ASC'],
        ]) as $row) {
            $overdueRows[] = $row;
        }

        // Pendência ativa move a linha para a terceira seção — a régua
        // de "ativa" é a MESMA da tela (expira por data+hora).
        $map = Pending::activeMap($usersId, $today);

        $open = $overdue = $pending = [];
        foreach ($openRows as $row) {
            $key = Pending::TYPE_OCCURRENCE . ':' . (int) $row['id'];
            if (isset($map[$key])) {
                $row['_pending'] = $map[$key];
                $pending[]       = $row;
            } else {
                $open[] = $row;
            }
        }
        foreach ($overdueRows as $row) {
            $key = Pending::TYPE_OCCURRENCE . ':' . (int) $row['id'];
            if (isset($map[$key])) {
                $row['_pending'] = $map[$key];
                $pending[]       = $row;
            } else {
                $overdue[] = $row;
            }
        }

        $anchor = 0;
        foreach ([$open, $overdue, $pending] as $bucket) {
            if ($bucket !== []) {
                $anchor = (int) $bucket[0]['id'];
                break;
            }
        }

        return [
            'open'    => $open,
            'overdue' => $overdue,
            'pending' => $pending,
            'anchor'  => $anchor,
        ];
    }

    /**
     * Converte eodData no pacote de tags do motor de templates do core:
     * escalares `##taskplus.*##` + listas `open`/`overdue`/`pending`
     * cujas linhas usam tags `##eod.*##` (formato dos followups do
     * core — a chave da linha É a tag completa).
     *
     * Texto sai PURO: o escape é do core (getDataForHtml) na hora de
     * montar o content_html. Datas em dd/mm e horas em HH:MM — o modelo
     * não conhece o formato interno do banco.
     */
    public static function templateRows(array $data, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');

        $rows = static function (array $items) use ($today): array {
            $out = [];
            foreach ($items as $row) {
                $limit = (string) ($row['time_limit'] ?? '');
                $limit = ($limit !== '') ? substr($limit, 0, 5) : '';

                $date  = (string) ($row['date'] ?? '');
                $short = preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $date, $m)
                    ? ($m[2] . '/' . $m[1])
                    : '';

                $uid     = (int) ($row['users_id'] ?? 0);
                $creator = (int) ($row['users_id_creator'] ?? 0);
                $origin  = ($creator > 0 && $creator !== $uid)
                    ? __('criada pelo gestor', 'taskplus')
                    : '';

                $pendingLabel = '';
                if (isset($row['_pending']) && is_array($row['_pending'])) {
                    $label  = (string) ($row['_pending']['label'] ?? '');
                    $reason = trim((string) ($row['_pending']['reason'] ?? ''));
                    $pendingLabel = ($reason !== '') ? trim($label . ' — ' . $reason) : $label;
                }

                $out[] = [
                    '##eod.name##'    => trim((string) ($row['name'] ?? '')),
                    '##eod.limit##'   => $limit,
                    '##eod.date##'    => ($date === $today) ? '' : $short,
                    '##eod.origin##'  => $origin,
                    '##eod.pending##' => $pendingLabel,
                ];
            }
            return $out;
        };

        $open    = $rows($data['open'] ?? []);
        $overdue = $rows($data['overdue'] ?? []);
        $pending = $rows($data['pending'] ?? []);

        $niceDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $today, $m)
            ? ($m[3] . '/' . $m[2] . '/' . $m[1])
            : $today;

        // Contagens como STRING: o ##IFtag## do core trata '0' como
        // falso — seção vazia some do e-mail sozinha.
        return [
            '##taskplus.eod_date##'      => $niceDate,
            '##taskplus.open_count##'    => (string) count($open),
            '##taskplus.overdue_count##' => (string) count($overdue),
            '##taskplus.pending_count##' => (string) count($pending),
            'open'    => $open,
            'overdue' => $overdue,
            'pending' => $pending,
        ];
    }

    // =====================================================================
    // Orquestração (chamada pelo cron)
    // =====================================================================

    /**
     * Rodada do fim de dia: régua de horário → técnicos com conteúdo →
     * um evento nativo por técnico → trilha do dia.
     *
     * $raiser (harness) recebe (usersId, anchorId, tags) e devolve bool;
     * NULL = raiseEod real. A trilha é carimbada SEMPRE que a rodada era
     * devida (uma tentativa por dia — sem rajada retroativa se o e-mail
     * do GLPI estiver desligado/quebrado).
     *
     * Retorno: ['due' => bool, 'users' => n, 'sent' => n].
     */
    public static function processEod(?string $now = null, ?callable $raiser = null): array
    {
        $now    = $now ?? date('Y-m-d H:i:s');
        $today  = substr($now, 0, 10);
        $config = Config::get();

        if (!self::shouldSendEod($config, $now)) {
            return ['due' => false, 'users' => 0, 'sent' => 0];
        }

        $raiser = $raiser ?? static function (int $usersId, int $anchorId, array $tags): bool {
            return self::raiseEod($anchorId, $tags);
        };

        $sent  = 0;
        $users = self::eodUsers($today);
        foreach ($users as $usersId) {
            $data = self::eodData($usersId, $now);
            if ($data['anchor'] <= 0) {
                continue; // dia zerado — "só envia se houver conteúdo"
            }
            if ($raiser($usersId, $data['anchor'], self::templateRows($data, $today)) === true) {
                $sent++;
            }
        }

        self::markEodSent($today);

        return ['due' => true, 'users' => count($users), 'sent' => $sent];
    }

    /**
     * Dispara o evento nativo. O item âncora vai RECARREGADO do banco
     * (getFromDB) — o destinatário AUTHOR lê o `users_id` da linha; as
     * listas prontas viajam nas $options e o target só repassa.
     */
    public static function raiseEod(int $anchorId, array $tags): bool
    {
        $item = new OccurrenceAlert();
        if ($anchorId <= 0 || !$item->getFromDB($anchorId)) {
            return false;
        }

        return (bool) NotificationEvent::raiseEvent(self::EVENT_END_OF_DAY, $item, ['eod' => $tags]);
    }

    // =====================================================================
    // 7b-2 — resumo matinal ao gestor (decisão nº 22)
    // =====================================================================

    /**
     * Mapa [gestor => [técnicos]] a cobrir hoje: régua INVERTIDA do
     * managersByOwner do 7a — parte dos técnicos COM CONTEÚDO (eodUsers:
     * qualquer ocorrência viva devida até hoje), sobe para os grupos
     * deles e desce para os `is_manager` desses grupos.
     *
     * Diferença deliberada para o sino (decisão nº 22): aqui NÃO há
     * exclusão do próprio — se o gestor é membro do grupo e tem
     * conteúdo, a linha dele entra no resumo que ele mesmo recebe
     * (é o painel matinal do setor, e ele faz parte do setor).
     *
     * Gestor sem nenhum técnico com conteúdo simplesmente não aparece
     * no mapa — "só envia se houver conteúdo".
     */
    public static function digestPairs(?string $today = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $withContent = self::eodUsers($today);
        if ($withContent === []) {
            return [];
        }

        // 1) grupos dos técnicos com conteúdo
        $groupsByTech = [];
        $allGroups    = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => ['glpi_groups_users.users_id' => $withContent ?: [0]],
        ]) as $row) {
            $uid = (int) ($row['users_id'] ?? 0);
            $gid = (int) ($row['groups_id'] ?? 0);
            if ($uid > 0 && $gid > 0) {
                $groupsByTech[$uid][$gid] = true;
                $allGroups[$gid] = true;
            }
        }
        if ($allGroups === []) {
            return [];
        }

        // 2) gestores desses grupos
        $managersByGroup = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => [
                'glpi_groups_users.groups_id'  => array_keys($allGroups) ?: [0],
                'glpi_groups_users.is_manager' => 1,
            ],
        ]) as $row) {
            $gid = (int) ($row['groups_id'] ?? 0);
            $mid = (int) ($row['users_id'] ?? 0);
            if ($gid > 0 && $mid > 0) {
                $managersByGroup[$gid][$mid] = true;
            }
        }

        // 3) inversão gestor → técnicos (SEM tirar o próprio)
        $out = [];
        foreach ($groupsByTech as $uid => $gids) {
            foreach (array_keys($gids) as $gid) {
                foreach (array_keys($managersByGroup[$gid] ?? []) as $mid) {
                    $out[$mid][$uid] = true;
                }
            }
        }

        ksort($out);
        foreach ($out as $mid => $set) {
            $ids = array_keys($set);
            sort($ids);
            $out[$mid] = $ids;
        }

        return $out;
    }

    /**
     * As linhas do resumo de UM gestor: uma por técnico com conteúdo,
     * com o trio agregado do fim de dia — reusa eodData, então a régua
     * é IDÊNTICA à do e-mail do técnico (pendência ativa na terceira
     * coluna; pendência expirada volta para aberta/atrasada; nativas
     * fora). Técnico zerado SOME (decisão nº 22).
     *
     * `anchor` = id da primeira ocorrência encontrada (percorrendo os
     * técnicos por id) — é o item em que o evento nativo é disparado;
     * o destinatário NÃO sai dele (vai nas $options — TARGET_MANAGER).
     * Linhas ordenadas por nome (desempate por id) para leitura.
     */
    public static function digestData(array $techIds, ?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');

        $names = Alerts::ownerNames(array_map(
            static fn ($id) => ['users_id' => (int) $id],
            $techIds
        ));

        $rows   = [];
        $anchor = 0;
        foreach ($techIds as $techId) {
            $techId = (int) $techId;
            $data   = self::eodData($techId, $now);

            $open    = count($data['open']);
            $overdue = count($data['overdue']);
            $pending = count($data['pending']);
            if ($open + $overdue + $pending === 0) {
                continue; // técnico zerado some da linha
            }
            if ($anchor <= 0 && $data['anchor'] > 0) {
                $anchor = (int) $data['anchor'];
            }

            $rows[] = [
                'users_id' => $techId,
                'name'     => (string) ($names[$techId] ?? ('#' . $techId)),
                'open'     => $open,
                'overdue'  => $overdue,
                'pending'  => $pending,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']) ?: ($a['users_id'] <=> $b['users_id']);
        });

        return ['rows' => $rows, 'anchor' => $anchor];
    }

    /**
     * Converte digestData no pacote de tags do motor do core: escalares
     * `##taskplus.digest_date##`/`##taskplus.tech_count##` + lista
     * `techs` com tags de linha `##digest.*##`. Contagens como STRING
     * (mesma razão da 7b-1) e texto PURO — o escape é do core (T28).
     */
    public static function templateRowsDigest(array $data, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');

        $techs = [];
        foreach (($data['rows'] ?? []) as $row) {
            $techs[] = [
                '##digest.tech##'    => trim((string) ($row['name'] ?? '')),
                '##digest.open##'    => (string) (int) ($row['open'] ?? 0),
                '##digest.overdue##' => (string) (int) ($row['overdue'] ?? 0),
                '##digest.pending##' => (string) (int) ($row['pending'] ?? 0),
            ];
        }

        $niceDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $today, $m)
            ? ($m[3] . '/' . $m[2] . '/' . $m[1])
            : $today;

        return [
            '##taskplus.digest_date##' => $niceDate,
            '##taskplus.tech_count##'  => (string) count($techs),
            'techs' => $techs,
        ];
    }

    /**
     * Rodada do resumo matinal: régua de horário → gestores com equipe
     * com conteúdo → um evento nativo POR GESTOR → trilha do dia.
     * Mesma filosofia do fim de dia: trilha carimbada SEMPRE que a
     * rodada era devida, mesmo com envio falho (uma tentativa por dia).
     *
     * $raiser (harness) recebe (managerId, anchorId, tags) e devolve
     * bool; NULL = raiseDigest real.
     *
     * Retorno: ['due' => bool, 'managers' => n, 'sent' => n].
     */
    public static function processDigest(?string $now = null, ?callable $raiser = null): array
    {
        $now    = $now ?? date('Y-m-d H:i:s');
        $today  = substr($now, 0, 10);
        $config = Config::get();

        if (!self::shouldSendDigest($config, $now)) {
            return ['due' => false, 'managers' => 0, 'sent' => 0];
        }

        $raiser = $raiser ?? static function (int $managerId, int $anchorId, array $tags): bool {
            return self::raiseDigest($managerId, $anchorId, $tags);
        };

        $sent  = 0;
        $pairs = self::digestPairs($today);
        foreach ($pairs as $managerId => $techIds) {
            $data = self::digestData($techIds, $now);
            if ($data['rows'] === [] || $data['anchor'] <= 0) {
                continue; // toda a equipe zerada — nada a enviar
            }
            $tags = self::templateRowsDigest($data, $today);
            if ($raiser((int) $managerId, (int) $data['anchor'], $tags) === true) {
                $sent++;
            }
        }

        self::markDigestSent($today);

        return ['due' => true, 'managers' => count($pairs), 'sent' => $sent];
    }

    /**
     * Dispara o evento nativo do resumo. O item âncora só resolve o
     * itemtype/template; o DESTINATÁRIO viaja em `digest_manager` nas
     * $options e é lido pelo addSpecificTargets do target (alvo
     * TARGET_MANAGER semeado pelo Install) — validado no fonte do core:
     * raiseEvent → NotificationEventMailing::raise → addForTarget($alvo,
     * $options) → default do switch → addSpecificTargets($alvo, $options).
     */
    public static function raiseDigest(int $managerId, int $anchorId, array $tags): bool
    {
        $item = new OccurrenceAlert();
        if ($managerId <= 0 || $anchorId <= 0 || !$item->getFromDB($anchorId)) {
            return false;
        }

        return (bool) NotificationEvent::raiseEvent(self::EVENT_MORNING_DIGEST, $item, [
            'digest'         => $tags,
            'digest_manager' => $managerId,
        ]);
    }
}
