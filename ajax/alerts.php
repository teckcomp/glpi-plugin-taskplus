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
use GlpiPlugin\Taskplus\Alerts;

include('../../../inc/includes.php');

Access::require('task');

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
    throw new HttpException(405, 'GET or POST only');
}

$result['csrf'] = Session::getNewCSRFToken();

// 9e-1: o Content-Type desceu para ca'. Antes ele era emitido logo apos o
// gate, ou seja, ANTES de se saber se o metodo era aceito — com a recusa
// virando excecao, a pagina de erro do core sairia rotulada como JSON.
header('Content-Type: application/json; charset=UTF-8');

echo json_encode(
    $result,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
