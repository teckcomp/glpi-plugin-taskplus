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

/**
 * ---------------------------------------------------------------------
 * 9e-1 — POR QUE A RECUSA E' UM `throw` E NAO `http_response_code + exit`
 *
 * Mesmo motivo da 9c (ajax/attachment.php), aplicado aos endpoints JSON.
 * O arquivo roda dentro do LegacyFileLoadController: ob_start() ->
 * require -> ob_get_clean(). Um `exit` no meio disso encerra o processo
 * por fora do kernel — a resposta sai pelo shutdown do PHP, sem passar
 * pelo ErrorController nem pelo log de acesso.
 *
 * O padrao do core no 11.0.6 e' o oposto, e foi conferido no fonte: os
 * 34 ajax/ nativos usam `echo json_encode` no caminho FELIZ (por isso ele
 * fica como esta') e 32 deles usam `throw` de HttpException na RECUSA.
 * Nenhum usa http_response_code.
 *
 * Quem transforma a excecao em resposta e' o Glpi\Controller\ErrorController,
 * que NEGOCIA formato: JsonResponse quando o Accept pede json, fragmento
 * HTML quando e' XMLHttpRequest, pagina de erro completa caso contrario.
 * O status HTTP e' o mesmo de antes em todos os casos.
 *
 * O log tambem nao muda de lugar: Glpi\Log\ErrorLogHandler::isHandling
 * descarta qualquer HttpExceptionInterface com status entre 400 e 499 —
 * a regra olha getStatusCode(), NAO a classe. Por isso o 405 pode usar a
 * base HttpException (o core nao tem wrapper de MethodNotAllowed) sem
 * poluir o php-errors.log.
 *
 * O GATE nao mudou: mesma regua, mesmo status, so' a forma de entregar.
 * ---------------------------------------------------------------------
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\HttpException;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Phase;

include('../../../inc/includes.php');

$usersId = (int) Session::getLoginUserID();
$isAdmin = Access::isPhaseAdmin();
$managed = Access::managedGroups($usersId, $isAdmin);

if (!$isAdmin && (!Session::haveRight('plugin_taskplus_manage', READ) || $managed === [])) {
    $denied = new AccessDeniedHttpException();
    $denied->setMessageToDisplay(__('Sem permissão', 'taskplus'));
    throw $denied;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new HttpException(405, 'POST only');
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
