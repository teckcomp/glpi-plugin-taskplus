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
use GlpiPlugin\Taskplus\Occurrence;
use GlpiPlugin\Taskplus\Tickets;

include('../../../inc/includes.php');

Access::require('task');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new HttpException(405, 'POST only');
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

// Etapa 9a-1: o contador de não lidos acompanha TODO re-render (mesma
// decoração do front/today.php, mesmo leitor).
try {
    $result['data'] = Comment::withUnread($result['data'], $usersId);
} catch (\Throwable $e) {
    // contagem indisponível: re-render segue sem os badges
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
