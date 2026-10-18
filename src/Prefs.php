<?php

namespace GlpiPlugin\Taskplus;

/**
 * 16l (decisão nº 79) — preferências de tela POR USUÁRIO, no servidor:
 * valem em qualquer máquina/navegador.
 *
 *  - `show_all`      : "Ver tudo" (16, nº 73) — abertas futuras sem teto
 *  - `fav_groups_id` : setor favorito do Quadro de Equipe (14c, nº 72)
 *
 * Uma linha por usuário (UNIQUE users_id). Sem linha = usuário nunca
 * gravou nada (`known = false`): o JS então usa o localStorage antigo
 * como fallback e, no primeiro `list`, manda `pref_save` — migração
 * transparente da preferência de navegador para o servidor.
 *
 * Gravação SEM endpoint próprio: o clique no "Ver tudo" / na ★ já
 * dispara um `list`; o endpoint da tela chama applyFromInput() com o
 * mesmo POST (`pref_save=1` grava show_all; `pref_fav=1|0` grava o
 * favorito a partir de groups_id). Um POST só, um token CSRF só.
 */
class Prefs
{
    public const TABLE = 'glpi_plugin_taskplus_user_prefs';

    /** Preferências do usuário (+ `known`: se existe linha). */
    public static function get(int $usersId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = ['known' => false, 'show_all' => false, 'fav_groups_id' => 0];
        if ($usersId <= 0) {
            return $out;
        }
        try {
            foreach ($DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => [self::TABLE . '.users_id' => $usersId],
            ]) as $row) {
                $out['known']         = true;
                $out['show_all']      = ((int) ($row['show_all'] ?? 0)) === 1;
                $out['fav_groups_id'] = (int) ($row['fav_groups_id'] ?? 0);
                break;
            }
        } catch (\Throwable $e) {
            // tabela ainda não existe (install em curso): sem preferência
        }
        return $out;
    }

    /**
     * Lê o POST e grava o que veio marcado para gravar. Devolve true se
     * gravou algo. Nunca lança: preferência é best-effort.
     *
     *  - pref_save=1          → show_all = (show_all == 1)
     *  - pref_fav=1 | 0       → fav_groups_id = groups_id | 0
     */
    public static function applyFromInput(int $usersId, array $input): bool
    {
        $patch = [];
        if (!empty($input['pref_save'])) {
            $patch['show_all'] = Occurrence::upcomingDaysFromInput($input, 0) === Occurrence::UPCOMING_ALL ? 1 : 0;
        }
        if (isset($input['pref_fav']) && is_scalar($input['pref_fav'])) {
            $patch['fav_groups_id'] = ((string) $input['pref_fav'] === '1')
                ? max(0, (int) ($input['groups_id'] ?? 0))
                : 0;
        }
        if ($patch === [] || $usersId <= 0) {
            return false;
        }
        try {
            self::set($usersId, $patch);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Upsert parcial: só as chaves presentes em $patch mudam. */
    public static function set(int $usersId, array $patch): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now  = date('Y-m-d H:i:s');
        $cur  = self::get($usersId);
        $row  = [
            'show_all'      => array_key_exists('show_all', $patch) ? ((int) $patch['show_all'] === 1 ? 1 : 0) : ($cur['show_all'] ? 1 : 0),
            'fav_groups_id' => array_key_exists('fav_groups_id', $patch) ? max(0, (int) $patch['fav_groups_id']) : $cur['fav_groups_id'],
            'date_mod'      => $now,
        ];
        if ($cur['known']) {
            $DB->update(self::TABLE, $row, [self::TABLE . '.users_id' => $usersId]);
            return;
        }
        $DB->insert(self::TABLE, $row + ['users_id' => $usersId]);
    }
}
