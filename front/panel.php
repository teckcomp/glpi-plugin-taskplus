<?php

/**
 * Task+ — tela "Painel" (Etapa 6b: indicadores pessoais).
 *
 * Mesmo padrão do front/week.php: o controlador entrega TUDO pronto —
 * o payload inicial (últimos 90 dias, o default do panelRange) vai
 * embutido como JSON na página; o public/js/panel.js renderiza e, a
 * cada mudança de período no ajax/panel.php, re-renderiza com o
 * payload da resposta.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Panel;
use GlpiPlugin\Taskplus\Today;
use GlpiPlugin\Taskplus\Url;

include('../../../inc/includes.php');

Access::require('task');

Html::header(
    __('Task+ — Painel', 'taskplus'),
    '', // Html::header ignora o 2º argumento no GLPI 11 (lição do PP, Bloco 4a)
    'tools',
    Today::class
);

$payload = Panel::payload((int) Session::getLoginUserID());

// Twig do GLPI é strict: TODA variável usada no template TEM que estar
// aqui, e `nav` traz TODAS as chaves sempre (Access::sidebar()).
// JSON com HEX_TAG & cia: um "</script>" num nome de tarefa não pode
// quebrar a página (lição herdada do ProjectPlus).
TemplateRenderer::getInstance()->display(
    '@taskplus/panel.html.twig',
    [
        'plugin_web_dir'  => Url::base(),
        'plugin_version'  => PLUGIN_TASKPLUS_VERSION,
        'nav'             => Access::sidebar(),
        'current_user_id' => (int) Session::getLoginUserID(),
        'csrf_token'      => Session::getNewCSRFToken(),
        'payload_json'    => json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ),
    ]
);

Html::footer();
