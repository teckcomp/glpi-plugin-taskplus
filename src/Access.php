<?php

namespace GlpiPlugin\Taskplus;

use Html;
use Session;

/**
 * Task+ — helper central de controle de acesso (padrão do ProjectPlus).
 *
 * Traduz os 2 direitos do plugin em decisões simples de "pode ver?".
 *
 * Uso típico:
 *   - nos front/*.php, para gatear a tela:    Access::require('task');
 *   - nos templates, para montar a sidebar:   passa-se Access::sidebar() como 'nav'.
 *
 * Classe estática, sem estado e sem extends — autoloadeia via PSR-4
 * (namespace GlpiPlugin\Taskplus → src/), não precisa de registro no setup.php.
 */
class Access
{
    /**
     * Mapa módulo → nome do direito em glpi_profilerights.
     * (Configuração continua no direito NATIVO `config`, fora daqui.)
     */
    public const RIGHTS = [
        'task'   => 'plugin_taskplus_task',
        'manage' => 'plugin_taskplus_manage',
    ];

    /**
     * O perfil atual tem o direito $module no nível $right?
     * $right ausente = READ.
     */
    public static function can(string $module, ?int $right = null): bool
    {
        if (!isset(self::RIGHTS[$module])) {
            return false;
        }
        if ($right === null) {
            $right = READ;
        }
        return (bool) Session::haveRight(self::RIGHTS[$module], $right);
    }

    /**
     * Gate de tela: interrompe com "sem permissão" se o perfil não puder
     * ver o módulo. Equivalente a Session::checkRight resolvendo o nome
     * do direito pelo mapa.
     */
    public static function require(string $module, ?int $right = null): void
    {
        if (!self::can($module, $right)) {
            Html::displayRightError();
        }
    }

    /**
     * Flags de visibilidade da sidebar, consumidas pelos templates como
     * `nav.*`. Retorna TODAS as chaves sempre (o Twig do GLPI é strict:
     * acessar chave inexistente em `nav` quebra a tela — lição nº 9 do
     * ProjectPlus).
     *
     * Telas ainda não construídas aparecem desabilitadas no template; as
     * flags aqui só dizem se o perfil PODERIA vê-las.
     */
    public static function sidebar(): array
    {
        $task   = self::can('task');
        $manage = self::can('manage');

        return [
            'today'    => $task,
            'routines' => $task,
            'week'     => $task,
            'team'     => $manage,
            'panel'    => $task,
            'history'  => $task,
            'config'   => (bool) Session::haveRight('config', UPDATE),
        ];
    }
}
