<?php

namespace GlpiPlugin\Taskplus;

use CommonDBTM;
use Html;
use Notification;
use NotificationTarget;
use User;

/**
 * Task+ — target de notificação do itemtype OccurrenceAlert (Etapa 7a).
 *
 * Nome POR CONVENÇÃO do core: NotificationTarget::getInstanceClass()
 * resolve "GlpiPlugin\Taskplus\OccurrenceAlert" para esta classe
 * (prefixo NotificationTarget + basename, mesmo namespace). Renomear
 * qualquer um dos dois quebra o sino em silêncio.
 *
 * Eventos:
 *  - time_limit  → ocorrência de HOJE com horário-limite estourado e
 *    ainda aberta (7a; disparado pelo cron taskplusalerts via Alerts);
 *  - end_of_day  → fim de dia ao técnico (7b-1; disparado pelo mesmo
 *    cron via Emails, modo mailing — as listas prontas chegam nas
 *    $options['eod'] e este target só repassa ao motor de templates);
 *  - morning_digest → resumo matinal ao gestor (7b-2; as linhas prontas
 *    chegam nas $options['digest']).
 *
 * Destinatários:
 *  - time_limit/end_of_day: o RESPONSÁVEL — Notification::AUTHOR, que o
 *    addItemAuthor() do target base resolve lendo `users_id` da linha
 *    âncora;
 *  - morning_digest: o GESTOR — alvo CUSTOM Emails::TARGET_MANAGER.
 *    AUTHOR não serve (a âncora é ocorrência de um técnico); o core
 *    roteia items_id fora das constantes nativas para o
 *    addSpecificTargets DESTE target, que lê `digest_manager` das
 *    $options do raiseEvent (fluxo validado no fonte do 11.0.6).
 */
class NotificationTargetOccurrenceAlert extends NotificationTarget
{
    public function getEvents()
    {
        return [
            Alerts::EVENT_TIME_LIMIT      => __('Task+: horário-limite estourado', 'taskplus'),
            Emails::EVENT_END_OF_DAY      => __('Task+: fim de dia (e-mail ao técnico)', 'taskplus'),
            Emails::EVENT_MORNING_DIGEST  => __('Task+: resumo matinal (e-mail ao gestor)', 'taskplus'),
        ];
    }

    public function addNotificationTargets($entity)
    {
        $this->addTarget(Notification::AUTHOR, __('Responsável pela tarefa', 'taskplus'));
        $this->addTarget(Emails::TARGET_MANAGER, __('Gestor (resumo matinal)', 'taskplus'));
    }

