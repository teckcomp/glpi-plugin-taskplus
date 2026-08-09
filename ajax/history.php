<?php

/**
 * Task+ — endpoint AJAX da tela Histórico (Etapa 6c-1).
 *
 * Contrato com o public/js/history.js:
 *  - só POST, com `action` (`list` e, desde a 6c-2, `restore` com `id`
 *    — posse e estado revalidados no History::restore a cada POST, T18)
 *    e `period_from`/`period_to` opcionais (mesmos nomes
 *    do 5b-2 p2 — a régua do recorte é UMA só; bordas vazias caem no
 *    default de 30 dias do History::historyRange, que também aplica o
 *    teto de 180);
 *  - o CORE do GLPI 11 valida o `_glpi_csrf_token` do POST sozinho
 *    (CSRF_COMPLIANT) — NUNCA Session::checkCSRF manual;
 *  - a resposta é SEMPRE JSON com: success, message, `csrf` (token NOVO,
 *    que o JS rotaciona — o do POST foi consumido) e `data` (payload
 *    completo do período pedido, para re-render).
 *
 * Lembrete de teste: endpoints ajax/ não respondem pela URL direta no
 * navegador (o roteador devolve "Item requisitado não encontrado") —
 * teste sempre via fetch/tela.
 */

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\History;

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

$result = History::handle($action, $_POST, $usersId);

// O período viaja em TODO POST (o JS o anexa) — a resposta devolve o
// MESMO recorte que o usuário pediu. A normalização (data inválida,
// par invertido, default de 30, teto de 180) é do historyRange.
$pf = isset($_POST['period_from']) ? (string) $_POST['period_from'] : null;
$pt = isset($_POST['period_to']) ? (string) $_POST['period_to'] : null;

// Token novo para o JS rotacionar + estado atualizado para re-render
$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = History::payload($usersId, $pf, $pt);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
