<?php

namespace GlpiPlugin\Taskplus;

use Config as CoreConfig;
use Session;

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
        'email_enabled'      => 1, // 1 = envia e-mail além do sino (Etapa 6)
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
            if (isset($values[$key])) {
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

    /**
     * Formulário simples de configuração.
     */
    public static function showForm(): void
    {
        Session::checkRight('config', UPDATE);

        $config = self::get();
        $action = htmlspecialchars(self::formUrl());

        echo "<form method='post' action='{$action}'>";
        echo '<table class="tab_cadre_fixe">';
        echo '<tr><th colspan="2">' . __('Configuração do Task+', 'taskplus') . '</th></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Enviar e-mails (além do sino)', 'taskplus')
            . '</td><td>';
        echo "<select name='email_enabled'>";
        echo "<option value='1'" . ($config['email_enabled'] ? ' selected' : '') . '>'
            . __('Yes') . '</option>';
        echo "<option value='0'" . (!$config['email_enabled'] ? ' selected' : '') . '>'
            . __('No') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Apagar tabelas, dados e direitos ao desinstalar', 'taskplus')
            . '</td><td>';
        echo "<select name='purge_on_uninstall'>";
        echo "<option value='0'" . (!$config['purge_on_uninstall'] ? ' selected' : '') . '>'
            . __('No') . '</option>';
        echo "<option value='1'" . ($config['purge_on_uninstall'] ? ' selected' : '') . '>'
            . __('Yes') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_2"><td colspan="2" class="center">';
        echo "<input type='submit' name='update' value='" . _sx('button', 'Save') . "' class='btn btn-primary'>";
        echo '</td></tr>';

        echo '</table>';
        // O core injeta e valida o token CSRF do POST sozinho (GLPI 11) —
        // Html::closeForm cuida do hidden. NUNCA Session::checkCSRF manual.
        \Html::closeForm();
    }
}
