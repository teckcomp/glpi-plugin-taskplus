<?php

/**
 * Task+ — tela "Configurações" (reescrita na Etapa 4c).
 *
 * Até o 4b era uma tabela solta (Config::showForm). Agora segue o mesmo
 * padrão do front/today.php: sidebar do hub + área principal, com o
 * formulário de preferências (POST clássico) e o CRUD de fases do quadro
 * (payload embutido + public/js/config.js + ajax/phase.php).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Config as PluginConfig;
use GlpiPlugin\Taskplus\Phase;
use GlpiPlugin\Taskplus\Url;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    PluginConfig::set($_POST);
    Session::addMessageAfterRedirect(__('Configuração salva', 'taskplus'), true, INFO);
    Html::back();
}

// 2º argumento vazio: o Html::header do GLPI 11 ignora o parâmetro $url —
// quem posiciona o menu são os argumentos 3 e 4 ($sector e $item).
Html::header(__('Task+ — Configurações', 'taskplus'), '', 'config', 'plugins');

$config = PluginConfig::get();

// Twig do GLPI é strict: TODA variável usada no template TEM que estar
// aqui, e `nav` traz TODAS as chaves sempre (Access::sidebar()).
// Dois tokens CSRF de propósito: `form_csrf` vai no hidden do POST
// clássico e `csrf_token` alimenta o JS do CRUD de fases (rotacionado a
// cada resposta do ajax) — no GLPI 11 vários tokens coexistem válidos.
TemplateRenderer::getInstance()->display(
    '@taskplus/config.html.twig',
    [
        'plugin_web_dir'     => Url::base(),
        'plugin_version'     => PLUGIN_TASKPLUS_VERSION,
        'nav'                => Access::sidebar(),
        'form_csrf'          => Session::getNewCSRFToken(),
        'csrf_token'         => Session::getNewCSRFToken(),
        'email_enabled'      => (int) $config['email_enabled'],
        'purge_on_uninstall' => (int) $config['purge_on_uninstall'],
        'payload_json'       => json_encode(
            Phase::payload(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ),
    ]
);

Html::footer();
