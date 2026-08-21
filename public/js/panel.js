/**
 * Task+ — tela "Painel" (Etapa 6b: indicadores pessoais, SÓ leitura).
 *
 * Contrato com o servidor:
 *  - estado inicial embutido em <script type="application/json"
 *    id="taskplus-panel-data"> (gerado pelo front/panel.php com
 *    JSON_HEX_* — seguro contra </script>);
 *  - mudança de período vai por POST a ajax/panel.php com
 *    `_glpi_csrf_token` e `period_from`/`period_to` (mesmos nomes do
 *    5b-2 p2); a resposta traz `csrf` NOVO (rotação obrigatória — o
 *    token é de uso único) e `data` com o payload agregado do período
 *    pedido, para re-render. O servidor é a fonte da verdade do
 *    recorte: default de 90 dias, teto de 180 (flag `clamped`);
 *  - 6b-2: o POST leva também `view_kind` + `view_id` ('self' = meu
 *    painel; 'user' + id = painel de um técnico; 'team' + groups_id,
 *    0 = todos os setores = agregado da equipe). O front NÃO decide
 *    escopo — manda o pedido e obedece ao `view` da resposta, que diz
 *    qual painel foi realmente lido e traz as opções permitidas;
 *  - defesa de cliente: Array.isArray em toda lista vinda do servidor
 *    (variável ausente vira null → .map() quebraria a tela).
 *
 * Sem dependências. Todo texto de usuário entra no DOM via textContent
 * (nunca innerHTML) — sem XSS por nome de rotina.
 */
