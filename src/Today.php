<?php

namespace GlpiPlugin\Taskplus;

use CommonGLPI;

/**
 * Task+ — tela "Hoje" (padrão do hub) e âncora do menu Ferramentas.
 *
 * Na Etapa 0 é só a entrada de menu + página vazia; o conteúdo real
 * (avulsas, rotinas, origens nativas) chega nas Etapas 1–3.
 */
class Today extends CommonGLPI
{
    public static $rightname = 'plugin_taskplus_task';

    public static function getTypeName($nb = 0)
    {
        return __('Tarefas', 'taskplus');
    }

    public static function getMenuName()
    {
        return __('Tarefas', 'taskplus');
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getMenuName(),
            'page'  => Url::to('front/today.php'),
            'icon'  => 'ti ti-checklist',
        ];
    }
}
