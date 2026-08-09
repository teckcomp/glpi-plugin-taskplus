<?php

/**
 * Task+ — endpoint AJAX da tela Semana (Etapa 6a).
 *
 * Contrato com o public/js/week.js:
 *  - só POST, com `action` (por ora só `list`) e `anchor` opcional
 *    (qualquer data 'Y-m-d' dentro da semana desejada — o JS manda o
 *    `week.prev`/`week.next` ecoado pelo payload anterior);
 *  - o CORE do GLPI 11 valida o `_glpi_csrf_token` do POST sozinho
 *    (CSRF_COMPLIANT) — NUNCA Session::checkCSRF manual;
 *  - a resposta é SEMPRE JSON com: success, message, `csrf` (token NOVO,
 *    que o JS rotaciona — o do POST foi consumido) e `data` (payload
 *    completo da semana pedida, para re-render).
 *
 * Lembrete de teste: endpoints ajax/ não respondem pela URL direta no
 * navegador (o roteador devolve "Item requisitado não encontrado") —
 * teste sempre via fetch/tela.
 */

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Week;

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

$result = Week::handle($action, $_POST, $usersId);

// A âncora viaja em TODO POST (o JS a anexa quando não está na semana
// corrente) — a resposta devolve a MESMA semana que o usuário pediu.
// A normalização (data inválida cai na semana corrente) é do weekRange.
$anchor = isset($_POST['anchor']) ? (string) $_POST['anchor'] : null;

// Token novo para o JS rotacionar + estado atualizado para re-render
$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = Week::payload($usersId, $anchor);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
