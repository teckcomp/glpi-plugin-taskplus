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
 *  - taskplusalerts → Etapa 7a (ENTREGUE): sino de horário-limite
 *                     estourado (régua e trilha em Alerts.php);
 *                     7b-1 (ENTREGUE): e-mail de fim de dia ao técnico;
 *                     7b-2 (ENTREGUE): resumo matinal ao gestor
 *                     (réguas e conteúdo em Emails.php).
 */
class Cron
{
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'taskplusgen' => [
                'description' => __('Tarefas: gera as ocorrências do dia (rotinas)', 'taskplus'),
            ],
            'taskplusalerts' => [
                'description' => __('Tarefas: alertas de horário-limite e pendências', 'taskplus'),
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
            'Tarefas %s: %d rotina(s) ativa(s), %d ocorrencia(s) criada(s), %d ja existente(s).',
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
     * Alertas (Etapa 7a: horário-limite estourado → sino).
     *
     * Só orquestra: a régua de "quem alerta" (hoje + limite estourado +
     * viva + sem pendência ativa + sem alerta prévio) e a trilha
     * `date_alert_limit` moram em Alerts::process() — exercitável por
     * harness, o que este método não seria.
     *
     * Frequência fina (10 min no Install): alerta de horário-limite com
     * até 1h de atraso não serve. Idempotente por construção — a trilha
     * garante UMA tentativa por ocorrência, rode o cron quantas vezes for.
     */
    public static function cronTaskplusalerts(CronTask $task): int
    {
        $result = Alerts::process();

        $task->log(sprintf(
            'Tarefas alertas: %d candidata(s) de horario-limite, %d no sino, %d via navegador.',
            $result['candidates'],
            $result['bell'],
            $result['raised']
        ));

        // 7b-1: fim de dia ao técnico. A régua (horário configurado +
        // trilha "já enviei hoje" + só com conteúdo) mora em
        // Emails::processEod — aqui só o log. O envio real é ASSÍNCRONO:
        // o raiseEvent enfileira em glpi_queuednotifications e o cron
        // nativo `queuednotification` despacha.
        $emails = Emails::processEod();
        if ($emails['due']) {
            $task->log(sprintf(
                'Tarefas fim de dia: %d tecnico(s) com tarefas vivas, %d e-mail(s) enfileirado(s).',
                $emails['users'],
                $emails['sent']
            ));
        }

        // 7b-2: resumo matinal ao gestor — mesma mecânica (régua de
        // horário + trilha diária em Emails::processDigest; o envio real
        // é a mesma fila assíncrona do fim de dia).
        $digest = Emails::processDigest();
        if ($digest['due']) {
            $task->log(sprintf(
                'Tarefas resumo matinal: %d gestor(es) com equipe, %d e-mail(s) enfileirado(s).',
                $digest['managers'],
                $digest['sent']
            ));
        }

        $volume = $result['candidates'] + $emails['sent'] + $digest['sent'];
        if ($volume > 0) {
            $task->addVolume($volume);
            return 1;
        }

        return 0; // nada a fazer
    }
}
