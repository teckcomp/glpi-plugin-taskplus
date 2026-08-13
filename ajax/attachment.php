<?php

/**
 * Task+ — download do anexo do diálogo (Etapa 8e-3; resposta limpa na 9c).
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
 *
 * ---------------------------------------------------------------------
 * 9c — POR QUE ESTE ARQUIVO **DEVOLVE** A RESPOSTA EM VEZ DE ESCREVER
 *
 * No GLPI 11 todo arquivo legado roda dentro do LegacyFileLoadController:
 * ele faz ob_start() → require(este arquivo) → ob_get_clean(). Se o
 * script DEVOLVE um Response do Symfony, o controller usa esse objeto e
 * nada mais acontece. A versão anterior chamava Toolbox::sendFile(), que
 * (a) está DEPRECADO no 11.0 e escreve "Called method is deprecated" no
 * log e (b) faz ->send(), e o Response::send() do Symfony chama
 * closeOutputBuffers() — fechando o buffer do PRÓPRIO controller. Daí as
 * duas linhas seguintes no php-errors.log: "The output buffer has been
 * unexpectedly closed" e "Unexpected output detected". O download
 * funcionava (o PHP descarrega o buffer no shutdown), mas por fora do
 * kernel, e o ruído atrapalhava o diagnóstico de erros de verdade.
 *
 * O padrão correto é o do próprio core em front/document.send.php:
 * `return Toolbox::getFileAsResponse(...)` no caminho feliz e `throw`
 * das exceções HTTP do core nos caminhos de recusa. As 4xx NÃO vão para
 * o php-errors.log de propósito (Glpi\Log\ErrorLogHandler::isHandling
 * descarta HttpException 4xx — elas são registradas no log de acesso),
 * então a recusa também sai silenciosa. O GATE não mudou nada: a mesma
 * régua de sempre, só a forma de entregar a resposta é outra.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
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

/**
 * Recusa por "não existe" — 404 com a mensagem na página de erro padrão
 * do GLPI. Mesma mensagem para id inexistente e para linha excluída: não
 * se conta a quem pergunta que o anexo existe.
 */
$notFound = static function (string $msg): never {
    $exception = new NotFoundHttpException();
    $exception->setMessageToDisplay($msg);
    throw $exception;
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
    $notFound(__('Anexo não encontrado', 'taskplus'));
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
    $notFound(__('Tarefa não encontrada', 'taskplus'));
}

$allowed = Comment::canInteract($occ, $usersId);
if (!$allowed && Access::canTeam()) {
    $ownerId  = (int) ($occ['users_id'] ?? 0);
    $managers = Alerts::managersByOwner([['users_id' => $ownerId]]);
    $allowed  = in_array($usersId, $managers[$ownerId] ?? [], true);
}
if (!$allowed) {
    $exception = new AccessDeniedHttpException();
    $exception->setMessageToDisplay(__('Sem permissão para este anexo', 'taskplus'));
    throw $exception;
}

// 3) Document nativo → arquivo no disco → Response do core
$doc = new Document();
if (!$doc->getFromDB((int) $comment['documents_id'])) {
    $notFound(__('Documento não encontrado', 'taskplus'));
}
$path = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
if (!is_file($path)) {
    $notFound(__('Arquivo não encontrado no disco', 'taskplus'));
}

// getFileAsResponse é a API NÃO deprecada equivalente ao sendFile: mesmos
// headers, mesmo Content-Disposition, mesmo tratamento de 304. O `?: null`
// no mime preserva o comportamento anterior — mime vazio no Document deixa
// o core detectar por finfo em vez de mandar Content-type em branco.
return Toolbox::getFileAsResponse(
    $path,
    (string) $doc->fields['filename'],
    $doc->fields['mime'] ?: null
);
