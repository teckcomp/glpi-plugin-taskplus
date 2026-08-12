<?php

/**
 * Task+ — endpoint AJAX da tela Equipe (Etapa 5b-1: ações do gestor).
 *
 * Contrato com o public/js/team.js (mesmo padrão do ajax/occurrence.php):
 *  - só POST, com `action` (toggle|list) e os campos (`id`, `tech_id`,
 *    `done`);
 *  - o CORE do GLPI 11 valida o `_glpi_csrf_token` do POST sozinho
 *    (CSRF_COMPLIANT) — NUNCA Session::checkCSRF manual;
 *  - a resposta é SEMPRE JSON com: success, message, `csrf` (token NOVO,
 *    que o JS rotaciona — o do POST foi consumido) e `data` (payload
 *    completo e atualizado da tela Equipe, para re-render).
 *
 * Gate = Access::canTeam() (a MESMA régua da tela); a validação de
 * ESCOPO por ação (técnico membro de setor gerido, ocorrência do
 * técnico, nunca item nativo) mora em Team::handle — é reexecutada a
 * cada POST (T18).
 *
 * Lembrete de teste: endpoints ajax/ não respondem pela URL direta no
 * navegador (o roteador devolve "Item requisitado não encontrado") —
 * teste sempre via fetch/tela.
 */

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Team;

include('../../../inc/includes.php');

if (!Access::canTeam()) {
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

// 8e-3: upload do anexo viaja no multipart — repassado ao domínio
$input = $_POST;
if (isset($_FILES['file']) && is_array($_FILES['file'])) {
    $input['_file'] = $_FILES['file'];
}

$result = Team::handle($action, $input, $usersId);

// 5b-2 p2: período ativo viaja em todo POST — o payload de resposta
// devolve o mesmo recorte (normalização em Occurrence::periodRange,
// chamada dentro do Team::payload).
$pf = isset($_POST['period_from']) ? (string) $_POST['period_from'] : null;
$pt = isset($_POST['period_to']) ? (string) $_POST['period_to'] : null;

// Token novo para o JS rotacionar + estado atualizado para re-render
$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = Team::payload($usersId, $pf, $pt);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
