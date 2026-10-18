<?php

/**
 * Task+ — 16q: endpoint do SELETOR DE TEMA da casca (padrão do alerts.php).
 *
 * Carregado em TODAS as telas do hub pela casca (_sidebar), por isso é
 * AUTOSSUFICIENTE em CSRF, como o sino:
 *
 *  - GET                       → { theme, csrf } — leitura; daqui o
 *    shell.js tira o primeiro token (a casca não recebe csrf_token de
 *    nenhum controlador — zero mudança nos 9 front/).
 *  - POST pref_theme=dark|light → grava em user_prefs (Prefs::applyFromInput,
 *    mesma régua do 16l) e devolve { theme, csrf }.
 *
 * O CORE valida o `_glpi_csrf_token` do POST sozinho — nunca
 * Session::checkCSRF manual. Recusa por `throw` (9e-1), nunca exit.
 */

use Glpi\Exception\Http\HttpException;
use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Prefs;

include('../../../inc/includes.php');

Access::require('task');

$usersId = (int) Session::getLoginUserID();
$method  = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

if ($method === 'POST') {
    Prefs::applyFromInput($usersId, $_POST);
} elseif ($method !== 'GET') {
    throw new HttpException(405, 'GET or POST only');
}

$result = [
    'theme' => Prefs::get($usersId)['theme'],
    'csrf'  => Session::getNewCSRFToken(),
];

header('Content-Type: application/json; charset=UTF-8');

echo json_encode(
    $result,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