(function () {
    'use strict';

    var state = {
        root: null,
        ajaxUrl: '',
        csrf: '',
        // Recorte exibido: '' + '' = default do servidor (últimos 90)
        period: { from: '', to: '' },
        // 6b-2: alvo pedido, no formato "kind:id" ('self:0' = meu
        // painel). Quem manda é a resposta do servidor — o syncView
        // reescreve isto a cada render.
        view: 'self:0',
        // A-2a: texto do filtro do seletor de alvo. Puramente local —
        // não vai ao servidor e não altera o recorte.
        viewFilter: '',
        busy: false,
        data: emptyData()
    };

    function emptyData() {
        return {
            date: '',
            period: { from: '', to: '', label: '', days: 0, clamped: false },
            totals: { due: 0, done: 0, pending: 0, late: 0, open: 0, rate: null },
            heatmap: { weeks: [], max_done: 0 },
            weekdays: [],
            best_day: null,
            routines: { rows: [], more: 0 },
            owners: { rows: [] },
            // 6b-2: alvo exibido + opções do gestor (vazias para quem
            // não administra setor — sem seletor na tela)
            view: {
                kind: 'self', id: 0, label: '', group_id: 0, group_name: '',
                techs: 0, failed: 0, is_self: true, denied: false, options: []
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
        var h = (d.heatmap && typeof d.heatmap === 'object') ? d.heatmap : {};
        var r = (d.routines && typeof d.routines === 'object') ? d.routines : {};
        var o = (d.owners && typeof d.owners === 'object') ? d.owners : {};
        var b = (d.best_day && typeof d.best_day === 'object') ? d.best_day : null;
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
                due: Number(t.due) || 0,
                done: Number(t.done) || 0,
                pending: Number(t.pending) || 0,
                late: Number(t.late) || 0,
                open: Number(t.open) || 0,
                rate: (t.rate === null || t.rate === undefined) ? null : (Number(t.rate) || 0)
            },
            heatmap: {
                weeks: Array.isArray(h.weeks) ? h.weeks.map(safeWeek) : [],
                max_done: Number(h.max_done) || 0
            },
            weekdays: Array.isArray(d.weekdays) ? d.weekdays.map(safeWeekday) : [],
            best_day: b ? {
                label: (typeof b.label === 'string') ? b.label : '',
                done: Number(b.done) || 0
            } : null,
            routines: {
                rows: Array.isArray(r.rows) ? r.rows.map(safeRoutine) : [],
                more: Number(r.more) || 0
            },
            owners: {
                rows: Array.isArray(o.rows) ? o.rows.map(safeOwner) : []
            },
            view: safeView(d.view)
        };
    }

    function safeOwner(raw) {
        var o = (raw && typeof raw === 'object') ? raw : {};
        return {
            id: Number(o.id) || 0,
            name: (typeof o.name === 'string') ? o.name : '',
            due: Number(o.due) || 0,
            done: Number(o.done) || 0,
            pending: Number(o.pending) || 0,
            rate: (o.rate === null || o.rate === undefined) ? null : (Number(o.rate) || 0)
        };
    }

    function safeView(raw) {
        var v = (raw && typeof raw === 'object') ? raw : {};
        var kind = (v.kind === 'user' || v.kind === 'team') ? v.kind : 'self';
        return {
            kind: kind,
            id: Number(v.id) || 0,
            group_id: Number(v.group_id) || 0,
            group_name: (typeof v.group_name === 'string') ? v.group_name : '',
            techs: Number(v.techs) || 0,
            failed: Number(v.failed) || 0,
            label: (typeof v.label === 'string') ? v.label : '',
            // Ausente = pessoal: o modo "outro técnico" só existe se o
            // servidor disser explicitamente que não é o próprio.
            is_self: (v.is_self === undefined) ? true : !!v.is_self,
            denied: !!v.denied,
            options: Array.isArray(v.options) ? v.options.map(safeViewOption) : []
        };
    }

    function safeViewOption(raw) {
        var o = (raw && typeof raw === 'object') ? raw : {};
        return {
            kind: (o.kind === 'team') ? 'team' : 'user',
            id: Number(o.id) || 0,
            label: (typeof o.label === 'string') ? o.label : '',
            groups: (typeof o.groups === 'string') ? o.groups : ''
        };
    }

    function safeWeek(raw) {
        var w = (raw && typeof raw === 'object') ? raw : {};
        return {
            label: (typeof w.label === 'string') ? w.label : '',
            days: Array.isArray(w.days) ? w.days.map(safeCell) : []
        };
    }

    function safeCell(raw) {
        var c = (raw && typeof raw === 'object') ? raw : {};
        return {
            date: (typeof c.date === 'string') ? c.date : '',
            due: Number(c.due) || 0,
            done: Number(c.done) || 0,
            level: (c.level === null || c.level === undefined) ? null : (Number(c.level) || 0),
            in_range: !!c.in_range,
            is_today: !!c.is_today,
            future: !!c.future
        };
    }

    function safeWeekday(raw) {
        var w = (raw && typeof raw === 'object') ? raw : {};
        return {
            label: (typeof w.label === 'string') ? w.label : '',
            due: Number(w.due) || 0,
            done: Number(w.done) || 0
        };
    }

    function safeRoutine(raw) {
        var r = (raw && typeof raw === 'object') ? raw : {};
        return {
            id: Number(r.id) || 0,
            name: (typeof r.name === 'string') ? r.name : '',
            due: Number(r.due) || 0,
            done: Number(r.done) || 0,
            pending: Number(r.pending) || 0,
            rate: (r.rate === null || r.rate === undefined) ? null : (Number(r.rate) || 0)
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
        // período que o usuário está vendo ('' + '' = default de 90).
        fd.append('period_from', state.period.from);
        fd.append('period_to', state.period.to);
        // 6b-2: o alvo viaja junto do recorte — trocar o período
        // enquanto se lê a equipe não pode devolver o painel próprio.
        // Quem valida o par kind+id é o servidor.
        var target = splitView(state.view);
        fd.append('view_kind', target.kind);
        fd.append('view_id', String(target.id));

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

    function applyPeriod() {
        state.period = {
            from: ($('tp-p-from') ? $('tp-p-from').value : '') || '',
            to: ($('tp-p-to') ? $('tp-p-to').value : '') || ''
        };
        post({ action: 'list' });
    }

    function clearPeriod() {
        state.period = { from: '', to: '' };
        post({ action: 'list' });
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------

    function render() {
        syncView();
        syncToolbar();
        renderKpis();
        renderHeatmap();
        renderRoutines();
        renderOwners();
    }

    /**
     * Espelha o recorte ECOADO pelo servidor (fonte da verdade — ele
     * aplica default, teto e desinverte o par) nos inputs e no aviso.
     */
    function syncToolbar() {
        var p = state.data.period;
        state.period = { from: p.from, to: p.to };

        var from = $('tp-p-from');
        var to = $('tp-p-to');
        if (from) {
            from.value = p.from;
        }
        if (to) {
            to.value = p.to;
        }
        var note = $('tp-p-note');
        if (note) {
            note.hidden = (p.label === '');
            note.textContent = 'Período: ' + p.label + ' (' + p.days + ' dias)'
                + (p.clamped ? ' — recorte limitado a 180 dias' : '');
        }
    }

    /**
     * 6b-2: seletor "Ver painel de:" + faixa de identificação.
     *
     * A RESPOSTA manda: `view.kind` + id dizem o que o servidor
     * realmente leu. Se o pedido foi recusado (`denied`), o select
     * volta sozinho para "Meu painel" — a tela nunca fica dizendo que
     * mostra algo que não está mostrando.
     */
    function syncView() {
        var v = state.data.view;
        var selected = viewKey(v);
        state.view = selected;

        var box = $('tp-p-viewbox');
        var hasOptions = (v.options.length > 0);

        if (box) {
            box.hidden = !hasOptions;
        }
        if (hasOptions) {
            renderViewOptions();
        } else {
            var empty = $('tp-p-view');
            if (empty) {
                empty.textContent = '';
            }
        }

        var note = $('tp-p-viewnote');
        var text = $('tp-p-viewnote-text');
        if (note) {
            note.hidden = v.is_self;
        }
        if (text) {
            text.textContent = v.is_self ? '' : viewNoteText(v);
        }

        // Modo equipe troca a tabela de rotinas pela de responsáveis:
        // a mesma rotina de vários técnicos viraria linhas homônimas.
        var team = (v.kind === 'team');
        var rBlock = $('tp-p-routines-block');
        var oBlock = $('tp-p-owners-block');
        if (rBlock) {
            rBlock.hidden = team;
        }
        if (oBlock) {
            oBlock.hidden = !team;
        }
    }

    /** Chave "kind:id" do alvo exibido, na forma que o select usa. */
    function viewKey(v) {
        if (v.is_self || v.kind === 'self') {
            return 'self:0';
        }
        return (v.kind === 'team')
            ? ('team:' + v.group_id)
            : ('user:' + v.id);
    }

    function splitView(key) {
        var parts = String(key || 'self:0').split(':');
        var kind = (parts[0] === 'user' || parts[0] === 'team') ? parts[0] : 'self';
        return { kind: kind, id: Number(parts[1]) || 0 };
    }

    /**
     * A-2a: monta o seletor aplicando o filtro digitado.
     *
     * Igual ao do Histórico, com uma diferença: as opções de EQUIPE
     * ficam num grupo próprio no topo e NÃO são filtradas — são no
     * máximo cinco linhas e é por elas que a leitura começa.
     *
     * Os técnicos vão em <optgroup> pelo setor; o filtro casa nome ou
     * setor, sem acento; "Meu painel" e o alvo selecionado nunca somem
     * (opção ativa filtrada faria o <select> trocar de valor sozinho e
     * o próximo `change` mandaria um POST que ninguém pediu).
     */
    function renderViewOptions() {
        var select = $('tp-p-view');
        if (!select) {
            return;
        }

        var options = state.data.view.options;
        var q = norm(state.viewFilter);
        var kept = 0;
        var techs = 0;

        select.textContent = '';
        select.appendChild(makeOption('self:0', 'Meu painel'));

        var teamBox = null;
        var groups = [];
        var byGroup = {};

        options.forEach(function (o) {
            if (o.kind === 'team') {
                if (!teamBox) {
                    teamBox = document.createElement('optgroup');
                    teamBox.label = 'Equipe';
                    select.appendChild(teamBox);
                }
                teamBox.appendChild(makeOption('team:' + o.id, optionText(o)));
                return;
            }
            techs++;
            var key = 'user:' + o.id;
            var match = (q === '') || matchesOption(o, q);
            if (match) {
                kept++;
            } else if (key !== state.view) {
                return;
            }
            var name = String(o.groups || '');
            if (!byGroup[name]) {
                byGroup[name] = [];
                groups.push(name);
            }
            byGroup[name].push(o);
        });

        groups.sort(function (a, b) {
            if (a === '') { return 1; }
            if (b === '') { return -1; }
            return norm(a) < norm(b) ? -1 : (norm(a) > norm(b) ? 1 : 0);
        });

        groups.forEach(function (name) {
            var target = select;
            if (name !== '') {
                target = document.createElement('optgroup');
                target.label = name;
                select.appendChild(target);
            }
            byGroup[name].forEach(function (o) {
                target.appendChild(makeOption('user:' + o.id, optionText(o)));
            });
        });

        select.value = state.view;
        syncViewCount(kept, techs);
    }

    /** O técnico casa o filtro pelo nome OU pelo setor. */
    function matchesOption(o, q) {
        return norm(String(o.label || '') + ' ' + String(o.groups || '')).indexOf(q) !== -1;
    }

    /** Busca sem acento — mesma régua da tela Equipe e do Histórico. */
    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    /** "4 de 187" — diz que a lista está recortada, não vazia. */
    function syncViewCount(kept, total) {
        var count = $('tp-p-viewcount');
        if (!count) {
            return;
        }
        var on = (state.viewFilter !== '');
        count.hidden = !on;
        count.textContent = on ? (kept + ' de ' + total) : '';
    }

    /**
     * Rótulo da opção — o texto de interface é do front, não do domínio.
     * A-2a: o setor do técnico NÃO entra mais aqui quando existe, porque
     * virou o cabeçalho do <optgroup>.
     */
    function optionText(o) {
        if (o.kind === 'team') {
            return (o.id === 0)
                ? 'Equipe (todos os setores)'
                : ('Equipe — ' + (o.label || ('#' + o.id)));
        }
        return o.label || ('#' + o.id);
    }

    function viewNoteText(v) {
        if (v.kind !== 'team') {
            return 'Painel de ' + (v.label || ('#' + v.id))
                + ' — leitura de gestor: os mesmos números que ele vê.';
        }
        var who = v.group_name ? ('Equipe — ' + v.group_name) : 'Equipe (todos os setores)';
        var text = 'Painel da ' + who + ': ' + v.techs + ' técnico(s) no recorte, você incluído.';
        if (v.failed > 0) {
            text += ' ' + v.failed + ' sem dados nesta leitura.';
        }
        return text;
    }

    function makeOption(value, text) {
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = text;
        return opt;
    }

    function changeView() {
        var select = $('tp-p-view');
        state.view = select ? String(select.value || 'self:0') : 'self:0';
        post({ action: 'list' });
    }

    function renderKpis() {
        var t = state.data.totals;
        var rateEl = $('tp-pk-rate');
        if (rateEl) {
            rateEl.textContent = (t.rate === null) ? '—' : (t.rate + '%');
        }
        var doneEl = $('tp-pk-done');
        if (doneEl) {
            doneEl.textContent = String(t.done);
        }
        var dueEl = $('tp-pk-due');
        if (dueEl) {
            dueEl.textContent = String(t.due);
            dueEl.title = t.open + ' aberta(s) · ' + t.late + ' atrasada(s) · ' + t.done + ' concluída(s)';
        }
        var pendEl = $('tp-pk-pending');
        if (pendEl) {
            pendEl.textContent = String(t.pending);
        }
        var bestEl = $('tp-pk-best');
        if (bestEl) {
            var b = state.data.best_day;
            bestEl.textContent = b ? (b.label + ' (' + b.done + ')') : '—';
            bestEl.title = b ? (b.done + ' conclusão(ões) às ' + b.label.toLowerCase() + 's no período') : '';
        }
    }

    /** "2026-08-01" → "01/08". */
    function brDate(iso) {
        return (typeof iso === 'string' && iso.length >= 10)
            ? iso.substr(8, 2) + '/' + iso.substr(5, 2)
            : '';
    }

    function cellClass(cell) {
        var cls = 'taskplus-hm__cell';
        if (!cell.in_range) {
            return cls + ' taskplus-hm__cell--out';
        }
        cls += (cell.level === null)
            ? ' taskplus-hm__cell--empty'
            : ' taskplus-hm__cell--l' + cell.level;
        if (cell.is_today) {
            cls += ' taskplus-hm__cell--today';
        }
        if (cell.future) {
            cls += ' taskplus-hm__cell--future';
        }
        return cls;
    }

    function renderHeatmap() {
        var box = $('tp-p-heatmap');
        if (!box) {
            return;
        }
        box.textContent = '';

        var weeks = state.data.heatmap.weeks;
        if (weeks.length === 0) {
            box.appendChild(el('div', 'taskplus-hm__empty', 'Sem dados no período.'));
            renderLegend();
            return;
        }

        // Régua da esquerda: seg–dom, alinhada às linhas das colunas
        var dows = el('div', 'taskplus-hm__dows');
        dows.appendChild(el('span', 'taskplus-hm__month', '')); // vaga do rótulo de mês
        ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'].forEach(function (label, i) {
            // Como no GitHub: rótulo em linhas alternadas para não poluir
            dows.appendChild(el('span', 'taskplus-hm__dow', (i % 2 === 0) ? label : ''));
        });
        box.appendChild(dows);

        var strip = el('div', 'taskplus-hm__weeks');
        weeks.forEach(function (week) {
            var col = el('div', 'taskplus-hm__week');
            col.appendChild(el('span', 'taskplus-hm__month', week.label));
            week.days.forEach(function (cell) {
                var c = el('span', cellClass(cell));
                if (cell.in_range) {
                    c.title = brDate(cell.date) + ' — '
                        + (cell.due === 0
                            ? 'nada devida'
                            : cell.done + ' de ' + cell.due + ' concluída(s)');
                }
                col.appendChild(c);
            });
            strip.appendChild(col);
        });
        box.appendChild(strip);

        renderLegend();
    }

    function renderLegend() {
        var legend = $('tp-p-legend');
        if (!legend) {
            return;
        }
        legend.textContent = '';
        legend.appendChild(el('span', 'taskplus-hm__legendtext', '0%'));
        ['--l0', '--l1', '--l2', '--l3'].forEach(function (mod) {
            legend.appendChild(el('span', 'taskplus-hm__cell taskplus-hm__cell' + mod));
        });
        legend.appendChild(el('span', 'taskplus-hm__legendtext', '100%'));
    }

    function renderRoutines() {
        var box = $('tp-p-routines');
        if (!box) {
            return;
        }
        box.textContent = '';

        var rows = state.data.routines.rows;
        if (rows.length === 0) {
            box.appendChild(el('div', 'taskplus-hm__empty',
                'Nenhuma ocorrência de rotina no período.'));
            return;
        }

        var table = el('table', 'taskplus-rtable');
        var thead = el('thead');
        var hr = el('tr');
        ['Rotina', 'Concluídas', 'Pendentes', 'Taxa'].forEach(function (h) {
            hr.appendChild(el('th', null, h));
        });
        thead.appendChild(hr);
        table.appendChild(thead);

        var tbody = el('tbody');
        rows.forEach(function (r) {
            var tr = el('tr');
            tr.appendChild(el('td', 'taskplus-rtable__name', r.name || '(sem nome)'));
            tr.appendChild(el('td', 'taskplus-rtable__num', r.done + ' / ' + r.due));
            tr.appendChild(el('td', 'taskplus-rtable__num', String(r.pending)));

            var td = el('td', 'taskplus-rtable__rate');
            if (r.rate === null) {
                td.appendChild(el('span', 'taskplus-rtable__ratetext', '—'));
            } else {
                var bar = el('span', 'taskplus-rtable__bar');
                var fill = el('span', 'taskplus-rtable__fill');
                fill.style.width = r.rate + '%';
                bar.appendChild(fill);
                td.appendChild(bar);
                td.appendChild(el('span', 'taskplus-rtable__ratetext', r.rate + '%'));
            }
            tr.appendChild(td);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        box.appendChild(table);

        if (state.data.routines.more > 0) {
            box.appendChild(el('div', 'taskplus-rtable__more',
                'e mais ' + state.data.routines.more + ' rotina(s) com menos volume no período'));
        }
    }

    /**
     * 6b-2 p2: taxa por responsável (modo equipe). Mesma anatomia da
     * tabela de rotinas — barra + percentual —, sem teto de linhas:
     * cortar responsável some com gente da conta.
     */
    function renderOwners() {
        var box = $('tp-p-owners');
        if (!box) {
            return;
        }
        box.textContent = '';

        var rows = state.data.owners.rows;
        if (rows.length === 0) {
            box.appendChild(el('div', 'taskplus-hm__empty',
                'Nenhum técnico no recorte.'));
            return;
        }

        var table = el('table', 'taskplus-rtable');
        var thead = el('thead');
        var hr = el('tr');
        ['Responsável', 'Concluídas', 'Pendentes', 'Taxa'].forEach(function (h) {
            hr.appendChild(el('th', null, h));
        });
        thead.appendChild(hr);
        table.appendChild(thead);

        var tbody = el('tbody');
        rows.forEach(function (r) {
            var tr = el('tr');
            tr.appendChild(el('td', 'taskplus-rtable__name', r.name || ('#' + r.id)));
            tr.appendChild(el('td', 'taskplus-rtable__num', r.done + ' / ' + r.due));
            tr.appendChild(el('td', 'taskplus-rtable__num', String(r.pending)));

            var td = el('td', 'taskplus-rtable__rate');
            if (r.rate === null) {
                // Sem nada devida no período: não há taxa a mostrar —
                // "0%" mentiria sobre quem simplesmente não teve tarefa.
                td.appendChild(el('span', 'taskplus-rtable__ratetext', '—'));
            } else {
                var bar = el('span', 'taskplus-rtable__bar');
                var fill = el('span', 'taskplus-rtable__fill');
                fill.style.width = r.rate + '%';
                bar.appendChild(fill);
                td.appendChild(bar);
                td.appendChild(el('span', 'taskplus-rtable__ratetext', r.rate + '%'));
            }
            tr.appendChild(td);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        box.appendChild(table);
    }

    // ------------------------------------------------------------------
    // Init
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-panel');
        if (!state.root) {
            return; // não está na tela Painel
        }
        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';

        var raw = null;
        var dataEl = $('taskplus-panel-data');
        if (dataEl) {
            try {
                raw = JSON.parse(dataEl.textContent);
            } catch (e) {
                raw = null; // JSON ruim → estrutura vazia, tela não quebra
            }
        }
        state.data = safeData(raw);

        var apply = $('tp-p-apply');
        if (apply) {
            apply.addEventListener('click', applyPeriod);
        }
        var clear = $('tp-p-clear');
        if (clear) {
            clear.addEventListener('click', clearPeriod);
        }
        var view = $('tp-p-view');
        if (view) {
            view.addEventListener('change', changeView);
        }
        // A-2a: filtrar é local — remonta o <select> e NÃO faz POST.
        var vfilter = $('tp-p-viewfilter');
        if (vfilter) {
            vfilter.addEventListener('input', function () {
                state.viewFilter = vfilter.value.trim();
                renderViewOptions();
            });
        }

        render();
    }

    // Exposto para o harness (jsdom outside-only não dispara
    // DOMContentLoaded — lição T14) e para depuração.
    window.TaskplusPanel = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
