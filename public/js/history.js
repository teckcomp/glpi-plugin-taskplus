/**
 * Task+ — tela "Histórico" (6c-1: trilha auditável · 6c-2: restaurar).
 *
 * Contrato com o servidor:
 *  - estado inicial embutido em <script type="application/json"
 *    id="taskplus-history-data"> (gerado pelo front/history.php com
 *    JSON_HEX_* — seguro contra </script>);
 *  - mudança de período vai por POST a ajax/history.php com
 *    `_glpi_csrf_token` e `period_from`/`period_to` (mesmos nomes do
 *    5b-2 p2); a resposta traz `csrf` NOVO (rotação obrigatória — o
 *    token é de uso único) e `data` com o payload do período pedido,
 *    para re-render. O servidor é a fonte da verdade do recorte:
 *    default de 30 dias, teto de 180 (flag `clamped`);
 *  - 9b-1: o POST leva também `view_kind` + `view_id` ('self' = meu
 *    histórico, 'user' + id = trilha de um técnico). O front NÃO decide
 *    escopo — manda o pedido e obedece ao `view` da resposta, que diz
 *    o que o servidor realmente leu e se a tela pode agir
 *    (`can_restore`);
 *  - ÚNICA ação da tela (6c-2): "Restaurar" nos cards EXCLUÍDA — POST
 *    `restore` + id; posse e estado são revalidados no SERVIDOR (T18)
 *    e a resposta volta com o payload completo, então o re-render é
 *    integral (badge troca, contadores se corrigem, busca reaplica);
 *  - busca 100% LOCAL (título/descrição/categoria, sem acento): item
 *    fora da busca some e o cabeçalho do dia some junto quando o dia
 *    esvazia;
 *  - defesa de cliente: Array.isArray em toda lista vinda do servidor
 *    (variável ausente vira null → .map() quebraria a tela).
 *
 * Sem dependências. Todo texto de usuário entra no DOM via textContent
 * (nunca innerHTML) — sem XSS por nome de tarefa.
 */
