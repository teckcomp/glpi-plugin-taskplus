<?php

namespace GlpiPlugin\Taskplus;

/**
 * Helper ÚNICO de URL do plugin (padrão herdado do ProjectPlus).
 *
 * - `Plugin::getWebDir()` está DEPRECATED no GLPI 11 (cada chamada grava
 *   aviso no log). O prefixo `/plugins/` é seguro mesmo em instalação
 *   pelo marketplace: o `PluginsRouterListener` casa `/plugins/` e
 *   `/marketplace/` contra o MESMO padrão e localiza o plugin pela chave.
 * - NÃO usar `$_SERVER['PHP_SELF']`: no front controller do GLPI 11 ele
 *   vale sempre `/index.php`.
 */
final class Url
{
    /**
     * Chave/diretório do plugin. É a mesma string usada pelo roteador.
     */
    public const KEY = 'taskplus';

    /**
     * Raiz web do plugin, sem barra no fim. Ex.: `/glpi/plugins/taskplus`
     */
    public static function base(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/' . self::KEY;
    }

    /**
     * URL de um recurso do plugin. Aceita o caminho com ou sem barra
     * inicial. Ex.: `Url::to('front/today.php')`
     */
    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}
