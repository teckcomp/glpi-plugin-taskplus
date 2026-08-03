<?php

/**
 * Task+ — hooks de instalação/desinstalação.
 * O GLPI exige estas funções globais; a lógica real fica em src/Install.php.
 */

use GlpiPlugin\Taskplus\Install;

function plugin_taskplus_install(): bool
{
    return Install::install();
}

function plugin_taskplus_uninstall(): bool
{
    return Install::uninstall();
}
