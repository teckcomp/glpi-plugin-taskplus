<?php

namespace GlpiPlugin\Taskplus;

use CommonDBTM;

/**
 * Task+ — "itemtype" de notificação de uma ocorrência (Etapa 7a).
 *
 * O mecanismo de notificação do GLPI (NotificationEvent::raiseEvent)
 * exige um CommonDBTM: é dele que vêm o tipo (casa com a coluna
 * `itemtype` de glpi_notifications), os campos para o template e o
 * destinatário (o addItemAuthor() do target base lê `users_id`).
 *
 * A classe de domínio Occurrence é PROPOSITALMENTE uma classe simples
 * (payload/handle testáveis por harness) — em vez de convertê-la em
 * CommonDBTM só por causa do sino, esta casca fina mapeia a MESMA
 * tabela e existe apenas para o ciclo de notificação. Nenhuma escrita
 * passa por aqui.
 *
 * Convenção do core (NotificationTarget::getInstanceClass): o target
 * deste itemtype é a classe irmã NotificationTargetOccurrenceAlert.
 */
class OccurrenceAlert extends CommonDBTM
{
    /**
     * CommonDBTM deduziria "glpi_plugin_taskplus_occurrencealerts" do
     * nome da classe; a tabela real é a de ocorrências.
     */
    public static function getTable($classname = null)
    {
        return Occurrence::TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return __('Tarefa do plugin Tarefas', 'taskplus');
    }
}
