<?php

/**
 * Task+ — tela "Quadro de Equipe" (13b: kanban por setor, tarefas com
 * vários colaboradores — decisões nº 65/66).
 *
 * Mesmo padrão do front/board.php: o controlador entrega TUDO pronto —
 * payload inicial embutido como JSON; o public/js/teamboard.js renderiza
 * e, depois de cada ação no ajax/teamboard.php, re-renderiza com o
 * payload da resposta. `?groups_id=N` abre direto num setor do escopo.
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\TeamBoard;
use GlpiPlugin\Taskplus\Today;
use GlpiPlugin\Taskplus\Url;

include('../../../inc/includes.php');

// Gate = o mesmo da sidebar: direito de tarefa E algum setor no escopo.
Access::require('task');
if (!Access::canTeamBoard()) {
    throw new AccessDeniedHttpException();
}

Html::header(
    __('Tarefas — Quadro de Equipe', 'taskplus'),
    '', // Html::header ignora o 2º argumento no GLPI 11 (lição do PP, Bloco 4a)
    'tools',
    Today::class
);

$payload = TeamBoard::payload(
    (int) Session::getLoginUserID(),
    (int) ($_GET['groups_id'] ?? 0)
);

// Twig do GLPI é strict: TODA variável usada no template TEM que estar
// aqui, e `nav` traz TODAS as chaves sempre (Access::sidebar()).
TemplateRenderer::getInstance()->display(
    '@taskplus/teamboard.html.twig',
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
