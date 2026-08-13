<?php

/**
 * Task+ — endpoint AJAX da tela Painel (Etapa 6b).
 *
 * Contrato com o public/js/panel.js:
 *  - só POST, com `action` (por ora só `list`) e `period_from`/
 *    `period_to` opcionais (mesmos nomes do 5b-2 p2 — a régua do
 *    recorte é UMA só; bordas vazias caem no default de 90 dias do
 *    Panel::panelRange, que também aplica o teto de 180);
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
use GlpiPlugin\Taskplus\Panel;

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

$result = Panel::handle($action, $_POST, $usersId);

// O período viaja em TODO POST (o JS o anexa) — a resposta devolve o
// MESMO recorte que o usuário pediu. A normalização (data inválida,
// par invertido, default de 90, teto de 180) é do panelRange.
$pf = isset($_POST['period_from']) ? (string) $_POST['period_from'] : null;
$pt = isset($_POST['period_to']) ? (string) $_POST['period_to'] : null;

// 6b-2: alvo do "Ver painel de:" — tipo + id. 'self' (ou ausente) =
// painel pessoal; 'user' + users_id = painel de um técnico; 'team' +
// groups_id (0 = todos os setores) = agregado da equipe. O escopo NÃO é
// do front: o Panel::payload revalida o par contra os setores
// administrados a cada POST (T18) e, se não puder, devolve o painel do
// próprio com `view.denied`.
$vk = (string) ($_POST['view_kind'] ?? 'self');
$vi = (int) ($_POST['view_id'] ?? 0);

// Token novo para o JS rotacionar + estado atualizado para re-render
$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = Panel::payload($usersId, $pf, $pt, $vk, $vi);

// Alvo recusado: a resposta traz o painel pessoal, mas o gestor precisa
// saber POR QUE a tela voltou para ele (mesma mensagem neutra da Equipe).
if (!empty($result['data']['view']['denied'])) {
    $result['success'] = false;
    $result['message'] = __('Alvo fora dos setores que você administra', 'taskplus');
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
