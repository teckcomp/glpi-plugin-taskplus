<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — tela "Equipe" (Etapa 5a: leitura para o gestor).
 *
 * Camada única de dados da tela Equipe, usada pelo front/team.php.
 * SEM endpoint ajax nesta sub-etapa: a tela é leitura pura, o payload
 * inteiro (placar + tarefas de cada técnico) vai embutido na página e o
 * expandir/recolher é só JS. Ações do gestor (concluir/editar tarefa do
 * técnico) chegam na 5a-2, com endpoint próprio e validação por ação.
 *
 * MODELO (decisões da abertura da Etapa 5):
 *
 *  - Quem vê: admin (direito nativo `config` UPDATE) vê TODOS os
 *    setores; gestor (`plugin_taskplus_manage` + `is_manager` em pelo
 *    menos um grupo) vê SÓ os setores que administra — a régua é
 *    Access::managedGroups(), a MESMA das fases (4c-2), nunca a de
 *    membro (que é a leitura do Quadro).
 *
 *  - Técnicos = MEMBROS dos setores administrados (qualquer vínculo em
 *    glpi_groups_users conta, gestor incluso — ele também tem dia).
 *    Usuário em mais de um setor aparece UMA vez, com todos os chips.
 *
 *  - O dia de cada técnico vem de Occurrence::payload($techId) — o
 *    MESMO payload da tela Hoje e do Quadro. Reusar em vez de
 *    reconsultar garante que gestor e técnico veem números idênticos
 *    (KPIs, pendências expirando, nativas, tudo). Falha no payload de
 *    UM técnico não derruba a tela: ele aparece zerado com aviso.
 *
 *  - Nativas (chamado/projeto) aparecem na lista expandida como
 *    leitura + link, como na tela Hoje (decisão nº 12): o gestor NUNCA
 *    age sobre item nativo de outro técnico — a decisão nº 3 / lição
 *    T18 exige que quem grava seja o próprio dono (users_id_tech).
 */
class Team
{
    // =====================================================================
    // Payload da tela Equipe
    // =====================================================================

    /**
     * Tudo que a tela Equipe precisa para renderizar:
     *
     *   [
     *     'date'   => 'Y-m-d' (hoje),
     *     'groups' => [nomes dos setores administrados, ordenados],
     *     'techs'  => [por técnico: id, label, groups, kpis, items],
     *   ]
     *
     * ATENÇÃO safeData(): chave nova aqui precisa entrar no team.js.
     */
    public static function payload(int $usersId): array
    {
        $isAdmin = Access::isPhaseAdmin();
        $groups  = Access::managedGroups($usersId, $isAdmin);

        $members = self::members(array_keys($groups), $groups);

        $techs = [];
        foreach ($members as $techId => $info) {
            // O dia de um técnico com dado ruim não pode esconder o
            // resto da equipe: ele entra zerado, marcado com load_error,
            // e o JS mostra o aviso na linha dele.
            try {
                $occ = Occurrence::payload($techId);
                $techs[] = self::techEntry($techId, $info['label'], $info['groups'], $occ);
            } catch (\Throwable $e) {
                $entry               = self::techEntry($techId, $info['label'], $info['groups'], []);
                $entry['load_error'] = true;
                $techs[]             = $entry;
            }
        }

        // Ordem estável e previsível: alfabética pelo nome exibido;
        // empate (homônimos) resolve por id.
        usort($techs, static function (array $a, array $b): int {
            return [mb_strtolower($a['label']), $a['id']]
                <=> [mb_strtolower($b['label']), $b['id']];
        });

        $groupNames = array_values($groups);
        sort($groupNames, SORT_FLAG_CASE | SORT_NATURAL);

        return [
            'date'   => date('Y-m-d'),
            'groups' => $groupNames,
            'techs'  => $techs,
        ];
    }

