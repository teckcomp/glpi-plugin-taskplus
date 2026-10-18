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

    /**
     * 16q-4: a linha do título recebe os controles "de tela" que as telas
     * montam na barra de filtros — interruptores e botão primário à direita,
     * seletor de setor ao lado do título — como no mockup aprovado. Não
     * edita os JS de tela: observa .taskplus-main e MOVE os nós (os
     * listeners vão junto). Regra para tela que recria a barra a cada
     * render (teamboard.js): um nó por seletor em cada área — o que chega
     * novo substitui o que estava lá.
     */
    var MOVE_RIGHT = [
        '.taskplus-block__tools:has(#tp-btn-new)', // Hoje: Ver tudo + Mostrar concluídas + Nova tarefa
        '.taskplus-toolbar > .taskplus-toolbar__toggle', // Rotinas: Mostrar pausadas
        '.taskplus-toolbar > .btn-primary',       // Rotinas: Nova rotina
        '.taskplus-weeknav',                      // Semana: ‹ Esta semana › (16q-12)
        '.taskplus-toolbar2__all',                // Quadro/Equipe/Semana: Ver tudo
        '.taskplus-toolbar2__new',                // Quadro/Equipe: Nova tarefa
        '#tp-p-view', '#tp-h-view',               // Painel/Histórico: ver de quem (16q-13/15)
        '#tp-p-export', '#tp-h-export'            // Painel/Histórico: Exportar CSV
    ];
    var MOVE_MID = ['.taskplus-toolbar2__sector']; // Quadro de Equipe: setor + ★ + papel

    function moveInto(main, areaId, selectors) {
        var area = document.getElementById(areaId);
        if (!area) {
            return;
        }
        selectors.forEach(function (sel) {
            var fresh = main.querySelector(sel);
            if (!fresh) {
                return;
            }
            var old = area.querySelector(sel);
            if (old && old !== fresh) {
                old.parentNode.removeChild(old);
            }
            fresh.setAttribute('data-tp-moved', sel);
            area.appendChild(fresh);
        });
        // ordem estável = ordem da lista (um nó movido antes não fica fora de lugar)
        selectors.forEach(function (sel) {
            var n = area.querySelector(':scope > ' + sel);
            if (n && n !== area.lastElementChild) {
                area.appendChild(n);
            }
        });
    }

    function relocate(main) {
        moveInto(main, 'taskplus-ctx-right', MOVE_RIGHT);
        moveInto(main, 'taskplus-ctx-mid', MOVE_MID);
        // Mês/dia de hoje no subtítulo da Hoje (o servidor não manda)
        var sub = document.getElementById('taskplus-ctx-sub');
        var ctx = sub ? sub.closest('.taskplus-ctx') : null;
        if (sub && ctx && ctx.getAttribute('data-screen') === 'today' && !sub.textContent) {
            try {
                var d = new Date();
                var txt = d.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });
                sub.textContent = txt.charAt(0).toUpperCase() + txt.slice(1);
            } catch (e) { /* locale indisponível: fica vazio */ }
        }
    }

    function init() {
        var shell = document.getElementById('taskplus-shell');
        if (!shell) {
            return;
        }
        var main = document.querySelector('.taskplus-app .taskplus-main');
        if (main && window.MutationObserver) {
            relocate(main);
            var busy = false;
            new MutationObserver(function () {
                if (busy) {
                    return;
                }
                busy = true;
                window.setTimeout(function () {
                    busy = false;
                    relocate(main);
                    fit();
                }, 0);
            }).observe(main, { childList: true, subtree: true });
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

    window.TaskplusShell = { apply: apply, current: current, fit: fit, relocate: relocate };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
