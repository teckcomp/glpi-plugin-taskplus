<?php

/**
 * Task+ — formulário de configuração.
 */

use GlpiPlugin\Taskplus\Config as PluginConfig;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    PluginConfig::set($_POST);
    Session::addMessageAfterRedirect(__('Configuração salva', 'taskplus'), true, INFO);
    Html::back();
}

// 2º argumento vazio: o Html::header do GLPI 11 ignora o parâmetro $url —
// quem posiciona o menu são os argumentos 3 e 4 ($sector e $item).
Html::header(__('Task+', 'taskplus'), '', 'config', 'plugins');
PluginConfig::showForm();
Html::footer();
