<?php

/**
 * Task+ — endpoint AJAX das fases do quadro (Etapa 4c, revisto na 4c-2).
 *
 * Contrato com o public/js/config.js (idêntico ao routine.php):
 *  - só POST, com `action` (add|update|delete|move|list) e os campos;
 *  - o CORE do GLPI 11 valida o `_glpi_csrf_token` do POST sozinho
 *    (CSRF_COMPLIANT) — NUNCA Session::checkCSRF manual;
 *  - a resposta é SEMPRE JSON com: success, message, `csrf` (token NOVO,
 *    que o JS rotaciona) e `data` (payload atualizado das fases).
 *
 * GATE da 4c-2 (fases por setor): entra o admin (`config` UPDATE, mexe
 * em tudo) OU o gestor de setor (`plugin_taskplus_manage` + `is_manager`
 * em pelo menos um grupo — mexe só nas fases dos seus setores). O gestor
 * NÃO precisa ser super-admin. O gate daqui só abre a porta; o escopo
 * fino é revalidado POR AÇÃO dentro do Phase::handle — esconder botão no
 * JS nunca é a proteção. Num endpoint ajax a negativa responde JSON 403
 * (Html::displayRightError devolveria HTML para o fetch).
 */

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Phase;

include('../../../inc/includes.php');

$usersId = (int) Session::getLoginUserID();
$isAdmin = Access::isPhaseAdmin();
$managed = Access::managedGroups($usersId, $isAdmin);

if (!$isAdmin && (!Session::haveRight('plugin_taskplus_manage', READ) || $managed === [])) {
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

$action = (string) ($_POST['action'] ?? '');

$result = Phase::handle(
    $action,
    $_POST,
    $usersId,
    $isAdmin,
    array_map('intval', array_keys($managed))
);

// Token novo para o JS rotacionar + estado atualizado para re-render
$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = Phase::payload($isAdmin, $managed);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
