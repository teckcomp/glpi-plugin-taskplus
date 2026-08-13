/**
 * Task+ — sino de alertas da sidebar (Etapa 7a; padrão do ProjectPlus).
 *
 * Carregado em TODAS as páginas do GLPI (hook add_javascript, mesma
 * filosofia do CSS global): a guarda do init() o torna inerte fora das
 * telas do Task+ — o markup do sino só existe na sidebar do plugin.
 *
 * Contrato com ajax/alerts.php:
 *  - GET  action=list  → {success, data:{unread:[],read:[]}, csrf}
 *    (é do LIST que vem o PRIMEIRO token — a sidebar não embute csrf);
 *  - POST action=read|read_all (+_glpi_csrf_token) → mesma resposta.
 *  - Toda resposta re-renderiza o sino inteiro e ROTACIONA o token.
 *
 * Invariantes da casa: sem dependências; DOM SEMPRE via createElement +
 * textContent (nunca innerHTML com dado do servidor); Array.isArray na
 * defesa do payload.
 */
(function () {
    'use strict';

    var POLL_MS = 60000; // badge se atualiza sozinho a cada minuto

    function init() {
        var wrap = document.getElementById('taskplus-bell-wrap');
        var bell = document.getElementById('taskplus-bell');
        var panel = document.getElementById('taskplus-bell-panel');
        if (!wrap || !bell || !panel) {
            return; // página sem o sino (fora do Task+)
        }
        if (wrap.getAttribute('data-bell-init') === '1') {
            return; // já inicializado (rodapé + DOMContentLoaded + harness)
        }
        wrap.setAttribute('data-bell-init', '1');

        var url = wrap.getAttribute('data-alerts-url') || '';
        var badge = bell.querySelector('.taskplus-bell__badge');
        var list = panel.querySelector('.taskplus-bell__list');
        var readAll = panel.querySelector('.taskplus-bell__readall');
        if (!url || !badge || !list) {
            return;
        }

        var state = { csrf: '', busy: false };

        function absorb(res) {
            if (res && typeof res.csrf === 'string' && res.csrf !== '') {
                state.csrf = res.csrf;
            }
            if (res && res.data) {
                render(res.data);
            }
        }

        function refresh() {
            // 9e-2: sem o Accept o core devolve a página de erro em HTML, o
            // resp.json() abaixo quebra e o usuário vê "Falha de comunicação"
            // em vez da mensagem real (inclusive na sessão expirada).
            fetch(url + '?action=list', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(absorb)
                .catch(function () { /* silencioso; próxima rodada tenta de novo */ });
        }

        function post(fields) {
            if (state.busy) {
                return;
            }
            state.busy = true;

            var fd = new FormData();
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
            fd.append('_glpi_csrf_token', state.csrf);

            // 9e-2: sem o Accept o core devolve a página de erro em HTML, o
            // resp.json() abaixo quebra e o usuário vê "Falha de comunicação"
            // em vez da mensagem real (inclusive na sessão expirada).
            fetch(url, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    state.busy = false;
                    absorb(res);
                })
                .catch(function () {
                    state.busy = false;
                    refresh(); // ressincroniza (inclusive o token)
                });
        }

        function fmtStamp(raw) {
            // 'AAAA-MM-DD HH:MM:SS' → 'DD/MM HH:MM' (sem Date/fuso)
            var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(raw || ''));
            if (!m) {
                return '';
            }
            return m[3] + '/' + m[2] + ' ' + m[4] + ':' + m[5];
        }

        function bellItem(a, isRead) {
            var li = document.createElement('li');
            li.className = 'taskplus-bell__item' + (isRead ? ' taskplus-bell__item--read' : '');
            li.setAttribute('data-alert-id', String(a.id));

            var body = document.createElement('div');
            body.className = 'taskplus-bell__body';

            var msg = document.createElement('span');
            msg.className = 'taskplus-bell__msg';
            msg.textContent = String(a.message || '');
            body.appendChild(msg);

            var when = document.createElement('em');
            when.textContent = fmtStamp(a.date_creation);
            body.appendChild(when);

            li.appendChild(body);

            if (!isRead) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'taskplus-bell__read';
                btn.title = __('Marcar como lida');
                btn.textContent = '✓';
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    post({ action: 'read', id: String(a.id) });
                });
                li.appendChild(btn);
            }

            return li;
        }

        function render(data) {
            var unread = (data && Array.isArray(data.unread)) ? data.unread : [];
            var read = (data && Array.isArray(data.read)) ? data.read : [];
            var n = unread.length;

            badge.hidden = n === 0;
            badge.textContent = n > 99 ? '99+' : String(n);
            if (readAll) {
                readAll.disabled = n === 0;
            }

            while (list.firstChild) {
                list.removeChild(list.firstChild);
            }

            if (n === 0) {
                var empty = document.createElement('li');
                empty.className = 'taskplus-bell__empty';
                empty.textContent = __('Nenhum alerta não lido');
                list.appendChild(empty);
            } else {
                unread.forEach(function (a) { list.appendChild(bellItem(a, false)); });
            }

            if (read.length > 0) {
                var section = document.createElement('li');
                section.className = 'taskplus-bell__section';
                section.textContent = __('Lidas recentemente');
                list.appendChild(section);
                read.forEach(function (a) { list.appendChild(bellItem(a, true)); });
            }
        }

        // i18n mínima local: o bell.js roda fora dos bundles das telas
        function __(s) {
            return s;
        }

        bell.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            if (!panel.hidden) {
                refresh();
            }
        });

        if (readAll) {
            readAll.addEventListener('click', function () {
                post({ action: 'read_all' });
            });
        }

        document.addEventListener('click', function (e) {
            if (!panel.hidden && !panel.contains(e.target) && !bell.contains(e.target)) {
                panel.hidden = true;
            }
        });

        refresh(); // badge já carrega com a página (e traz o 1º token)
        setInterval(refresh, POLL_MS);
    }

    // jsdom com runScripts 'outside-only' não dispara DOMContentLoaded
    // (T14): o harness chama window.TaskplusBell.init() na mão.
    window.TaskplusBell = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
