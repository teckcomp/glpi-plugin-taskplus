<?php

/**
 * Task+ — endpoint AJAX das fases do quadro (Etapa 4c).
 *
 * Contrato com o public/js/config.js (idêntico ao routine.php):
 *  - só POST, com `action` (add|update|delete|move|list) e os campos;
 *  - o CORE do GLPI 11 valida o `_glpi_csrf_token` do POST sozinho
 *    (CSRF_COMPLIANT) — NUNCA Session::checkCSRF manual;
 *  - a resposta é SEMPRE JSON com: success, message, `csrf` (token NOVO,
 *    que o JS rotaciona) e `data` (payload atualizado das fases).
 *
 * As fases são configuração GLOBAL do plugin, então o gate é o mesmo da
 * tela de Configurações: direito NATIVO `config` UPDATE — e não os
 * direitos do plugin. Num endpoint ajax a negativa responde JSON 403
 * (Html::displayRightError devolveria HTML para o fetch).
 */

use GlpiPlugin\Taskplus\Phase;

include('../../../inc/includes.php');

if (!Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => __('Sem permissão', 'taskplus')]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

$usersId = (int) Session::getLoginUserID();
$action  = (string) ($_POST['action'] ?? '');

$result = Phase::handle($action, $_POST, $usersId);

// Token novo para o JS rotacionar + estado atualizado para re-render
$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = Phase::payload();

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
