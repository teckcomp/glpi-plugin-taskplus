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
        // 8b: página inicial por perfil (redirect do central.php)
        'home'   => 'plugin_taskplus_home',
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
     *
     * 4c-2: "Configurações" deixa de ser exclusiva do admin — o gestor
     * de setor entra para administrar as fases dos seus grupos (o bloco
     * Preferências continua só admin, gateado dentro da tela).
     */
    public static function sidebar(): array
    {
        $task   = self::can('task');
        $manage = self::can('manage');

        return [
            'today'    => $task,
            'board'    => $task,
            // 8b-2: link "Visão Geral" na sidebar — só faz sentido para
            // quem tem a página inicial trocada (para os demais, a Visão
            // Geral JÁ é a entrada do GLPI e o link seria redundante).
            'home'     => self::can('home'),
            'routines' => $task,
            'week'     => $task,
            // 5a: mesma régua de Configurações — admin sempre; gestor
            // se tem o direito E administra pelo menos um setor. O
            // $manage sozinho não basta: direito marcado sem is_manager
            // em grupo algum daria uma tela vazia.
            'team'     => self::canTeam(),
            'panel'    => $task,
            'history'  => $task,
            'config'   => self::canConfigPhases(),
        ];
    }

    // =====================================================================
    // Escopo de fases por setor (Etapa 4c-2)
    // =====================================================================

    /**
     * Admin das fases = direito NATIVO `config` UPDATE (o mesmo gate da
     * tela de Configurações desde a 4c). Administra as fases de TODOS os
     * setores e as 4 de sistema.
     */
    public static function isPhaseAdmin(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
    }

    /**
     * Setores (grupos do GLPI) que $usersId pode ADMINISTRAR nas fases:
     * mapa [groups_id => nome do grupo].
     *
     *  - admin: TODOS os grupos (é a lista do seletor de setor);
     *  - gestor: grupos onde ele tem `is_manager` em glpi_groups_users.
     *
     * A tabela de grupos é pequena em qualquer instalação real: trazer
     * tudo e resolver em PHP dispensa JOIN (e o erro 1052 junto).
     */
    public static function managedGroups(int $usersId, bool $isAdmin): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $allGroups = [];
        foreach ($DB->request(['FROM' => 'glpi_groups']) as $row) {
            $allGroups[(int) ($row['id'] ?? 0)] = (string) ($row['name'] ?? '');
        }

        if ($isAdmin) {
            return $allGroups;
        }

        $managed = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => [
                'glpi_groups_users.users_id'   => $usersId,
                'glpi_groups_users.is_manager' => 1,
            ],
        ]) as $row) {
            $gid = (int) ($row['groups_id'] ?? 0);
            if (isset($allGroups[$gid])) {
                $managed[$gid] = $allGroups[$gid];
            }
        }

        return $managed;
    }

    /**
     * Setores (grupos do GLPI) em que $usersId é MEMBRO: mapa
     * [groups_id => nome do grupo]. É a leitura do QUADRO (Etapa 4d):
     * o usuário vê as fases dos grupos de que participa — qualquer
     * vínculo em glpi_groups_users conta, gestor ou não (administrar as
     * fases é outra régua: managedGroups, acima).
     */
    public static function memberGroups(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $allGroups = [];
        foreach ($DB->request(['FROM' => 'glpi_groups']) as $row) {
            $allGroups[(int) ($row['id'] ?? 0)] = (string) ($row['name'] ?? '');
        }

        $member = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => ['glpi_groups_users.users_id' => $usersId],
        ]) as $row) {
            $gid = (int) ($row['groups_id'] ?? 0);
            if (isset($allGroups[$gid])) {
                $member[$gid] = $allGroups[$gid];
            }
        }

        return $member;
    }

    /**
     * Pode entrar na tela Configurações (seção de fases)?
     * Admin sempre; gestor se tem o direito `plugin_taskplus_manage` E
     * gerencia pelo menos um grupo. A validação POR AÇÃO fica no
     * Phase::handle — isto aqui é só o gate da tela/sidebar.
     */
    public static function canConfigPhases(): bool
    {
        if (self::isPhaseAdmin()) {
            return true;
        }
        if (!self::can('manage')) {
            return false;
        }
        return self::managedGroups((int) Session::getLoginUserID(), false) !== [];
    }

    // =====================================================================
    // Tela Equipe (Etapa 5a)
    // =====================================================================

    /**
     * Pode entrar na tela Equipe?
     * Admin (direito nativo `config` UPDATE) sempre — vê todos os
     * setores; gestor se tem `plugin_taskplus_manage` E administra pelo
     * menos um grupo (`is_manager`). MESMA régua do gate de fases
     * (canConfigPhases): as duas telas são "coisa de quem administra
     * setor", e réguas divergentes confundiriam o suporte.
     */
    public static function canTeam(): bool
    {
        return self::canConfigPhases();
    }
}
