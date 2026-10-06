<?php

/**
 * Task+ — endpoint AJAX do Quadro de Equipe (13b).
 *
 * Contrato com o public/js/teamboard.js:
 *  - só POST, com `action` (list|add|update|delete), `groups_id` (setor
 *    exibido — viaja em todo POST) e os campos; o CORE valida o
 *    `_glpi_csrf_token` sozinho (CSRF_COMPLIANT);
 *  - resposta SEMPRE JSON: success, message, `csrf` (token NOVO) e
 *    `data` (payload atualizado do setor, para re-render).
 *
 * Recusa por `throw` de HttpException (9e-1): 4xx não polui o log.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\HttpException;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\TeamBoard;

include('../../../inc/includes.php');

Access::require('task');
if (!Access::canTeamBoard()) {
    throw new AccessDeniedHttpException();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new HttpException(405, 'POST only');
}

$usersId = (int) Session::getLoginUserID();
$action  = (string) ($_POST['action'] ?? '');
$groupId = (int) ($_POST['groups_id'] ?? 0);

$result = TeamBoard::handle($action, $_POST, $usersId);

// 13c-2: período viaja em todo POST (cascateia para o payload)
$pf = isset($_POST['period_from']) ? (string) $_POST['period_from'] : null;
$pt = isset($_POST['period_to']) ? (string) $_POST['period_to'] : null;

$result['csrf'] = Session::getNewCSRFToken();
$result['data'] = TeamBoard::payload($usersId, $groupId, $pf, $pt);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
