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
            days: Array.isArray(d.days) ? d.days.map(safeDay) : []
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

        // 9e-2: sem o Accept o core devolve a página de erro em HTML, o
        // resp.json() abaixo quebra e o usuário vê "Falha de comunicação"
        // em vez da mensagem real (inclusive na sessão expirada).
        fetch(state.ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (resp) { return resp.json(); })
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

        var badges = el('div', 'taskplus-card__badges');
        if (item.time_limit) {
            badges.appendChild(el('span',
                'taskplus-badge' + (item.is_late ? ' taskplus-badge--late' : ' taskplus-badge--limit'),
                'até ' + item.time_limit));
        }
        if (item.is_late && !item.time_limit) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--late', 'atrasada'));
        }
        if (item.is_routine) {
            badges.appendChild(el('span', 'taskplus-badge', 'rotina'));
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

        render();
    }

    // Exposto para o harness (jsdom outside-only não dispara
    // DOMContentLoaded — lição T14) e para depuração.
    window.TaskplusWeek = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
