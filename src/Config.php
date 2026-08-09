<?php

namespace GlpiPlugin\Taskplus;

use Config as CoreConfig;

/**
 * Configuração do Task+.
 *
 * Usa a tabela glpi_configs nativa com context = 'plugin:taskplus'
 * (mecanismo padrão do GLPI — não cria tabela extra para isso).
 */
class Config
{
    public const CONTEXT = 'plugin:taskplus';

    public const DEFAULTS = [
        'email_enabled'      => 1, // 1 = envia e-mail além do sino (7b)
        'email_eod_time'     => '18:00', // horário do e-mail de fim de dia (7b-1)
        'purge_on_uninstall' => 0, // 1 = apaga tabelas/dados/direitos ao desinstalar
    ];

    /**
     * Lê a configuração mesclada com os padrões.
     */
    public static function get(): array
    {
        $values = CoreConfig::getConfigurationValues(self::CONTEXT);
        return array_merge(self::DEFAULTS, $values);
    }

    /**
     * Grava valores vindos do formulário.
     */
    public static function set(array $values): void
    {
        $clean = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (!isset($values[$key])) {
                continue;
            }
            // Tipo pelo DEFAULT: chave de horário ('HH:MM') valida o
            // formato e IGNORA lixo (mantém o valor vigente); o resto
            // continua inteiro >= 0. A trilha email_eod_last NÃO está
            // aqui de propósito — quem a escreve é Emails::markEodSent.
            if (is_string($default)) {
                $raw = trim((string) $values[$key]);
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw)) {
                    $clean[$key] = $raw;
                }
            } else {
                $clean[$key] = max(0, (int) $values[$key]);
            }
        }
        if ($clean) {
            CoreConfig::setConfigurationValues(self::CONTEXT, $clean);
        }
    }

    /**
     * URL desta tela. Sempre via Url::to — nunca $_SERVER['PHP_SELF'],
     * que no front controller do GLPI 11 vale sempre "/index.php".
     */
    public static function formUrl(): string
    {
        return Url::to('front/config.form.php');
    }

    // showForm() foi removido na Etapa 4c: a tela de Configurações agora
    // é o templates/config.html.twig (layout padrão do hub, com sidebar),
    // renderizado pelo front/config.form.php via TemplateRenderer.
}
