<?php

/**
 * Task+ — endpoint AJAX das tarefas avulsas/ocorrências (Etapa 1).
 *
 * Contrato com o public/js/taskplus.js:
 *  - só POST, com `action` (add|update|delete|toggle|list) e os campos;
 *  - o CORE do GLPI 11 valida o `_glpi_csrf_token` do POST sozinho
 *    (CSRF_COMPLIANT) — NUNCA Session::checkCSRF manual;
 *  - a resposta é SEMPRE JSON com: success, message, `csrf` (token NOVO,
 *    que o JS rotaciona — o do POST foi consumido) e `data` (payload
 *    completo e atualizado da tela Hoje, para re-render).
 *
 * Lembrete de teste: endpoints ajax/ não respondem pela URL direta no
 * navegador (o roteador devolve "Item requisitado não encontrado") —
 * teste sempre via fetch/tela.
 */

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Occurrence;
use GlpiPlugin\Taskplus\Tickets;

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

$result = Occurrence::handle($action, $_POST, $usersId);

// 5b-2 p2: o período ativo viaja em TODO POST (o JS o anexa) — o
// payload de resposta precisa devolver o MESMO recorte que o usuário
// está vendo. A normalização (data inválida, par invertido) é do
// próprio payload; aqui só se repassa o que veio.
$pf = isset($_POST['period_from']) ? (string) $_POST['period_from'] : null;
$pt = isset($_POST['period_to']) ? (string) $_POST['period_to'] : null;

// Token novo para o JS rotacionar + estado atualizado para re-render
$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = Occurrence::payload($usersId, $pf, $pt);

// Etapa 8a: a coluna Chamados acompanha todo re-render da tela Hoje.
// Mesma chave e mesmo guarda do front/today.php.
$result['data']['tickets'] = [];
try {
    $result['data']['tickets'] = Tickets::forUser($usersId);
} catch (\Throwable $e) {
    // origem indisponível: re-render segue sem a coluna preenchida
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
