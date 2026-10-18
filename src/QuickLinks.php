<?php

namespace GlpiPlugin\Taskplus;

use Config as CoreConfig;

/**
 * Atalhos da tela Hoje (16c, decisão nº 74).
 *
 * Até MAX botões globais, cadastrados pelo admin (direito nativo
 * `config` UPDATE) em Configurações: nome + link + ícone Tabler + cor +
 * nova aba. Ficam em glpi_configs (context do plugin) como JSON na
 * chave `quick_links` — sem tabela nova, sem Install.
 *
 * A régua de validação mora aqui (fromInput) e vale tanto para o POST
 * do formulário quanto para o que já está gravado (all() re-normaliza:
 * JSON editado à mão ou de versão futura nunca derruba a Hoje).
 */
class QuickLinks
{
    public const KEY = 'quick_links';
    public const MAX = 10;

    /** Cores disponíveis → [fundo, texto, borda]. Chave vai na classe CSS. */
    public const COLORS = [
        'blue'   => 'Azul',
        'indigo' => 'Azul-escuro',
        'cyan'   => 'Ciano',
        'teal'   => 'Verde-água',
        'green'  => 'Verde',
        'lime'   => 'Lima',
        'amber'  => 'Âmbar',
        'orange' => 'Laranja',
        'coral'  => 'Coral',
        'red'    => 'Vermelho',
        'pink'   => 'Rosa',
        'purple' => 'Roxo',
        'brown'  => 'Marrom',
        'gray'   => 'Cinza',
    ];

    /** Catálogo de ícones (Tabler outline) oferecido no seletor. */
    public const ICONS = [
        'ti-link'           => 'Link',
        'ti-world'          => 'Site / web',
        'ti-home'           => 'Início',
        'ti-ticket'         => 'Ticket',
        'ti-headset'        => 'Suporte',
        'ti-users'          => 'Equipe',
        'ti-user'           => 'Pessoa',
        'ti-building'       => 'Empresa',
        'ti-building-store' => 'Loja',
        'ti-map'            => 'Mapa',
        'ti-map-pin'        => 'Local',
        'ti-file-text'      => 'Documento',
        'ti-folder'         => 'Pasta',
        'ti-book'           => 'Manual / wiki',
        'ti-clipboard-list' => 'Lista / checklist',
        'ti-chart-bar'      => 'Relatório',
        'ti-calendar'       => 'Agenda',
        'ti-mail'           => 'E-mail',
        'ti-phone'          => 'Telefone',
        'ti-brand-whatsapp' => 'WhatsApp',
        'ti-server'         => 'Servidor',
        'ti-database'       => 'Banco de dados',
        'ti-tool'           => 'Ferramenta',
        'ti-settings'       => 'Configuração',
        'ti-shield'         => 'Segurança',
        'ti-cash'           => 'Financeiro',
        'ti-shopping-cart'  => 'Compras',
        'ti-briefcase'      => 'Projeto',
        'ti-bell'           => 'Aviso',
        'ti-star'           => 'Favorito',
    ];
    public const DEFAULT_COLOR = 'blue';
    public const DEFAULT_ICON  = 'ti-link';

    /**
     * Atalhos válidos, na ordem cadastrada (só os preenchidos).
     * Falha de leitura/JSON → lista vazia (a faixa some, a tela segue).
     */
    public static function all(): array
    {
        try {
            $values = CoreConfig::getConfigurationValues(Config::CONTEXT, [self::KEY]);
        } catch (\Throwable $e) {
            return [];
        }
        $raw = $values[self::KEY] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? self::normalize($decoded) : [];
    }

    /**
     * MAX linhas para o formulário: as cadastradas + linhas em branco.
     */
    public static function rowsForForm(): array
    {
        $rows = self::all();
        while (count($rows) < self::MAX) {
            $rows[] = self::blank();
        }
        return array_slice($rows, 0, self::MAX);
    }

    public static function blank(): array
    {
        return ['name' => '', 'url' => '', 'icon' => self::DEFAULT_ICON, 'color' => self::DEFAULT_COLOR, 'new_tab' => 0];
    }

    /**
     * Lê `ql[<i>][name|url|icon|color|new_tab]` do POST, normaliza e
     * grava. Linha sem nome OU sem link válido é descartada (não dá
     * erro: o usuário deixa em branco para "apagar").
     */
    public static function saveFromInput(array $input): array
    {
        $rows  = is_array($input['ql'] ?? null) ? $input['ql'] : [];
        $clean = self::normalize($rows);
        CoreConfig::setConfigurationValues(Config::CONTEXT, [
            self::KEY => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return $clean;
    }

    /**
     * Régua única: no máximo MAX itens; nome 1–40 chars; link http(s)://
     * absoluto OU relativo começando por "/" (interno ao GLPI); ícone e
     * cor só do catálogo (ICONS/COLORS), com fallback para o padrão;
     * new_tab 0/1. Ordem preservada.
     */
    public static function normalize(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $url  = self::validUrl((string) ($row['url'] ?? ''));
            if ($name === '' || $url === null) {
                continue;
            }
            if (mb_strlen($name) > 40) {
                $name = mb_substr($name, 0, 40);
            }
            $icon = strtolower(trim((string) ($row['icon'] ?? '')));
            if ($icon !== '' && strpos($icon, 'ti-') !== 0) {
                $icon = 'ti-' . $icon;
            }
            if (!isset(self::ICONS[$icon])) {
                $icon = self::DEFAULT_ICON; // fora do catálogo → padrão
            }
            $color = (string) ($row['color'] ?? '');
            if (!isset(self::COLORS[$color])) {
                $color = self::DEFAULT_COLOR;
            }
            $out[] = [
                'name'    => $name,
                'url'     => $url,
                'icon'    => $icon,
                'color'   => $color,
                'new_tab' => !empty($row['new_tab']) ? 1 : 0,
            ];
            if (count($out) >= self::MAX) {
                break;
            }
        }
        return $out;
    }

    /** http(s)://host… (absoluto) ou /caminho (relativo ao GLPI); senão null. */
    public static function validUrl(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || mb_strlen($raw) > 2000) {
            return null;
        }
        if (preg_match('/[\s"\'<>]/', $raw)) {
            return null; // nada que escape de um atributo
        }
        if (preg_match('#^https?://[^/]+#i', $raw)) {
            return $raw;
        }
        if ($raw[0] === '/' && (strlen($raw) === 1 || $raw[1] !== '/')) {
            return $raw; // relativo; "//host" (protocol-relative) não
        }
        return null;
    }
}
