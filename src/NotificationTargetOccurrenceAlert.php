<?php

namespace GlpiPlugin\Taskplus;

use CommonDBTM;
use Html;
use Notification;
use NotificationTarget;

/**
 * Task+ — target de notificação do itemtype OccurrenceAlert (Etapa 7a).
 *
 * Nome POR CONVENÇÃO do core: NotificationTarget::getInstanceClass()
 * resolve "GlpiPlugin\Taskplus\OccurrenceAlert" para esta classe
 * (prefixo NotificationTarget + basename, mesmo namespace). Renomear
 * qualquer um dos dois quebra o sino em silêncio.
 *
 * Evento desta etapa:
 *  - time_limit → ocorrência de HOJE com horário-limite estourado e
 *    ainda aberta (disparado pelo cron taskplusalerts via Alerts).
 *
 * Destinatário: só o RESPONSÁVEL faz sentido — Notification::AUTHOR,
 * que o addItemAuthor() do target base resolve lendo `users_id` da
 * própria linha (a tabela de ocorrências tem essa coluna).
 *
 * Os eventos de e-mail (fim de dia, resumo ao gestor) chegam no 7b.
 */
class NotificationTargetOccurrenceAlert extends NotificationTarget
{
    public function getEvents()
    {
        return [
            Alerts::EVENT_TIME_LIMIT => __('Task+: horário-limite estourado', 'taskplus'),
        ];
    }

    public function addNotificationTargets($entity)
    {
        $this->addTarget(Notification::AUTHOR, __('Responsável pela tarefa', 'taskplus'));
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

        $limit = (string) ($fields['time_limit'] ?? '');
        $date  = (string) ($fields['date'] ?? '');

        $this->data['##taskplus.name##']        = (string) ($fields['name'] ?? '');
        $this->data['##taskplus.description##'] = (string) ($fields['description'] ?? '');
        $this->data['##taskplus.date##']        = ($date !== '') ? Html::convDate($date) : '';
        $this->data['##taskplus.limit##']       = ($limit !== '') ? substr($limit, 0, 5) : '';
        $this->data['##taskplus.url##']         =
            rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/plugins/' . Url::KEY . '/front/today.php';

        $this->getTags();
    }

    public function getTags()
    {
        $tags = [
            'taskplus.name'        => __('Título da tarefa', 'taskplus'),
            'taskplus.description' => __('Descrição', 'taskplus'),
            'taskplus.date'        => __('Dia da tarefa', 'taskplus'),
            'taskplus.limit'       => __('Horário-limite', 'taskplus'),
            'taskplus.url'         => __('Link da tela Hoje', 'taskplus'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        asort($this->tag_descriptions);
    }
}