    /**
     * Resolve o alvo CUSTOM do resumo matinal (7b-2). O addForTarget do
     * core (final) manda para cá todo items_id de USER_TYPE fora das
     * constantes nativas, com as MESMAS $options do raiseEvent — o
     * gestor destinatário viaja em `digest_manager` (Emails::raiseDigest
     * dispara um evento POR gestor). Mesmo formato de addToRecipientsList
     * do addItemAuthor do core (users_id + language).
     */
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== Emails::TARGET_MANAGER) {
            return;
        }

        $managerId = (int) ($options['digest_manager'] ?? 0);
        if ($managerId <= 0) {
            return;
        }

        $user = new User();
        if ($user->getFromDB($managerId)) {
            $this->addToRecipientsList([
                'language' => $user->getField('language'),
                'users_id' => $user->getField('id'),
            ]);
        }
    }

    /**
     * Tags disponíveis no modelo. `##taskplus.limit##` e `##taskplus.date##`
     * já saem formatados (HH:MM e via Html::convDate) — o modelo não
     * precisa saber o formato interno do banco.
     */
    public function addDataForTemplate($event, $options = [])
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $fields = ($this->obj instanceof CommonDBTM) ? $this->obj->fields : [];

        $this->data['##taskplus.url##'] =
            rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/' . Url::KEY . '/front/today.php';

        if ($event === Emails::EVENT_END_OF_DAY) {
            // 7b-1: as tags escalares e as três listas (open/overdue/
            // pending) já vêm PRONTAS de Emails::templateRows nas
            // $options — este target não remonta nada (a regra mora na
            // classe de domínio, exercitável por harness).
            $eod = $options['eod'] ?? [];
            if (is_array($eod)) {
                foreach ($eod as $key => $value) {
                    $this->data[$key] = $value;
                }
            }
        } elseif ($event === Emails::EVENT_MORNING_DIGEST) {
            // 7b-2: mesmo padrão — as linhas por técnico vêm prontas de
            // Emails::templateRowsDigest. O link do gestor é a tela
            // EQUIPE (não a Hoje).
            $this->data['##taskplus.team_url##'] =
                rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
                . '/plugins/' . Url::KEY . '/front/team.php';

            $digest = $options['digest'] ?? [];
            if (is_array($digest)) {
                foreach ($digest as $key => $value) {
                    $this->data[$key] = $value;
                }
            }
        } else {
            $limit = (string) ($fields['time_limit'] ?? '');
            $date  = (string) ($fields['date'] ?? '');

            $this->data['##taskplus.name##']        = (string) ($fields['name'] ?? '');
            $this->data['##taskplus.description##'] = (string) ($fields['description'] ?? '');
            $this->data['##taskplus.date##']        = ($date !== '') ? Html::convDate($date) : '';
            $this->data['##taskplus.limit##']       = ($limit !== '') ? substr($limit, 0, 5) : '';
        }

        $this->getTags();
    }

    public function getTags()
    {
        $tags = [
            'taskplus.name'          => __('Título da tarefa', 'taskplus'),
            'taskplus.description'   => __('Descrição', 'taskplus'),
            'taskplus.date'          => __('Dia da tarefa', 'taskplus'),
            'taskplus.limit'         => __('Horário-limite', 'taskplus'),
            'taskplus.url'           => __('Link da tela Hoje', 'taskplus'),
            // 7b-1 — fim de dia (escalares)
            'taskplus.eod_date'      => __('Data do fim de dia', 'taskplus'),
            'taskplus.open_count'    => __('Nº de abertas de hoje', 'taskplus'),
            'taskplus.overdue_count' => __('Nº de atrasadas', 'taskplus'),
            'taskplus.pending_count' => __('Nº de pendentes', 'taskplus'),
            // 7b-1 — tags de LINHA (dentro dos FOREACH)
            'eod.name'               => __('Título (linha do fim de dia)', 'taskplus'),
            'eod.limit'              => __('Horário-limite (linha)', 'taskplus'),
            'eod.date'               => __('Dia (linha; vazio = hoje)', 'taskplus'),
            'eod.origin'             => __('Autoria (linha; "criada pelo gestor")', 'taskplus'),
            'eod.pending'            => __('Pendência (linha; "volta em…")', 'taskplus'),
            // 7b-2 — resumo matinal (escalares)
            'taskplus.digest_date'   => __('Data do resumo matinal', 'taskplus'),
            'taskplus.tech_count'    => __('Nº de técnicos com conteúdo', 'taskplus'),
            'taskplus.team_url'      => __('Link da tela Equipe', 'taskplus'),
            // 7b-2 — tags de LINHA (dentro do FOREACHtechs)
            'digest.tech'            => __('Técnico (linha do resumo)', 'taskplus'),
            'digest.open'            => __('Abertas de hoje (linha)', 'taskplus'),
            'digest.overdue'         => __('Atrasadas (linha)', 'taskplus'),
            'digest.pending'         => __('Pendentes (linha)', 'taskplus'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        // Listas iteráveis (##FOREACHopen## … ##ENDFOREACHopen## etc.)
        foreach ([
            'open'    => __('Abertas de hoje', 'taskplus'),
            'overdue' => __('Atrasadas', 'taskplus'),
            'pending' => __('Pendentes', 'taskplus'),
            'techs'   => __('Técnicos do resumo matinal', 'taskplus'),
        ] as $tag => $label) {
            $this->addTagToList([
                'tag'     => $tag,
                'label'   => $label,
                'value'   => false,
                'foreach' => true,
            ]);
        }

        asort($this->tag_descriptions);
    }
}
