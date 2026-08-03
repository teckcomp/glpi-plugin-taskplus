<?php

namespace GlpiPlugin\Taskplus;

use CronTask;

/**
 * Task+ — tarefas automáticas (registradas em Install::install()).
 *
 * Etapa 0: os dois crons existem e rodam sem fazer nada (retorno 0 =
 * "nada a fazer"), para que o registro, o agendamento e o ciclo
 * desinstalar/reinstalar sejam testados desde já.
 *
 *  - taskplusgen    → Etapa 2: materializa as ocorrências do dia a partir
 *                     das rotinas ativas, de forma idempotente (a UNIQUE
 *                     `routine_day` garante que rodar 2x não duplica);
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
     * Gerador de ocorrências (lógica na Etapa 2).
     */
    public static function cronTaskplusgen(CronTask $task): int
    {
        $task->log('Task+ Etapa 0: gerador registrado, lógica chega na Etapa 2.');
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
