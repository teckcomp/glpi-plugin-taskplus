<?php

/**
 * Task+ — tela "Equipe" (Etapa 5a: leitura para o gestor).
 *
 * Mesmo padrão do front/today.php: o controlador entrega TUDO pronto —
 * o payload inteiro vai embutido como JSON na página e o
 * public/js/team.js renderiza. Desde a 5b-1 a tela também AGE: emite o
 * token CSRF inicial e o JS conversa com ajax/team.php (rotação de
 * token a cada resposta).
 *
 * O gate NÃO é o Access::require('task') das outras telas: Equipe é do
 * gestor/admin (Access::canTeam), e um admin sem o direito de tarefa
 * também entra — mesma lógica do gate de Configurações (4c-2).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Team;
use GlpiPlugin\Taskplus\Today;
use GlpiPlugin\Taskplus\Url;

include('../../../inc/includes.php');

if (!Access::canTeam()) {
    Html::displayRightError();
}

Html::header(
    __('Task+ — Equipe', 'taskplus'),
    '', // Html::header ignora o 2º argumento no GLPI 11 (lição do PP, Bloco 4a)
    'tools',
    Today::class
);

$payload = Team::payload((int) Session::getLoginUserID());

// Twig do GLPI é strict: TODA variável usada no template TEM que estar
// aqui, e `nav` traz TODAS as chaves sempre (Access::sidebar()).
// JSON com HEX_TAG & cia: um "</script>" num nome de tarefa não pode
// quebrar a página (lição herdada do ProjectPlus).
TemplateRenderer::getInstance()->display(
    '@taskplus/team.html.twig',
    [
        'plugin_web_dir'  => Url::base(),
        'plugin_version'  => PLUGIN_TASKPLUS_VERSION,
        'nav'             => Access::sidebar(),
        'current_user_id' => (int) Session::getLoginUserID(),
        // 5b-1: token inicial das ações do gestor (o JS rotaciona a cada
        // resposta do ajax/team.php)
        'csrf_token'      => Session::getNewCSRFToken(),
        'payload_json'    => json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ),
    ]
);

Html::footer();
