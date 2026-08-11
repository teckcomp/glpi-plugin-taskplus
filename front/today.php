<?php

/**
 * Task+ — tela "Hoje" (Etapa 1: avulsas + check 1 clique + KPIs).
 *
 * O controlador entrega TUDO pronto: o payload inicial vai embutido como
 * JSON na página (o public/js/taskplus.js renderiza e, depois de cada
 * ação no ajax/occurrence.php, re-renderiza com o payload da resposta).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Occurrence;
use GlpiPlugin\Taskplus\Tickets;
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

$payload = Occurrence::payload((int) Session::getLoginUserID());

// Etapa 8a: coluna 1 — chamados do usuário (atribuído/observador/
// requerente). Chave própria, FORA do Occurrence::payload de propósito:
// Team/Board/Week o reusam e não podem pagar esta consulta por técnico.
// Falha de leitura não derruba a tela (mesma filosofia das nativas).
$payload['tickets'] = [];
try {
    $payload['tickets'] = Tickets::forUser((int) Session::getLoginUserID());
} catch (\Throwable $e) {
    // origem indisponível: a tela segue sem a coluna preenchida
}

// Twig do GLPI é strict: TODA variável usada no template TEM que estar
// aqui, e `nav` traz TODAS as chaves sempre (Access::sidebar()).
// JSON com HEX_TAG & cia: um "</script>" num nome de tarefa não pode
// quebrar a página (lição herdada do ProjectPlus).
TemplateRenderer::getInstance()->display(
    '@taskplus/today.html.twig',
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
