<?php

/**
 * Task+ — endpoint do SINO de alertas (Etapa 7a; padrão do ProjectPlus).
 *
 * Contrato com o public/js/bell.js (carregado em TODAS as telas do hub
 * pela sidebar — por isso este endpoint é AUTOSSUFICIENTE em CSRF):
 *
 *  - GET  action=list            → payload do sino (leitura pura). A
 *    resposta TRAZ um token novo em `csrf`: é daqui que o widget obtém
 *    o primeiro token para os POSTs — o sino não depende de token
 *    embutido por tela (a sidebar não recebe csrf_token de nenhum
 *    controlador, de propósito: zero mudança nos 8 front/).
 *  - POST action=read (com id)   → marca UM alerta como lido; posse
 *    revalidada no WHERE (users_id — T18): id alheio é no-op silencioso.
 *  - POST action=read_all        → marca todos os não lidos.
 *
 * O CORE do GLPI 11 valida o `_glpi_csrf_token` do POST sozinho
 * (CSRF_COMPLIANT) — NUNCA Session::checkCSRF manual. Toda resposta
 * devolve token novo em `csrf` (uso único — o JS rotaciona) e o payload
 * completo do sino em `data` (re-render integral de graça).
 *
 * Lembrete de teste: endpoints ajax/ não respondem pela URL direta no
 * navegador (o roteador devolve "Item requisitado não encontrado") —
 * teste sempre via fetch/tela.
 */

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Alerts;

include('../../../inc/includes.php');

Access::require('task');

header('Content-Type: application/json; charset=UTF-8');

$usersId = (int) Session::getLoginUserID();
$method  = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'list');
    // GET é leitura pura: qualquer ação vira 'list'
    $result = Alerts::handle('list', [], $usersId);
} elseif ($method === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $result = Alerts::handle($action, $_POST, $usersId);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET or POST only']);
    exit;
}

$result['csrf'] = Session::getNewCSRFToken();

echo json_encode(
    $result,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