    /**
     * Entrada de UM técnico no payload, a partir do payload da tela Hoje
     * dele. Método público e puro (sem DB, sem sessão) de propósito: é o
     * coração testável da tela — o harness o exercita direto.
     */
    public static function techEntry(int $techId, string $label, array $groupNames, array $occ): array
    {
        $kpis = is_array($occ['kpis'] ?? null) ? $occ['kpis'] : [];

        $items = [];
        // Atrasadas vêm da lista própria do payload; o flag de origem
        // garante o status mesmo se is_late vier inesperadamente falso.
        foreach ((array) ($occ['overdue'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = self::itemRow($item, true);
            }
        }
        foreach ((array) ($occ['today'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = self::itemRow($item, false);
            }
        }

        // Ordem de leitura do gestor = ordem dos KPIs: Atrasadas ·
        // Para hoje · Pendentes · Concluídas. Dentro do grupo preserva a
        // ordem que a tela Hoje já calculou (usort estável no PHP 8).
        usort($items, static function (array $a, array $b): int {
            return $a['weight'] <=> $b['weight'];
        });

        return [
            'id'         => $techId,
            'label'      => $label,
            'groups'     => array_values($groupNames),
            'load_error' => false,
            'kpis'       => [
                'late'    => (int) ($kpis['late'] ?? 0),
                'today'   => (int) ($kpis['today'] ?? 0),
                'pending' => (int) ($kpis['pending'] ?? 0),
                'done'    => (int) ($kpis['done'] ?? 0),
            ],
            'items'      => $items,
        ];
    }

    /**
     * Item do payload da tela Hoje → linha enxuta da lista expandida.
     * Pura (testável). O status segue a MESMA precedência do Quadro
     * (Board::resolveColumn): pendente · concluída · atrasada · do dia.
     */
    public static function itemRow(array $item, bool $fromOverdue): array
    {
        $status = 'today';
        if (!empty($item['is_pending'])) {
            $status = 'pending';
        } elseif (!empty($item['is_done'])) {
            $status = 'done';
        } elseif ($fromOverdue || !empty($item['is_late'])) {
            $status = 'late';
        }

        $detail = '';
        switch ($status) {
            case 'pending':
                // Ex.: "volta em 12/08 14:30" — já formatado pela 4b
                $detail = (string) ($item['pending_label'] ?? '');
                break;
            case 'done':
                $doneTime = (string) ($item['done_time'] ?? '');
                if (!empty($item['was_overdue'])) {
                    // De dia anterior, concluída hoje (4d-2)
                    $detail = sprintf(
                        __('de %s · concluída %s', 'taskplus'),
                        (string) ($item['date_label'] ?? ''),
                        $doneTime
                    );
                } elseif ($doneTime !== '') {
                    $detail = sprintf(__('concluída %s', 'taskplus'), $doneTime);
                } else {
                    $detail = __('concluída', 'taskplus');
                }
                break;
            case 'late':
                $detail = sprintf(__('de %s', 'taskplus'), (string) ($item['date_label'] ?? ''));
                if (!empty($item['time_limit'])) {
                    $detail .= ' · ' . (string) $item['time_limit'];
                }
                break;
            default:
                if (!empty($item['time_limit'])) {
                    $detail = sprintf(__('até %s', 'taskplus'), (string) $item['time_limit']);
                }
        }

        $weights = ['late' => 0, 'today' => 1, 'pending' => 2, 'done' => 3];

        return [
            'name'      => (string) ($item['name'] ?? ''),
            'status'    => $status,
            'weight'    => $weights[$status],
            'detail'    => $detail,
            'is_native' => !empty($item['is_native']),
            // 'ticket' | 'project' | '' (própria)
            'source'    => (string) ($item['source'] ?? ''),
            // Link do item nativo (abre no GLPI); vazio nas próprias
            'url'       => !empty($item['is_native']) ? (string) ($item['url'] ?? '') : '',
        ];
    }

    // =====================================================================
    // Membros dos setores administrados
    // =====================================================================

    /**
     * Usuários MEMBROS dos grupos $groupIds, deduplicados:
     * [users_id => ['label' => nome exibido, 'groups' => [nomes]]].
     *
     * Duas consultas simples e junção em PHP (mesma higiene do
     * Access::managedGroups): dispensa JOIN com glpi_users e o
     * erro 1052 junto. Usuário desativado ou excluído fica fora —
     * a régua de "quem aparece na Equipe" é gente que ainda trabalha.
     */
    private static function members(array $groupIds, array $groupNames): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($groupIds === []) {
            return [];
        }

        $byUser = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => ['glpi_groups_users.groups_id' => $groupIds],
        ]) as $row) {
            $uid = (int) ($row['users_id'] ?? 0);
            $gid = (int) ($row['groups_id'] ?? 0);
            if ($uid > 0 && isset($groupNames[$gid])) {
                $byUser[$uid][$gid] = $groupNames[$gid];
            }
        }

        if ($byUser === []) {
            return [];
        }

        $members = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => [
                // Lista nunca vazia aqui (early return acima), mas a
                // regra "filtro nunca some" fica explícita mesmo assim
                'glpi_users.id'         => array_keys($byUser) ?: [0],
                'glpi_users.is_deleted' => 0,
                'glpi_users.is_active'  => 1,
            ],
        ]) as $row) {
            $uid = (int) ($row['id'] ?? 0);

            $label = trim(
                (string) ($row['firstname'] ?? '') . ' ' . (string) ($row['realname'] ?? '')
            );
            if ($label === '') {
                $label = (string) ($row['name'] ?? '');
            }

            $names = $byUser[$uid] ?? [];
            sort($names, SORT_FLAG_CASE | SORT_NATURAL);

            $members[$uid] = [
                'label'  => $label,
                'groups' => $names,
            ];
        }

        return $members;
    }
}
