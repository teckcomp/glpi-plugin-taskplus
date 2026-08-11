<?php

namespace GlpiPlugin\Taskplus;

/**
 * Task+ — página inicial por perfil (Etapa 8b, decisão de produto nº 10).
 *
 * Perfis com o direito `plugin_taskplus_home` aterrissam na tela Hoje do
 * Task+ no lugar da Visão Geral: o hook `post_init` (setup.php) chama
 * `shouldRedirect()` e, se for o caso, redireciona `/front/central.php`
 * para `front/today.php`.
 *
 * POR QUE `header()+exit` E NÃO `Html::redirect()`: validado no fonte do
 * 11.0.6 (sessão 23) — o POST_INIT roda no `Kernel::boot()` (listener
 * `InitializePlugins`, PostBootEvent), ANTES do ciclo de request que
 * converte a `RedirectException` do `Html::redirect()` em resposta
 * (`RedirectExceptionListener` escuta KernelEvents::EXCEPTION). Uma
 * exceção lançada no boot viraria erro 500, não redirect. No boot nada
 * foi emitido ainda, então o `header()` clássico é seguro e determinista.
 *
 * A DECISÃO fica nesta classe, pura e estática (padrão payload/handle da
 * casa): o setup.php só coleta o contexto e obedece.
 */
final class Home
{
    /** Nome do direito registrado no Install (nasce 0 para TODOS os perfis). */
    public const RIGHT = 'plugin_taskplus_home';

    /**
     * Porta de escape (8b-2): `central.php?taskplus_home=0` NÃO é
     * redirecionado — é o link "Visão Geral" da sidebar. Vale POR
     * CLIQUE, não por sessão: voltar ao central.php sem o marcador
     * redireciona de novo (a página inicial segue sendo o Task+).
     */
    public const ESCAPE_PARAM = 'taskplus_home';

    /**
     * Deve redirecionar ESTE request para a tela Hoje?
     *
     * Regras (todas precisam valer):
     *  - não é CLI (console/cron não têm página inicial);
     *  - GET puro (POST em central.php não existe no fluxo normal — se
     *    aparecer, não é nosso papel interceptar);
     *  - usuário logado E com o direito da home no perfil ativo;
     *  - o caminho é EXATAMENTE `<root_doc>/front/central.php` — nada de
     *    ends-with, para nunca casar com um central.php de plugin;
     *  - a query NÃO contém `embed`: o core usa
     *    `central.php?embed&dashboard=...` para embutir dashboards
     *    (Grid.php), e sequestrar esse caso quebraria os painéis;
     *  - a query NÃO traz a porta de escape `taskplus_home=0` (8b-2):
     *    é o link "Visão Geral" da sidebar do Task+;
     *  - nenhum header foi enviado ainda (defesa extra — no boot nunca
     *    foi, mas custa um if).
     */
    public static function shouldRedirect(
        bool $isCli,
        string $method,
        bool $loggedIn,
        bool $hasRight,
        ?string $requestUri,
        string $rootDoc,
        bool $headersSent
    ): bool {
        if ($isCli || $headersSent) {
            return false;
        }
        if (strtoupper($method) !== 'GET') {
            return false;
        }
        if (!$loggedIn || !$hasRight) {
            return false;
        }

        $uri = (string) $requestUri;
        if ($uri === '') {
            return false;
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path !== $rootDoc . '/front/central.php') {
            return false;
        }

        $query = parse_url($uri, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            if (str_contains($query, 'embed')) {
                return false;
            }
            // 8b-2: escape explícito — só o valor '0' abre a exceção
            // (qualquer outro valor não muda nada, para a régua não
            // ganhar um jeito acidental de ser desligada).
            parse_str($query, $params);
            if (($params[self::ESCAPE_PARAM] ?? null) === '0') {
                return false;
            }
        }

        return true;
    }

    /**
     * Destino do redirect. Isolado para o harness e para um eventual
     * destino configurável no futuro.
     */
    public static function target(): string
    {
        return Url::to('front/today.php');
    }
}
