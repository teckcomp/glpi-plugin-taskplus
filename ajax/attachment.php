<?php

/**
 * Task+ — download do anexo do diálogo (Etapa 8e-3).
 *
 * GET com `comment` (id do comentário). O ARQUIVO é um Document nativo,
 * mas quem serve é o plugin, com o gate PRÓPRIO — assim o técnico baixa
 * sem depender de direito de Document no perfil, e a régua é a mesma da
 * conversa:
 *  - participante da tarefa (dono ou criador — decisão nº 28); OU
 *  - GESTOR do dono (is_manager em grupo do dono + direito de equipe),
 *    o leitor da tela Equipe — mesma régua managersByOwner do sino (7a).
 *
 * Download é LEITURA: GET sem CSRF, como o document.send do core.
 */

use GlpiPlugin\Taskplus\Access;
use GlpiPlugin\Taskplus\Alerts;
use GlpiPlugin\Taskplus\Comment;
use GlpiPlugin\Taskplus\Occurrence;

include('../../../inc/includes.php');

Access::require('task');

/** @var \DBmysql $DB */
global $DB;

$usersId   = (int) Session::getLoginUserID();
$commentId = (int) ($_GET['comment'] ?? 0);

$deny = static function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $msg;
    exit;
};

// 1) comentário vivo com anexo
$comment = null;
if ($commentId > 0) {
    foreach ($DB->request([
        'FROM'  => Comment::TABLE,
        'WHERE' => [
            Comment::TABLE . '.id'         => $commentId,
            Comment::TABLE . '.is_deleted' => 0,
        ],
    ]) as $row) {
        $comment = $row;
    }
}
if ($comment === null || (int) ($comment['documents_id'] ?? 0) <= 0) {
    $deny(404, 'Anexo não encontrado');
}

// 2) ocorrência viva + gate (participante OU gestor do dono)
$occ = null;
foreach ($DB->request([
    'FROM'  => Occurrence::TABLE,
    'WHERE' => [
        Occurrence::TABLE . '.id'         => (int) $comment['plugin_taskplus_occurrences_id'],
        Occurrence::TABLE . '.is_deleted' => 0,
    ],
]) as $row) {
    $occ = $row;
}
if ($occ === null) {
    $deny(404, 'Tarefa não encontrada');
}

$allowed = Comment::canInteract($occ, $usersId);
if (!$allowed && Access::canTeam()) {
    $ownerId  = (int) ($occ['users_id'] ?? 0);
    $managers = Alerts::managersByOwner([['users_id' => $ownerId]]);
    $allowed  = in_array($usersId, $managers[$ownerId] ?? [], true);
}
if (!$allowed) {
    $deny(403, 'Sem permissão para este anexo');
}

// 3) Document nativo → arquivo no disco → envio pelo Toolbox do core
$doc = new Document();
if (!$doc->getFromDB((int) $comment['documents_id'])) {
    $deny(404, 'Documento não encontrado');
}
$path = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
if (!is_file($path)) {
    $deny(404, 'Arquivo não encontrado no disco');
}

Toolbox::sendFile($path, (string) $doc->fields['filename'], $doc->fields['mime'] ?: null);
