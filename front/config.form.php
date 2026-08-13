<?php

/**
 * Task+ — tela "Configurações" (reescrita na Etapa 4c; escopo por setor
 * na 4c-2).
 *
 * Mesmo padrão do front/today.php: sidebar do hub + área principal, com
 * o formulário de preferências (POST clássico, SÓ ADMIN) e o CRUD de
 * fases do quadro (payload embutido + public/js/config.js +
 * ajax/phase.php — admin mexe em tudo, gestor de setor só nos seus).
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Config as PluginConfig;
use GlpiPlugin\Taskplus\Phase;
use GlpiPlugin\Taskplus\Url;

include('../../../inc/includes.php');

// Gate da 4c-2: admin (config UPDATE) OU gestor de setor (direito
// plugin_taskplus_manage + is_manager em algum grupo). Mesma regra do
// ajax/phase.php e da flag nav.config (Access::canConfigPhases).
$usersId = (int) Session::getLoginUserID();
$isAdmin = Access::isPhaseAdmin();
$managed = Access::managedGroups($usersId, $isAdmin);

// 9c: exceção do core no lugar do Html::displayRightError() deprecado —
// mesmo resultado, sem a linha de deprecação no php-errors.log.
if (!$isAdmin && (!Session::haveRight('plugin_taskplus_manage', READ) || $managed === [])) {
    throw new AccessDeniedHttpException();
}

if (isset($_POST['update'])) {
    // Preferências continuam EXCLUSIVAS do admin — o gestor entra na
    // tela só pelas fases (o template nem renderiza o form para ele,
    // mas o POST é validado de novo aqui: JS/HTML nunca é a proteção).
    Session::checkRight('config', UPDATE);
    PluginConfig::set($_POST);
    Session::addMessageAfterRedirect(__('Configuração salva', 'taskplus'), true, INFO);
    Html::back();
}

// 2º argumento vazio: o Html::header do GLPI 11 ignora o parâmetro $url —
// quem posiciona o menu são os argumentos 3 e 4 ($sector e $item).
Html::header(__('Tarefas — Configurações', 'taskplus'), '', 'config', 'plugins');

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
        'is_admin'           => (bool) $isAdmin,
        'form_csrf'          => Session::getNewCSRFToken(),
        'csrf_token'         => Session::getNewCSRFToken(),
        'email_enabled'      => (int) $config['email_enabled'],
        'email_eod_time'     => (string) $config['email_eod_time'],
        'email_digest_time'  => (string) $config['email_digest_time'],
        'purge_on_uninstall' => (int) $config['purge_on_uninstall'],
        'payload_json'       => json_encode(
            Phase::payload($isAdmin, $managed),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ),
    ]
);

Html::footer();
