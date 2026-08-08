<?php

/**
 * Task+ — endpoint AJAX do Quadro (Etapa 4d).
 *
 * Contrato com o public/js/board.js (idêntico ao occurrence.php):
 *  - só POST, com `action` (set_phase|done|pending|list) e os campos;
 *  - o CORE do GLPI 11 valida o `_glpi_csrf_token` do POST sozinho
 *    (CSRF_COMPLIANT) — NUNCA Session::checkCSRF manual;
 *  - a resposta é SEMPRE JSON com: success, message, `csrf` (token NOVO,
 *    que o JS rotaciona — o do POST foi consumido) e `data` (payload
 *    completo e atualizado do quadro, para re-render).
 *
 * As regras de movimento (o que cada coluna aceita, escopo por setor)
 * são validadas DENTRO do Board::handle — o JS só espelha para UX.
 */

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Board;

include('../../../inc/includes.php');

Access::require('task');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

$usersId = (int) Session::getLoginUserID();
$action  = (string) ($_POST['action'] ?? '');

$result = Board::handle($action, $_POST, $usersId);

// Token novo para o JS rotacionar + estado atualizado para re-render
$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = Board::payload($usersId);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
