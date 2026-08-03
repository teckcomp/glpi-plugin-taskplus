<?php

/**
 * Task+ — tela "Hoje" (Etapa 0: casca vazia).
 *
 * O hub real (avulsas em 1 clique, grupos de rotinas, chips das origens
 * nativas) chega nas Etapas 1–3.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Today;
use GlpiPlugin\Taskplus\Url;

include('../../../inc/includes.php');

Access::require('task');

Html::header(
    __('Task+ — Hoje', 'taskplus'),
    '', // Html::header ignora o 2º argumento no GLPI 11 (lição do PP, Bloco 4a)
    'tools',
    Today::class
);

// Twig do GLPI é strict: TODA variável usada no template TEM que estar
// aqui, e `nav` traz TODAS as chaves sempre (Access::sidebar()).
TemplateRenderer::getInstance()->display(
    '@taskplus/today.html.twig',
    [
        'plugin_web_dir'  => Url::base(),
        'nav'             => Access::sidebar(),
        'current_user_id' => (int) Session::getLoginUserID(),
        'csrf_token'      => Session::getNewCSRFToken(),
    ]
);

Html::footer();
