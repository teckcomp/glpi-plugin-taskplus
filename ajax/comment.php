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

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Comment;

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
