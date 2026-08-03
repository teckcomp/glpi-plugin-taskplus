<?php

namespace GlpiPlugin\Taskplus;

use CronTask;

/**
 * Task+ — tarefas automáticas (registradas em Install::install()).
 *
 *  - taskplusgen    → Etapa 2b (ENTREGUE): materializa as ocorrências do
 *                     dia a partir das rotinas ativas, de forma idempotente
 *                     (a UNIQUE `routine_day` garante que rodar 2x não
 *                     duplica);
 *  - taskplusalerts → Etapa 6: sino + e-mail de horário-limite estourado,
 *                     fim de dia com pendências e resumo ao gestor.
 */
class Cron
{
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'taskplusgen' => [
                'description' => __('Task+: gera as ocorrências do dia (rotinas)', 'taskplus'),
            ],
            'taskplusalerts' => [
                'description' => __('Task+: alertas de horário-limite e pendências', 'taskplus'),
            ],
            default => [],
        };
    }

    /**
     * Gerador de ocorrências (Etapa 2b).
     *
     * Só orquestra: a regra "esta rotina gera hoje?" mora em
     * Routine::isDueOn() e a materialização em Routine::generateForDate()
     * — ambas exercitáveis por harness, o que este método não seria.
     *
     * Roda de hora em hora (Install.php): rotina criada às 10h precisa
     * aparecer na tela Hoje do MESMO dia. A checagem prévia de ocorrência
     * existente (apoiada na UNIQUE `routine_day`) garante que rodar N
     * vezes ao dia não duplica nada.
     *
     * Retorno do GLPI: 1 = fez algo, 0 = nada a fazer.
     */
    public static function cronTaskplusgen(CronTask $task): int
    {
        $date   = date('Y-m-d');
        $result = Routine::generateForDate($date);

        $task->log(sprintf(
            'Task+ %s: %d rotina(s) ativa(s), %d ocorrencia(s) criada(s), %d ja existente(s).',
            $date,
            $result['routines'],
            $result['created'],
            $result['skipped']
        ));

        if ($result['created'] > 0) {
            $task->addVolume($result['created']);
            return 1;
        }

        return 0; // nada a fazer
    }

    /**
     * Alertas (lógica na Etapa 6).
     */
    public static function cronTaskplusalerts(CronTask $task): int
    {
        $task->log('Task+ Etapa 0: alertas registrados, lógica chega na Etapa 6.');
        return 0; // nada a fazer
    }
}
