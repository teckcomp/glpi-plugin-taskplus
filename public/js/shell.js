/**
 * Task+ — 16q: casca (shell) — seletor de tema.
 *
 * Carregado em todas as páginas do GLPI (hook ADD_JAVASCRIPT, como o
 * bell.js) e INERTE fora das telas do hub: só age se achar o markup da
 * casca (#taskplus-shell). Tema padrão = petróleo escuro ('' no servidor);
 * 'light' = cinza claro. O atributo mora no <html> (data-taskplus-theme),
 * posto pelo inline do partial ANTES do primeiro paint — este arquivo só
 * troca e grava.
 *
 * Gravação pelo ajax/prefs.php (GET traz o primeiro CSRF; POST grava e
 * devolve o próximo). O localStorage guarda uma cópia só para a troca
 * refletir instantaneamente na próxima página antes do servidor responder.
 */
(function () {
    'use strict';

    var KEY = 'taskplus.theme';

    function current() {
        return document.documentElement.getAttribute('data-taskplus-theme') === 'light' ? 'light' : 'dark';
    }

    function apply(theme) {
        document.documentElement.setAttribute('data-taskplus-theme', theme === 'light' ? 'light' : 'dark');
        var btn = document.getElementById('taskplus-theme');
        if (btn) {
            var light = theme === 'light';
            btn.setAttribute('aria-pressed', light ? 'true' : 'false');
            btn.title = light ? 'Tema: claro — clique para o escuro' : 'Tema: escuro — clique para o claro';
            var ic = btn.querySelector('i');
            if (ic) {
                ic.className = light ? 'ti ti-moon' : 'ti ti-sun';
            }
        }
        try { window.localStorage.setItem(KEY, theme); } catch (e) { /* privado */ }
    }

    function save(url, theme) {
        if (!url || !window.fetch) {
            return;
        }
        // GET pega o token; POST grava com ele. Dois fetches sequenciais
        // (nunca paralelos: um token por vez — T143).
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.csrf) {
                    return;
                }
                var fd = new FormData();
                fd.append('_glpi_csrf_token', j.csrf);
                fd.append('pref_theme', theme);
                return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd,
                    headers: { 'Accept': 'application/json' } });
            })
            .catch(function () { /* best-effort: o localStorage já segura */ });
    }

    /**
     * 16q-1b: "tela cheia" — o quadro (.taskplus-board) e as colunas da
     * Hoje (.taskplus-columns > *) ganham a altura exata que sobra até o
     * fim da janela, e a rolagem acontece DENTRO deles, não na página.
     * Altura fixa em CSS não serve: o cabeçalho do GLPI varia por tema/
     * layout. Recalcula ao redimensionar; min 320px para tela baixa.
     */
    function fit() {
        var pad = 20;
        var boards = document.querySelectorAll('.taskplus-board');
        var cols = document.querySelectorAll('.taskplus-columns > *');
        var i, top, h;
        for (i = 0; i < boards.length; i++) {
            top = boards[i].getBoundingClientRect().top + window.scrollY;
            h = Math.max(320, window.innerHeight - top - pad);
            boards[i].style.height = h + 'px';
        }
        for (i = 0; i < cols.length; i++) {
            cols[i].classList.add('taskplus-fit');
            top = cols[i].getBoundingClientRect().top + window.scrollY;
            h = Math.max(320, window.innerHeight - top - pad);
            cols[i].style.maxHeight = h + 'px';
        }
    }

    function init() {
        var shell = document.getElementById('taskplus-shell');
        if (!shell) {
            return;
        }
        fit();
        window.addEventListener('resize', fit);
        // as telas montam a barra de ferramentas DEPOIS (buildToolbar no init
        // delas, que roda após este listener): mais uma passada em seguida.
        window.setTimeout(fit, 0);
        window.setTimeout(fit, 300);
        var btn = document.getElementById('taskplus-theme');
        if (!btn) {
            return;
        }
        apply(current());
        btn.addEventListener('click', function () {
            var next = current() === 'light' ? 'dark' : 'light';
            apply(next);
            save(shell.getAttribute('data-prefs-url'), next);
        });
    }

    window.TaskplusShell = { apply: apply, current: current, fit: fit };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
