/**
 * Task+ — tela "Semana" (Etapa 6a: grade seg–dom, SÓ leitura).
 *
 * Contrato com o servidor:
 *  - estado inicial embutido em <script type="application/json"
 *    id="taskplus-week-data"> (gerado pelo front/week.php com
 *    JSON_HEX_* — seguro contra </script>);
 *  - navegação de semana vai por POST a ajax/week.php com
 *    `_glpi_csrf_token` e `anchor` (o week.prev/week.next ecoado pelo
 *    payload anterior); a resposta traz `csrf` NOVO (rotação
 *    obrigatória — o token é de uso único) e `data` com o payload da
 *    semana pedida, para re-render;
 *  - busca é 100% local (título+descrição+categoria, sem acento),
 *    padrão do 5b-2 p2 — nunca vai ao servidor;
 *  - defesa de cliente: Array.isArray em toda lista vinda do servidor
 *    (variável ausente vira null → .map() quebraria a tela).
 *
 * Sem dependências. Todo texto de usuário entra no DOM via textContent
 * (nunca innerHTML) — sem XSS por nome/descrição.
 */
(function () {
    'use strict';

    var state = {
        root: null,
        userId: 0, // 13d
        showAll: false, // 16k: mesma preferência dos quadros/Hoje
        ajaxUrl: '',
        todayUrl: '',
        csrf: '',
        // Âncora da semana exibida: '' = semana corrente (o servidor
        // resolve); senão, uma data 'Y-m-d' dentro da semana desejada.
        anchor: '',
        search: '',
        busy: false,
        data: {
            date: '',
            kpis: { late: 0, today: 0, pending: 0, done: 0 },
            week: { start: '', end: '', label: '', prev: '', next: '', is_current: true },
            days: []
        }
    };

    function $(id) {
        return document.getElementById(id);
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) {
            e.className = cls;
        }
        if (text !== undefined && text !== null && text !== '') {
            e.textContent = text;
        }
        return e;
    }

    // ------------------------------------------------------------------
    // 12a — links clicáveis no texto livre da tarefa
    // ------------------------------------------------------------------

    /**
     * Preenche `node` com `text`, transformando URLs em <a> que abrem em
     * NOVA GUIA. Seguro por construção: texto entra por createTextNode,
     * o link por createElement + href atribuído — nunca innerHTML. Só
     * http(s) e www. viram link (javascript:, data: e afins ficam texto,
     * porque a regex nem os reconhece). Pontuação final colada na URL
     * ("veja http://x.com/a.") fica fora do link.
     */
    function linkify(node, text) {
        var s = String(text || '');
        var re = /(https?:\/\/[^\s<>"']+|www\.[^\s<>"']+)/gi;
        var last = 0;
        var m;
        while ((m = re.exec(s)) !== null) {
            var url = m[0];
            var trail = '';
            var t = url.match(/[.,;:!?)\]}]+$/);
            if (t) {
                trail = t[0];
                url = url.slice(0, url.length - trail.length);
            }
            if (url === '' || /^(https?:\/\/|www\.)$/i.test(url)) {
                continue;
            }
            if (m.index > last) {
                node.appendChild(document.createTextNode(s.slice(last, m.index)));
            }
            var a = document.createElement('a');
            a.href = /^www\./i.test(url) ? 'https://' + url : url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.className = 'taskplus-link';
            a.textContent = url;
            a.addEventListener('click', function (ev) {
                ev.stopPropagation(); // clique no link não aciona o card
            });
            node.appendChild(a);
            last = m.index + m[0].length - trail.length;
            re.lastIndex = last;
        }
        if (last < s.length) {
            node.appendChild(document.createTextNode(s.slice(last)));
        }
        return node;
    }

    /**
     * Normaliza o payload do servidor. Qualquer coisa fora do esperado
     * vira estrutura vazia — a tela nunca quebra por JSON ruim.
     */
    function safeData(raw) {
        var d = (raw && typeof raw === 'object') ? raw : {};
        var k = (d.kpis && typeof d.kpis === 'object') ? d.kpis : {};
        var w = (d.week && typeof d.week === 'object') ? d.week : {};
        return {
            date: (typeof d.date === 'string') ? d.date : '',
            kpis: {
                late: Number(k.late) || 0,
                today: Number(k.today) || 0,
                pending: Number(k.pending) || 0,
                done: Number(k.done) || 0
            },
            week: {
                start: (typeof w.start === 'string') ? w.start : '',
                end: (typeof w.end === 'string') ? w.end : '',
                label: (typeof w.label === 'string') ? w.label : '',
                prev: (typeof w.prev === 'string') ? w.prev : '',
                next: (typeof w.next === 'string') ? w.next : '',
                is_current: (w.is_current === undefined) ? true : !!w.is_current
            },
            days: Array.isArray(d.days) ? d.days.map(safeDay) : [],
            // 16k
            show_all: !!d.show_all,
            beyond: Array.isArray(d.beyond) ? d.beyond : [],
            prefs: (d.prefs && typeof d.prefs === 'object') ? safePrefs(d.prefs) : ((state.data && state.data.prefs) ? state.data.prefs : safePrefs(null)) // 16l
        };
    }

    function safeDay(raw) {
        var d = (raw && typeof raw === 'object') ? raw : {};
        return {
            date: (typeof d.date === 'string') ? d.date : '',
            date_label: (typeof d.date_label === 'string') ? d.date_label : '',
            dow_label: (typeof d.dow_label === 'string') ? d.dow_label : '',
            is_today: !!d.is_today,
            items: Array.isArray(d.items) ? d.items : []
        };
    }

    // ------------------------------------------------------------------
    // Toast de feedback
    // ------------------------------------------------------------------

    function toast(msg, isError) {
        var t = el('div', 'taskplus-toast' + (isError ? ' taskplus-toast--error' : ''), msg);
        document.body.appendChild(t);
        window.setTimeout(function () {
            if (t.parentNode) {
                t.parentNode.removeChild(t);
            }
        }, 4000);
    }

    // ------------------------------------------------------------------
    // Comunicação com o servidor
    // ------------------------------------------------------------------

    function post(fields) {
        if (state.busy) {
            return;
        }
        state.busy = true;

        var fd = new FormData();
        Object.keys(fields).forEach(function (key) {
            fd.append(key, fields[key]);
        });
        fd.append('_glpi_csrf_token', state.csrf);
        // A âncora acompanha o POST quando não é a semana corrente: a
        // resposta devolve a MESMA semana que o usuário está vendo.
        if (state.anchor !== '') {
            fd.append('anchor', state.anchor);
        }
        if (state.showAll) { // 16k
            fd.append('show_all', '1');
        }

        // 9e-2: sem o Accept o core devolve a página de erro em HTML, o
        // resp.json() abaixo quebra e o usuário vê "Falha de comunicação"
        // em vez da mensagem real (inclusive na sessão expirada).
        fetch(state.ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (resp) {
                // 16i (fila 8, revê a nº 49): sessão expirada → o core
                // responde 401; recarregar leva ao login e, depois, de volta
                // a esta tela. Antes ficava só o toast "sessão expirada".
                if (resp.status === 401) {
                    window.location.reload();
                    return new Promise(function () {}); // nada mais a fazer
                }
                return resp.json();
            })
            .then(function (res) {
                state.busy = false;
                // Rotação do token: o que foi enviado já era (uso único)
                if (res && typeof res.csrf === 'string' && res.csrf !== '') {
                    state.csrf = res.csrf;
                }
                if (res && res.data) {
                    state.data = safeData(res.data);
                }
                if (!res || !res.success) {
                    toast((res && res.message) ? res.message : 'Erro ao processar a ação', true);
                    render();
                    return;
                }
                render();
            })
            .catch(function () {
                state.busy = false;
                toast('Falha de comunicação com o servidor', true);
            });
    }

    /** Navega para a semana da âncora ('' = corrente). */
    function goTo(anchor) {
        state.anchor = anchor || '';
        post({ action: 'list' });
    }

    // ------------------------------------------------------------------
    // Busca local (padrão 5b-2 p2)
    // ------------------------------------------------------------------

    /** Minúsculas e sem acentos: "Relatório" casa com "relatorio". */
    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    /** O item casa com a busca (título + descrição + categoria)? */
    function matchesSearch(item) {
        if (state.search === '') {
            return true;
        }
        var q = norm(state.search);
        return norm(item.name).indexOf(q) !== -1
            || norm(item.description).indexOf(q) !== -1
            || norm(item.category).indexOf(q) !== -1;
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------

    function render() {
        renderBar();
        renderKpis();
        renderGrid();
    }

    function renderBar() {
        var label = $('tp-w-label');
        if (label) {
            label.textContent = state.data.week.label
                + (state.data.week.is_current ? ' (esta semana)' : '');
        }
        var current = $('tp-w-current');
        if (current) {
            current.hidden = !!state.data.week.is_current;
        }
    }

    function renderKpis() {
        var k = state.data.kpis;
        var map = {
            'tp-wk-late': k.late,
            'tp-wk-open': k.today,
            'tp-wk-pending': k.pending,
            'tp-wk-done': k.done
        };
        Object.keys(map).forEach(function (id) {
            var e = $(id);
            if (e) {
                e.textContent = String(map[id]);
            }
        });
    }

    function renderGrid() {
        var grid = $('tp-w-grid');
        if (!grid) {
            return;
        }
        grid.textContent = '';

        renderBeyond(); // 16k
        state.data.days.forEach(function (day) {
            grid.appendChild(dayColumn(day));
        });
    }

    function dayColumn(day) {
        var col = el('div', 'taskplus-wday' + (day.is_today ? ' taskplus-wday--today' : ''));

        var head = el('div', 'taskplus-wday__head');
        var title = el('div', 'taskplus-wday__title');
        title.appendChild(el('span', 'taskplus-wday__dow', day.dow_label));
        title.appendChild(el('span', 'taskplus-wday__date', day.date_label));
        head.appendChild(title);

        var items = day.items.filter(matchesSearch);
        head.appendChild(el('span', 'taskplus-section__count', String(items.length)));

        // Atalho só no dia de HOJE: é o único dia que a tela Hoje mostra
        if (day.is_today && state.todayUrl !== '') {
            var link = document.createElement('a');
            link.className = 'taskplus-wday__link';
            link.href = state.todayUrl;
            link.title = 'Abrir na tela Hoje';
            link.setAttribute('aria-label', 'Abrir na tela Hoje');
            link.appendChild(el('i', 'ti ti-external-link'));
            head.appendChild(link);
        }
        col.appendChild(head);

        var list = el('div', 'taskplus-wday__list');
        if (items.length === 0) {
            list.appendChild(el('div', 'taskplus-wday__empty',
                state.search !== '' && day.items.length > 0 ? 'nada na busca' : '—'));
        } else {
            items.forEach(function (item) {
                list.appendChild(chip(item));
            });
        }
        col.appendChild(list);

        return col;
    }

    // ------------------------------------------------------------------
    // 16k — barra de prazo (cópia do board.js) e "Ver tudo"
    // ------------------------------------------------------------------
    function deadlineBar(item) {
        var pct = Math.max(0, Math.min(100, Number(item.deadline_pct) || 0));
        var tone = pct >= 85 ? 'red' : (pct >= 60 ? 'amber' : 'green');
        var box = el('div', 'taskplus-bcard__deadline taskplus-bcard__deadline--' + tone);
        var track = el('div', 'taskplus-bcard__deadline-track');
        var fill = el('div', 'taskplus-bcard__deadline-fill');
        fill.style.width = pct + '%';
        track.appendChild(fill);
        box.appendChild(track);
        box.appendChild(el('span', 'taskplus-bcard__deadline-label', item.deadline_label || ''));
        box.title = 'Prazo ' + pct + '% consumido';
        return box;
    }


    // ------------------------------------------------------------------
    // 16l (nº 79) — preferência vem do SERVIDOR (payload.prefs); o
    // localStorage fica como fallback para quem ainda não tem linha
    // (known=false): nesse caso o primeiro `list` leva pref_save e migra.
    // ------------------------------------------------------------------
    function safePrefs(raw) {
        var p = (raw && typeof raw === 'object') ? raw : {};
        return {
            known: !!p.known,
            show_all: !!p.show_all,
            fav_groups_id: Number(p.fav_groups_id) || 0
        };
    }

    /** Estado inicial do Ver tudo: servidor se conhecido, senão navegador. */
    function initialShowAll(prefs) {
        if (prefs.known) {
            return prefs.show_all;
        }
        return readShowAll();
    }

    function showAllKey() {
        return 'taskplus.board.all.' + String(state.userId || 0);
    }

    function readShowAll() {
        try {
            return window.localStorage.getItem(showAllKey()) === '1';
        } catch (e) {
            return false;
        }
    }

    function setShowAll(on) {
        state.showAll = !!on;
        try {
            if (state.showAll) {
                window.localStorage.setItem(showAllKey(), '1');
            } else {
                window.localStorage.removeItem(showAllKey());
            }
        } catch (e) {
            // storage bloqueado: vale só nesta página
        }
    }

    function syncShowAllButton() {
        var all = $('tp-w-all');
        if (!all) {
            return;
        }
        all.classList.toggle('taskplus-toolbar2__all--on', state.showAll);
        all.setAttribute('aria-pressed', state.showAll ? 'true' : 'false');
        all.textContent = state.showAll ? 'Vendo tudo' : 'Ver tudo';
        all.title = state.showAll
            ? 'Mostrando também as tarefas abertas com prazo depois desta semana'
            : 'Mostrar também as tarefas abertas com prazo depois desta semana';
    }

    /** 16k: seção "Depois desta semana" — só com Ver tudo e com itens. */
    function renderBeyond() {
        var box = $('tp-w-beyond');
        if (!box) {
            return;
        }
        box.textContent = '';
        var items = state.showAll
            ? state.data.beyond.filter(matchesSearch)
            : [];
        if (items.length === 0) {
            box.hidden = true;
            return;
        }
        var head = el('div', 'taskplus-wbeyond__head');
        head.appendChild(el('span', null, 'Depois desta semana'));
        head.appendChild(el('span', 'taskplus-section__count', String(items.length)));
        box.appendChild(head);
        var list = el('div', 'taskplus-wbeyond__list');
        items.forEach(function (item) {
            var c = chip(item);
            c.insertBefore(el('div', 'taskplus-wbeyond__date', item.date_label || ''), c.firstChild);
            list.appendChild(c);
        });
        box.appendChild(list);
        box.hidden = false;
    }

    /**
     * Card compacto de um dia. SÓ leitura: sem check, sem ações — as
     * classes de status e os badges são os mesmos das outras telas.
     */
    function chip(item) {
        var c = el('div', 'taskplus-wcard'
            + (item.is_pending ? ' taskplus-wcard--pending' : '')
            + (item.is_done ? ' taskplus-wcard--done' : '')
            + (item.is_late ? ' taskplus-wcard--late' : ''));

        c.appendChild(el('div', 'taskplus-wcard__name', item.name || '(sem título)'));
        // 12c: descrição no card, até 4 linhas (o CSS corta o resto)
        if (item.description) {
            c.appendChild(linkify(el('div', 'taskplus-wcard__desc'), item.description));
        }

        var badges = el('div', 'taskplus-card__badges');
        if (item.time_limit) {
            badges.appendChild(el('span',
                'taskplus-badge' + (item.is_late ? ' taskplus-badge--late' : ' taskplus-badge--limit'),
                'até ' + item.time_limit));
        }
        if (item.is_routine) {
            badges.appendChild(el('span', 'taskplus-badge', 'rotina'));
        }
        if (item.is_team) {
            // 13d: tarefa de equipe do colaborador (decisão nº 65)
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--sector', 'equipe · ' + (item.group_name || 'setor')));
        }
        if (item.category) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--category', item.category));
        }
        if (item.is_pending) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--pending',
                item.pending_label || 'pendente'));
            if (item.pending_reason) {
                badges.appendChild(el('span', 'taskplus-badge', item.pending_reason));
            }
            if (item.pending_by_other && item.pending_by_label) {
                badges.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                    'pelo gestor ' + item.pending_by_label));
            }
        }
        if (item.is_done && item.done_time) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--done', 'às ' + item.done_time));
        }
        if (item.is_done && item.done_by_other && item.done_by_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                'pelo gestor ' + item.done_by_label));
        }
        if (item.created_by_other && item.created_by_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                'criada pelo gestor ' + item.created_by_label));
        }
        if (badges.childNodes.length > 0) {
            c.appendChild(badges);
        }
        // 16k (nº 71): mesma leitura dos quadros — barra de prazo em
        // aberta não atrasada; faixa "Atrasada · há N" em atrasada.
        if (!item.is_late && !item.is_pending && !item.is_done && item.deadline_label) {
            c.appendChild(deadlineBar(item));
        }
        if (item.is_late && !item.is_pending && !item.is_done) {
            var late = el('div', 'taskplus-bcard__late');
            late.appendChild(el('i', 'ti ti-alert-triangle'));
            late.appendChild(document.createTextNode('\u00a0Atrasada' + (item.late_label ? ' \u00b7 ' + item.late_label : '')));
            c.appendChild(late);
        }

        if (item.is_team) {
            var collab = el('div', 'taskplus-bcard__collab');
            (Array.isArray(item.collaborators) ? item.collaborators : []).forEach(function (p) {
                collab.appendChild(el('span', 'taskplus-chip' + (Number(p.id) === state.userId ? ' taskplus-chip--me' : ''), p.label || ''));
            });
            if (collab.childNodes.length > 0) {
                c.appendChild(collab);
            }
            if (item.team_url) {
                var link = document.createElement('a');
                link.className = 'taskplus-wcard__teamlink';
                link.href = item.team_url;
                link.textContent = 'abrir no Quadro de Equipe';
                c.appendChild(link);
            }
        }

        return c;
    }

    // ------------------------------------------------------------------
    // Init
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-week');
        if (!state.root) {
            return; // não está na tela Semana
        }
        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.userId = Number(state.root.getAttribute('data-user-id')) || 0; // 13d
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';
        state.todayUrl = state.root.getAttribute('data-today-url') || '';

        var raw = null;
        var dataEl = $('taskplus-week-data');
        if (dataEl) {
            try {
                raw = JSON.parse(dataEl.textContent);
            } catch (e) {
                raw = null; // JSON ruim → estrutura vazia, tela não quebra
            }
        }
        state.data = safeData(raw);

        var prev = $('tp-w-prev');
        if (prev) {
            prev.addEventListener('click', function () {
                goTo(state.data.week.prev);
            });
        }
        var next = $('tp-w-next');
        if (next) {
            next.addEventListener('click', function () {
                goTo(state.data.week.next);
            });
        }
        var current = $('tp-w-current');
        if (current) {
            current.addEventListener('click', function () {
                goTo('');
            });
        }
        var search = $('tp-w-search');
        if (search) {
            search.addEventListener('input', function () {
                state.search = search.value.trim();
                renderGrid();
            });
        }
        // 16k: "Ver tudo" (preferência por pessoa, a mesma dos quadros)
        state.showAll = initialShowAll(state.data.prefs); // 16l
        var all = $('tp-w-all');
        if (all) {
            all.addEventListener('click', function () {
                setShowAll(!state.showAll);
                syncShowAllButton();
                post({ action: 'list', pref_save: '1' }); // 16l
            });
        }
        syncShowAllButton();

        render();
        if (state.showAll) {
            // o payload embutido não traz `beyond`; sem linha no servidor, migra
            post(state.data.prefs.known ? { action: 'list' } : { action: 'list', pref_save: '1' });
        }
    }

    // Exposto para o harness (jsdom outside-only não dispara
    // DOMContentLoaded — lição T14) e para depuração.
    window.TaskplusWeek = { init: init, chip: chip, renderBeyond: renderBeyond, state: state };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
