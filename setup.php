<?php

/**
 * Task+ — hub unificado de tarefas para GLPI 11.
 *
 * Rotinas recorrentes, tarefas avulsas, tarefas de projeto e de chamados
 * numa tela só. Derivado da base do ProjectPlus (v1.1.0-beta), sem os
 * módulos de Projetos/Modelos/Orçamento/Custos/Relatórios.
 *
 * Não substitui nem altera tabelas nativas (glpi_projecttasks,
 * glpi_tickettasks). Apenas adiciona tabelas e telas próprias; as origens
 * nativas serão LIDAS (Etapa 3), nunca escritas.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Taskplus\Home;
use GlpiPlugin\Taskplus\Today;

define('PLUGIN_TASKPLUS_VERSION', '0.1.0-beta');

// Versões mínima/máxima do GLPI suportadas
define('PLUGIN_TASKPLUS_MIN_GLPI', '11.0.0');
define('PLUGIN_TASKPLUS_MAX_GLPI', '11.0.99');

/**
 * Inicialização do plugin: hooks, menus, CSS.
 */
function plugin_init_taskplus(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['taskplus'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('taskplus')) {
        return;
    }

    // Aba "Task+" na tela de Perfil (complemento da Etapa 5a): é onde os
    // dois direitos do plugin são marcados — sem ela eles existem em
    // glpi_profilerights mas não aparecem em lugar nenhum da interface.
    Plugin::registerClass(
        GlpiPlugin\Taskplus\Profile::class,
        ['addtabon' => 'Profile']
    );

    // Etapa 7a: itemtype de notificação do plugin. Habilita "Tarefa do
    // Task+" nos cadastros de modelos/notificações do GLPI e permite ao
    // NotificationEvent::raiseEvent achar o target por convenção de nome
    // (NotificationTargetOccurrenceAlert).
    Plugin::registerClass(
        GlpiPlugin\Taskplus\OccurrenceAlert::class,
        ['notificationtemplates_types' => true]
    );

    // Item de menu: Ferramentas > Task+ (tela Hoje)
    if (Session::haveRight('plugin_taskplus_task', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['taskplus'] = [
            'tools' => Today::class,
        ];
    }

    // Página de configuração do plugin (Configurar > Plugins)
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['taskplus'] = 'front/config.form.php';
    }

    // CSS carregado em todas as páginas do GLPI (só estiliza as telas do plugin)
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['taskplus'] = 'css/taskplus.css';

    // 7a: JS do sino de alertas, mesma filosofia do CSS — carrega em
    // todas as páginas, mas a guarda do bell.js o torna inerte fora das
    // telas do plugin (o markup do sino só existe na sidebar do Task+).
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['taskplus'] = 'js/bell.js';

    // 8b: página inicial por perfil — roda a cada request, decide em
    // GlpiPlugin\Taskplus\Home::shouldRedirect() (pura, testada) e só
    // age em GET de /front/central.php de usuário com o direito.
    $PLUGIN_HOOKS[Hooks::POST_INIT]['taskplus'] = 'plugin_taskplus_post_init';
}

/**
 * Hook post_init (8b): perfis com `plugin_taskplus_home` aterrissam na
 * tela Hoje no lugar da Visão Geral.
 *
 * `header()+exit` de propósito, NÃO `Html::redirect()`: o POST_INIT roda
 * no Kernel::boot(), antes do ciclo que converte a RedirectException em
 * resposta (validado no fonte do 11.0.6 — sessão 23). No boot nada foi
 * emitido, então o header clássico é seguro.
 */
function plugin_taskplus_post_init(): void
{
    /** @var array $CFG_GLPI */
    global $CFG_GLPI;

    $loggedIn = (int) Session::getLoginUserID() > 0;
    $hasRight = $loggedIn && Session::haveRight(Home::RIGHT, READ);

    $should = Home::shouldRedirect(
        PHP_SAPI === 'cli',
        (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        $loggedIn,
        (bool) $hasRight,
        $_SERVER['REQUEST_URI'] ?? null,
        (string) ($CFG_GLPI['root_doc'] ?? ''),
        headers_sent()
    );

    if ($should) {
        header('Location: ' . Home::target(), true, 302);
        exit;
    }
}

/**
 * Metadados do plugin.
 */
function plugin_version_taskplus(): array
{
    return [
        'name'         => 'Tarefas',
        'version'      => PLUGIN_TASKPLUS_VERSION,
        'author'       => 'Teckcomp I.T. Services',
        'license'      => 'GPL-2.0-or-later',
        'homepage'     => 'https://github.com/teckcomp/glpi-plugin-taskplus',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TASKPLUS_MIN_GLPI,
                'max' => PLUGIN_TASKPLUS_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Pré-requisitos (chamado antes da instalação).
 */
function plugin_taskplus_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_TASKPLUS_MIN_GLPI, '<')) {
        echo sprintf(
            'Este plugin requer GLPI >= %s (versão atual: %s)',
            PLUGIN_TASKPLUS_MIN_GLPI,
            GLPI_VERSION
        );
        return false;
    }
    return true;
}

/**
 * Verificação de configuração (chamado na ativação).
 */
function plugin_taskplus_check_config($verbose = false): bool
{
    return true;
}