(function () {
    'use strict';

    var state = {
        root: null,
        ajaxUrl: '',
        csrf: '',
        // Recorte exibido: '' + '' = default do servidor (últimos 30)
        period: { from: '', to: '' },
        // 9b-1: alvo pedido, no formato "kind:id" ('self:0' = meu
        // histórico). Quem manda é a resposta do servidor — o syncView
        // reescreve isto a cada render.
        view: 'self:0',
        search: '',
        busy: false,
        data: emptyData()
    };

    function emptyData() {
        return {
            date: '',
            period: { from: '', to: '', label: '', days: 0, clamped: false },
            totals: { all: 0, done: 0, pending: 0, late: 0, open: 0, skipped: 0, deleted: 0 },
            days: [],
            // 9b-1: alvo exibido + opções do gestor (vazias para quem
            // não administra setor — sem seletor na tela)
            view: {
                kind: 'self', id: 0, label: '', is_self: true,
                denied: false, can_restore: true, options: []
            }
        };
    }

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
        var p = (d.period && typeof d.period === 'object') ? d.period : {};
        var t = (d.totals && typeof d.totals === 'object') ? d.totals : {};
        return {
            date: (typeof d.date === 'string') ? d.date : '',
            period: {
                from: (typeof p.from === 'string') ? p.from : '',
                to: (typeof p.to === 'string') ? p.to : '',
                label: (typeof p.label === 'string') ? p.label : '',
                days: Number(p.days) || 0,
                clamped: !!p.clamped
            },
            totals: {
                all: Number(t.all) || 0,
                done: Number(t.done) || 0,
                pending: Number(t.pending) || 0,
                late: Number(t.late) || 0,
                open: Number(t.open) || 0,
                skipped: Number(t.skipped) || 0,
                deleted: Number(t.deleted) || 0
            },
            days: Array.isArray(d.days) ? d.days.map(safeDay) : [],
            view: safeView(d.view)
        };
    }

    function safeView(raw) {
        var v = (raw && typeof raw === 'object') ? raw : {};
        return {
            kind: (v.kind === 'user') ? 'user' : 'self',
            id: Number(v.id) || 0,
            label: (typeof v.label === 'string') ? v.label : '',
            // Ausente = próprio: o modo "outro técnico" só existe se o
            // servidor disser explicitamente que não é o próprio.
            is_self: (v.is_self === undefined) ? true : !!v.is_self,
            denied: !!v.denied,
            // Ausente = pode: payload antigo (aba aberta antes da
            // atualização) mantém o restaurar do próprio funcionando.
            can_restore: (v.can_restore === undefined) ? true : !!v.can_restore,
            options: Array.isArray(v.options) ? v.options.map(safeViewOption) : []
        };
    }

    function safeViewOption(raw) {
        var o = (raw && typeof raw === 'object') ? raw : {};
        return {
            kind: 'user',
            id: Number(o.id) || 0,
            label: (typeof o.label === 'string') ? o.label : '',
            groups: (typeof o.groups === 'string') ? o.groups : ''
        };
    }

    function safeDay(raw) {
        var day = (raw && typeof raw === 'object') ? raw : {};
        return {
            date: (typeof day.date === 'string') ? day.date : '',
            label: (typeof day.label === 'string') ? day.label : '',
            is_today: !!day.is_today,
            items: Array.isArray(day.items) ? day.items.map(safeItem) : []
        };
    }

    var STATES = ['deleted', 'skipped', 'done', 'pending', 'late', 'open'];

    function safeItem(raw) {
        var i = (raw && typeof raw === 'object') ? raw : {};
        var stateKey = (typeof i.state === 'string' && STATES.indexOf(i.state) !== -1)
            ? i.state : 'open';
        return {
            id: Number(i.id) || 0,
            is_routine: !!i.is_routine,
            routine_name: (typeof i.routine_name === 'string') ? i.routine_name : '',
            name: (typeof i.name === 'string') ? i.name : '',
            description: (typeof i.description === 'string') ? i.description : '',
            category: (typeof i.category === 'string') ? i.category : '',
            date: (typeof i.date === 'string') ? i.date : '',
            date_label: (typeof i.date_label === 'string') ? i.date_label : '',
            time_limit: (typeof i.time_limit === 'string') ? i.time_limit : null,
            state: stateKey,
            is_edited: !!i.is_edited,
            done_label: (typeof i.done_label === 'string') ? i.done_label : '',
            done_by_other: !!i.done_by_other,
            done_by_label: (typeof i.done_by_label === 'string') ? i.done_by_label : '',
            skip_reason: (typeof i.skip_reason === 'string') ? i.skip_reason : '',
            skip_label: (typeof i.skip_label === 'string') ? i.skip_label : '',
            is_pending: !!i.is_pending,
            pending_reason: (typeof i.pending_reason === 'string') ? i.pending_reason : '',
            pending_label: (typeof i.pending_label === 'string') ? i.pending_label : '',
            pending_by_other: !!i.pending_by_other,
            pending_by_label: (typeof i.pending_by_label === 'string') ? i.pending_by_label : '',
            created_by_other: !!i.created_by_other,
            created_by_label: (typeof i.created_by_label === 'string') ? i.created_by_label : ''
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
        // O recorte acompanha TODO POST: a resposta devolve o MESMO
        // período que o usuário está vendo ('' + '' = default de 30).
        fd.append('period_from', state.period.from);
        fd.append('period_to', state.period.to);
        // 9b-1: o alvo viaja junto do recorte — trocar o período
        // enquanto se lê a trilha do técnico não pode devolver a
        // própria. Quem valida o par kind+id é o servidor.
        var target = splitView(state.view);
        fd.append('view_kind', target.kind);
        fd.append('view_id', String(target.id));

        fetch(state.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
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
                // Ação com mensagem (restore) confirma no toast; o
                // `list` volta com message vazia e segue silencioso.
                if (typeof res.message === 'string' && res.message !== '') {
                    toast(res.message, false);
                }
                render();
            })
            .catch(function () {
                state.busy = false;
                toast('Falha de comunicação com o servidor', true);
            });
    }

    function applyPeriod() {
        state.period = {
            from: ($('tp-h-from') ? $('tp-h-from').value : '') || '',
            to: ($('tp-h-to') ? $('tp-h-to').value : '') || ''
        };
        post({ action: 'list' });
    }

    function clearPeriod() {
        state.period = { from: '', to: '' };
        post({ action: 'list' });
    }

    // ------------------------------------------------------------------
    // Busca local (título/descrição/categoria, sem acento)
    // ------------------------------------------------------------------

    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

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
        syncView();
        syncToolbar();
        renderTotals();
        renderList();
    }

    /**
     * 9b-1: seletor "Ver histórico de:" + faixa de identificação.
     *
     * A RESPOSTA manda: `view.kind` + id dizem o que o servidor
     * realmente leu. Se o pedido foi recusado (`denied`), o select
     * volta sozinho para "Meu histórico" — a tela nunca fica dizendo
     * que mostra algo que não está mostrando.
     */
    function syncView() {
        var v = state.data.view;
        var selected = viewKey(v);
        state.view = selected;

        var box = $('tp-h-viewbox');
        var select = $('tp-h-view');
        var hasOptions = (v.options.length > 0);

        if (box) {
            box.hidden = !hasOptions;
        }
        if (select) {
            select.textContent = '';
            if (hasOptions) {
                select.appendChild(makeOption('self:0', 'Meu histórico'));
                v.options.forEach(function (o) {
                    select.appendChild(makeOption('user:' + o.id, optionText(o)));
                });
                select.value = selected;
            }
        }

        var note = $('tp-h-viewnote');
        var text = $('tp-h-viewnote-text');
        if (note) {
            note.hidden = v.is_self;
        }
        if (text) {
            text.textContent = v.is_self ? '' : viewNoteText(v);
        }
    }

    /** Chave "kind:id" do alvo exibido, na forma que o select usa. */
    function viewKey(v) {
        return (v.is_self || v.kind !== 'user') ? 'self:0' : ('user:' + v.id);
    }

    function splitView(key) {
        var parts = String(key || 'self:0').split(':');
        var kind = (parts[0] === 'user') ? 'user' : 'self';
        return { kind: kind, id: Number(parts[1]) || 0 };
    }

    /** Rótulo da opção — o texto de interface é do front, não do domínio. */
    function optionText(o) {
        var text = o.label || ('#' + o.id);
        if (o.groups) {
            text += ' (' + o.groups + ')';
        }
        return text;
    }

    function viewNoteText(v) {
        return 'Histórico de ' + (v.label || ('#' + v.id))
            + ' — leitura de gestor: a mesma trilha que ele vê.';
    }

    function makeOption(value, text) {
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = text;
        return opt;
    }

    function changeView() {
        var select = $('tp-h-view');
        state.view = select ? String(select.value || 'self:0') : 'self:0';
        // Alvo novo, busca local zerada: o filtro digitado para a
        // própria trilha não faz sentido sobre a de outra pessoa.
        state.search = '';
        var search = $('tp-h-search');
        if (search) {
            search.value = '';
        }
        post({ action: 'list' });
    }

    /**
     * Espelha o recorte ECOADO pelo servidor (fonte da verdade — ele
     * aplica default, teto e desinverte o par) nos inputs e no aviso.
     */
    function syncToolbar() {
        var p = state.data.period;
        state.period = { from: p.from, to: p.to };

        var from = $('tp-h-from');
        var to = $('tp-h-to');
        if (from) {
            from.value = p.from;
        }
        if (to) {
            to.value = p.to;
        }
        var note = $('tp-h-note');
        if (note) {
            note.hidden = (p.label === '');
            note.textContent = 'Período: ' + p.label + ' (' + p.days + ' dias)'
                + (p.clamped ? ' — recorte limitado a 180 dias' : '');
        }
    }

    function renderTotals() {
        var t = state.data.totals;
        var map = {
            'tp-hk-all': t.all,
            'tp-hk-done': t.done,
            'tp-hk-pending': t.pending,
            'tp-hk-late': t.late,
            'tp-hk-skipped': t.skipped,
            'tp-hk-deleted': t.deleted
        };
        Object.keys(map).forEach(function (id) {
            var e = $(id);
            if (e) {
                e.textContent = String(map[id]);
            }
        });
        var all = $('tp-hk-all');
        if (all) {
            all.title = t.open + ' aberta(s) no período';
        }
    }

    function renderList() {
        var box = $('tp-h-list');
        if (!box) {
            return;
        }
        box.textContent = '';

        var days = state.data.days;
        if (days.length === 0) {
            box.appendChild(el('div', 'taskplus-hist__empty', 'Nada no período.'));
            return;
        }

        var anyShown = false;
        days.forEach(function (day) {
            var items = day.items.filter(matchesSearch);
            if (items.length === 0) {
                return; // busca esvaziou o dia: cabeçalho some junto
            }
            anyShown = true;

            var section = el('div', 'taskplus-hday' + (day.is_today ? ' taskplus-hday--today' : ''));
            var head = el('div', 'taskplus-hday__head');
            head.appendChild(el('span', 'taskplus-hday__label',
                day.label + (day.is_today ? ' (hoje)' : '')));
            head.appendChild(el('span', 'taskplus-section__count', String(items.length)));
            section.appendChild(head);

            var list = el('div', 'taskplus-hday__list');
            items.forEach(function (item) {
                list.appendChild(card(item));
            });
            section.appendChild(list);

            box.appendChild(section);
        });

        if (!anyShown) {
            box.appendChild(el('div', 'taskplus-hist__empty', 'Nada na busca.'));
        }
    }

    /** Badge principal do estado do item. */
    function stateBadge(item) {
        switch (item.state) {
            case 'deleted':
                return el('span', 'taskplus-badge taskplus-badge--hdeleted', 'excluída');
            case 'skipped':
                return el('span', 'taskplus-badge taskplus-badge--hskipped',
                    'pulada' + (item.skip_label ? ' ' + item.skip_label : ''));
            case 'done':
                return el('span', 'taskplus-badge taskplus-badge--done',
                    'concluída' + (item.done_label ? ' ' + item.done_label : ''));
            case 'pending':
                return el('span', 'taskplus-badge taskplus-badge--pending',
                    item.pending_label || 'pendente');
            case 'late':
                return el('span', 'taskplus-badge taskplus-badge--late', 'atrasada');
            default:
                return el('span', 'taskplus-badge', 'aberta');
        }
    }

    /**
     * Linha de um item da trilha. Badges e autores são os mesmos das
     * outras telas. Única ação (6c-2): "Restaurar" no card EXCLUÍDA —
     * o servidor revalida posse e estado a cada POST (T18); rotina
     * excluída (se um dia existir) é recusada lá e o toast explica.
     */
    function card(item) {
        var c = el('div', 'taskplus-hcard taskplus-hcard--' + item.state);

        var name = el('div', 'taskplus-hcard__name', item.name || '(sem título)');
        c.appendChild(name);

        var badges = el('div', 'taskplus-card__badges');
        badges.appendChild(stateBadge(item));

        if (item.state === 'skipped' && item.skip_reason) {
            badges.appendChild(el('span', 'taskplus-badge', item.skip_reason));
        }
        if (item.state === 'pending') {
            if (item.pending_reason) {
                badges.appendChild(el('span', 'taskplus-badge', item.pending_reason));
            }
            if (item.pending_by_other && item.pending_by_label) {
                badges.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                    'pelo gestor ' + item.pending_by_label));
            }
        }
        if (item.state === 'done' && item.done_by_other && item.done_by_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                'pelo gestor ' + item.done_by_label));
        }
        if (item.time_limit) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--limit',
                'até ' + item.time_limit));
        }
        if (item.is_routine) {
            badges.appendChild(el('span', 'taskplus-badge',
                'rotina' + (item.routine_name ? ': ' + item.routine_name : '')));
        }
        if (item.category) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--category', item.category));
        }
        if (item.is_edited) {
            badges.appendChild(el('span', 'taskplus-badge', 'editada'));
        }
        if (item.created_by_other && item.created_by_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                'criada pelo gestor ' + item.created_by_label));
        }
        c.appendChild(badges);

        if (item.description) {
            c.appendChild(el('div', 'taskplus-hcard__desc', item.description));
        }

        // 9b-1: quem diz se a tela pode agir é o servidor. No modo
        // "trilha de um técnico" o can_restore vem falso (leitura pura)
        // — o 9b-2 é que liga a restauração pelo gestor.
        if (item.state === 'deleted' && state.data.view.can_restore) {
            var actions = el('div', 'taskplus-hcard__actions');
            var btn = el('button', 'btn btn-sm btn-outline-secondary taskplus-hcard__restore',
                'Restaurar');
            btn.type = 'button';
            btn.addEventListener('click', function () {
                post({ action: 'restore', id: String(item.id) });
            });
            actions.appendChild(btn);
            c.appendChild(actions);
        }

        return c;
    }

    // ------------------------------------------------------------------
    // Init
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-history');
        if (!state.root) {
            return;
        }
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';
        state.csrf = state.root.getAttribute('data-csrf') || '';

        var dataEl = $('taskplus-history-data');
        if (dataEl) {
            try {
                state.data = safeData(JSON.parse(dataEl.textContent || '{}'));
            } catch (e) {
                state.data = emptyData();
            }
        }

        var apply = $('tp-h-apply');
        if (apply) {
            apply.addEventListener('click', applyPeriod);
        }
        var clear = $('tp-h-clear');
        if (clear) {
            clear.addEventListener('click', clearPeriod);
        }
        var view = $('tp-h-view');
        if (view) {
            view.addEventListener('change', changeView);
        }
        var search = $('tp-h-search');
        if (search) {
            search.addEventListener('input', function () {
                state.search = search.value.trim();
                renderList();
            });
        }

        render();
    }

    // jsdom com runScripts 'outside-only' não dispara DOMContentLoaded
    // (T14): o harness chama window.TaskplusHistory.init() na mão.
    window.TaskplusHistory = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
