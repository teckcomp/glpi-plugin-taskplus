<?php

/**
 * Task+ — tela "Quadro" (Etapa 4d: kanban por fases).
 *
 * Mesmo padrão do front/today.php: o controlador entrega TUDO pronto —
 * o payload inicial vai embutido como JSON na página (o
 * public/js/board.js renderiza e, depois de cada ação no
 * ajax/board.php, re-renderiza com o payload da resposta).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Board;
use GlpiPlugin\Taskplus\Today;
use GlpiPlugin\Taskplus\Url;

include('../../../inc/includes.php');

Access::require('task');

Html::header(
    __('Task+ — Quadro', 'taskplus'),
    '', // Html::header ignora o 2º argumento no GLPI 11 (lição do PP, Bloco 4a)
    'tools',
    Today::class
);

$payload = Board::payload((int) Session::getLoginUserID());

// Twig do GLPI é strict: TODA variável usada no template TEM que estar
// aqui, e `nav` traz TODAS as chaves sempre (Access::sidebar()).
// JSON com HEX_TAG & cia: um "</script>" num nome de tarefa não pode
// quebrar a página (lição herdada do ProjectPlus).
TemplateRenderer::getInstance()->display(
    '@taskplus/board.html.twig',
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
