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
 *  - 7b-1 (AQUI): fim de dia ao TÉCNICO — o que ficou aberto de hoje,
 *    atrasado de dias anteriores e pendente (adiado com motivo/data/hora),
 *    só do Task+ (nativas FORA — decisão nº 3, mesma régua do 7a) e
 *    concluídas FORA (o e-mail é chamada para ação, não diário de bordo);
 *  - 7b-2 (próximo bloco): resumo matinal ao GESTOR.
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

    /** Chave da trilha "já enviei hoje" no contexto de Config do plugin. */
    public const LAST_EOD_KEY = 'email_eod_last';

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
}
