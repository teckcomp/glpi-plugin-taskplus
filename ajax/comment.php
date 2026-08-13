<?php

/**
 * Task+ — endpoint AJAX do diálogo das tarefas (Etapa 8e-1).
 *
 * Contrato com o public/js/taskplus.js (seção do diálogo):
 *  - só POST, com `action` (list|add|delete), `occurrences_id` e os
 *    campos; o CORE valida o `_glpi_csrf_token` sozinho (CSRF_COMPLIANT);
 *  - resposta SEMPRE JSON: success, message, `csrf` (token NOVO, o do
 *    POST foi consumido) e `comments` (a thread atualizada da
 *    ocorrência, para re-render do modal).
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

use Glpi\Exception\Http\HttpException;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Comment;

include('../../../inc/includes.php');

Access::require('task');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new HttpException(405, 'POST only');
}

$usersId = (int) Session::getLoginUserID();
$action  = (string) ($_POST['action'] ?? '');

// 8e-3: upload do anexo viaja no multipart — repassado ao domínio
$input = $_POST;
if (isset($_FILES['file']) && is_array($_FILES['file'])) {
    $input['_file'] = $_FILES['file'];
}

$result = Comment::handle($action, $input, $usersId);

// 9a-1: qualquer ação sobre a thread significa que o usuário está
// OLHANDO para ela agora (o modal exibe a lista logo em seguida) —
// então zera o não lido. markReadIfVisible revalida a participação:
// quem não alcança a thread não grava leitura.
Comment::markReadIfVisible((int) ($_POST['occurrences_id'] ?? 0), $usersId);

// Token novo para rotação + thread atualizada (o gate de leitura é o
// mesmo da escrita — quem não participa recebe lista vazia).
$result['csrf']     = Session::getNewCSRFToken();
$result['comments'] = Comment::listFor((int) ($_POST['occurrences_id'] ?? 0), $usersId);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
