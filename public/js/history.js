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
 *  - ação da tela (6c-2, ampliada na 9b-2): "Restaurar" nos cards
 *    EXCLUÍDA — POST
 *    `restore` + id; posse (dono OU técnico do escopo do gestor) e
 *    estado são revalidados no SERVIDOR (T18)
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
        // A-2a: texto do filtro do seletor de alvo. Puramente local —
        // não vai ao servidor e não altera o recorte.
        viewFilter: '',
        search: '',
        busy: false,
        // 11a: URL do download de anexo e tarefa cujo diálogo está
        // aberto (null = modal fechado)
        attachUrl: '',
        dialogItem: null,
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
            created_by_label: (typeof i.created_by_label === 'string') ? i.created_by_label : '',
            // 11a: ausentes = zero. Aba aberta antes da atualização
            // (payload velho) simplesmente não mostra o botão — nunca
            // mostra um botão que abriria modal vazio.
            dialog_count: Number(i.dialog_count) || 0,
            file_count: Number(i.file_count) || 0
        };
    }

    // ------------------------------------------------------------------
    // 10a — exportação CSV
    // ------------------------------------------------------------------

    var CSV_SEP = ';';

    /**
     * Uma célula de CSV para o Excel em pt-BR.
     *
     * Duas defesas obrigatórias:
     *  - separador ';' (o Excel em português não reconhece vírgula) e
     *    aspas duplicadas quando o texto contém ';', '"' ou quebra;
     *  - célula que COMEÇA por = + - @ vira fórmula ao abrir no Excel.
     *    Título de tarefa é texto livre digitado por técnico, então a
     *    apóstrofe entra na frente para neutralizar (CSV injection).
     */
    function csvCell(value) {
        var s = (value === null || value === undefined) ? '' : String(value);
        if (/^[=+\-@\t\r]/.test(s)) {
            s = "'" + s;
        }
        if (s.indexOf('"') !== -1 || s.indexOf(CSV_SEP) !== -1 || /[\r\n]/.test(s)) {
            s = '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function csvLine(cells) {
        return cells.map(csvCell).join(CSV_SEP);
    }

    /** Estado em texto, sem os rótulos de horário do badge. */
    function stateText(item) {
        switch (item.state) {
            case 'deleted':  return 'excluída';
            case 'skipped':  return 'pulada';
            case 'done':     return 'concluída';
            case 'pending':  return 'pendente';
            case 'late':     return 'atrasada';
            default:         return 'aberta';
        }
    }

    /** Quem está sendo exibido, em texto — vai para o arquivo e o nome dele. */
    function viewLabel() {
        var v = state.data.view;
        return v.is_self ? 'Meu histórico' : (v.label || ('#' + v.id));
    }

    /**
     * O arquivo é EXATAMENTE o que está na tela (decisão do bloco): alvo
     * e período já vêm decididos pelo servidor, e a busca local é
     * reaplicada aqui. Exportar o período inteiro com a busca digitada
     * na tela faria o gestor levar 40 linhas achando que levou 12.
     *
     * O cabeçalho registra os três recortes, para o arquivo não mentir
     * sobre o próprio conteúdo depois de sair daqui.
     */
    function buildCsv() {
        var p = state.data.period;
        var rows = [];

        state.data.days.forEach(function (day) {
            day.items.filter(matchesSearch).forEach(function (item) {
                rows.push(csvLine([
                    item.date,
                    item.name,
                    item.category,
                    item.is_routine ? 'Rotina' : 'Avulsa',
                    item.routine_name,
                    stateText(item),
                    item.done_label,
                    item.pending_reason || item.skip_reason
                ]));
            });
        });

        var head = [
            csvLine(['Task+ — Histórico']),
            csvLine(['Alvo', viewLabel()]),
            csvLine(['Período', p.from + ' a ' + p.to]),
            csvLine(['Busca', state.search || '(sem filtro)']),
            csvLine(['Linhas', String(rows.length)]),
            '',
            csvLine(['Data', 'Tarefa', 'Categoria', 'Origem', 'Rotina',
                     'Estado', 'Conclusão', 'Motivo'])
        ];

        return head.concat(rows).join('\r\n') + '\r\n';
    }

    function slug(s) {
        return norm(s).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'sem-nome';
    }

    function csvName() {
        var p = state.data.period;
        return 'taskplus-historico-' + slug(viewLabel()) + '-' + p.from + '-a-' + p.to + '.csv';
    }

    function exportCsv() {
        var visible = 0;
        state.data.days.forEach(function (day) {
            visible += day.items.filter(matchesSearch).length;
        });
        if (visible === 0) {
            toast('Nada para exportar neste recorte.', true);
            return;
        }
        // BOM: sem ele o Excel em pt-BR abre "concluída" como lixo.
        var blob = new Blob(['\uFEFF' + buildCsv()], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = csvName();
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        toast(visible + ' linha(s) exportada(s).');
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
        var hasOptions = (v.options.length > 0);

        if (box) {
            box.hidden = !hasOptions;
        }
        if (hasOptions) {
            renderViewOptions();
        } else {
            var empty = $('tp-h-view');
            if (empty) {
                empty.textContent = '';
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

    /**
     * A-2a: monta o seletor aplicando o filtro digitado.
     *
     * Em produção há setor com mais de 150 membros — um <select> plano
     * com essa massa é impossível de percorrer. Três regras:
     *
     *  1. os técnicos vão em <optgroup> pelo SETOR (a string `groups`
     *     que o servidor já manda), então o nome do setor sai do texto
     *     da opção e vira cabeçalho;
     *  2. o filtro casa nome OU setor, sem acento (mesmo `norm` da
     *     busca da tela);
     *  3. "Meu histórico" e o ALVO SELECIONADO nunca são filtrados.
     *     Se a opção ativa sumisse, o <select> assumiria outro valor
     *     sozinho — e o próximo `change` mandaria um POST para um
     *     técnico que ninguém escolheu.
     */
    function renderViewOptions() {
        var select = $('tp-h-view');
        if (!select) {
            return;
        }

        var options = state.data.view.options;
        var q = norm(state.viewFilter);
        var kept = 0;

        select.textContent = '';
        select.appendChild(makeOption('self:0', 'Meu histórico'));

        var groups = [];
        var byGroup = {};

        options.forEach(function (o) {
            var key = 'user:' + o.id;
            var match = (q === '') || matchesOption(o, q);
            if (match) {
                kept++;
            } else if (key !== state.view) {
                return; // fora do filtro e não é o alvo atual
            }
            var name = String(o.groups || '');
            if (!byGroup[name]) {
                byGroup[name] = [];
                groups.push(name);
            }
            byGroup[name].push(o);
        });

        // Setor sem nome por último: é a exceção, não o cabeçalho.
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
        syncViewCount(kept, options.length);
    }

    /** O técnico casa o filtro pelo nome OU pelo setor. */
    function matchesOption(o, q) {
        return norm(String(o.label || '') + ' ' + String(o.groups || '')).indexOf(q) !== -1;
    }

    /**
     * "4 de 187" — sem isso, quem digita e não acha ninguém conclui que
     * o técnico não existe mais, em vez de que a lista está recortada.
     */
    function syncViewCount(kept, total) {
        var count = $('tp-h-viewcount');
        if (!count) {
            return;
        }
        var on = (state.viewFilter !== '');
        count.hidden = !on;
        count.textContent = on ? (kept + ' de ' + total) : '';
    }

    /**
     * Rótulo da opção — o texto de interface é do front, não do domínio.
     * A-2a: o setor NÃO entra mais aqui quando existe, porque virou o
     * cabeçalho do <optgroup>; repeti-lo dobraria a linha inteira.
     */
    function optionText(o) {
        return o.label || ('#' + o.id);
    }

    function viewNoteText(v) {
        return 'Histórico de ' + (v.label || ('#' + v.id))
            + ' — leitura de gestor: a mesma trilha que ele vê.'
            + (v.can_restore ? ' Você pode restaurar as tarefas excluídas dele.' : '');
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

        // 11a: o botão só existe quando HÁ diálogo. Ausência de botão é
        // informação para quem audita ("esta tarefa não deixou
        // evidência") — botão que abre modal vazio não é.
        if (item.dialog_count > 0) {
            var dact = el('div', 'taskplus-hcard__actions');
            var dbtn = el('button', 'btn btn-sm btn-outline-secondary taskplus-hcard__dialog',
                dialogLabel(item));
            dbtn.type = 'button';
            dbtn.addEventListener('click', function () {
                openDialog(item);
            });
            dact.appendChild(dbtn);
            c.appendChild(dact);
        }

        // 9b-1/9b-2: quem diz se a tela pode agir é o servidor. No modo
        // "trilha de um técnico" o can_restore vem ligado para o gestor
        // do setor — e o restore() revalida o escopo inteiro no POST.
        if (item.state === 'deleted' && state.data.view.can_restore) {
            var actions = el('div', 'taskplus-hcard__actions');
            var btn = el('button', 'btn btn-sm btn-outline-secondary taskplus-hcard__restore',
                'Restaurar');
            btn.type = 'button';
            btn.addEventListener('click', function () {
                // Escrever no dado de OUTRA pessoa pede confirmação —
                // mesma régua da trava de duplicadas (8d). No próprio
                // histórico segue em um clique.
                if (!state.data.view.is_self && !confirmRestore(item)) {
                    return;
                }
                post({ action: 'restore', id: String(item.id) });
            });
            actions.appendChild(btn);
            c.appendChild(actions);
        }

        return c;
    }

    /**
     * 9b-2: confirmação do gestor. window.confirm ausente (ou recusado
     * pelo navegador) NÃO pode virar restauração silenciosa — sem
     * confirmação possível, a ação não segue.
     */
    function confirmRestore(item) {
        var who = state.data.view.label || 'este técnico';
        var what = item.name || '(sem título)';
        if (typeof window.confirm !== 'function') {
            return false;
        }
        return window.confirm('Restaurar a tarefa "' + what + '" de ' + who + '?');
    }

    // ------------------------------------------------------------------
    // Init
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // 11a — diálogo da tarefa (LEITURA)
    // ------------------------------------------------------------------

    /**
     * Rótulo do botão: contagem sempre, clipe só quando há anexo. O
     * clipe é o que faz a evidência saltar aos olhos numa lista longa.
     */
    function dialogLabel(item) {
        var n = item.dialog_count > 9 ? '9+' : String(item.dialog_count);
        return 'Diálogo (' + n + ')' + (item.file_count > 0 ? ' \uD83D\uDCCE' : '');
    }

    function openDialog(item) {
        state.dialogItem = item;
        var title = $('tp-h-d-title');
        if (title) {
            // O dia entra no título: no Histórico duas ocorrências da
            // MESMA rotina têm o mesmo nome, e sem a data o leitor não
            // sabe qual delas está lendo.
            title.textContent = (item.name || '(sem título)')
                + (item.date_label ? ' — ' + item.date_label : '');
        }
        renderDialog([]);
        var modal = $('tp-h-dialog-modal');
        if (modal) {
            modal.hidden = false;
        }
        postDialog(item);
    }

    function closeDialog() {
        var modal = $('tp-h-dialog-modal');
        if (modal) {
            modal.hidden = true;
        }
        state.dialogItem = null;
    }

    /**
     * POST próprio: mesma trava de busy e mesma rotação de token do
     * post() principal, mas a resposta NÃO passa pelo safeData nem
     * dispara re-render — o servidor não devolve payload para
     * `action=dialog` de propósito (a trilha não mudou, e recalcular o
     * recorte a cada abertura sairia caro no período de 180 dias).
     */
    function postDialog(item) {
        if (state.busy || !item) {
            return;
        }
        state.busy = true;
        var asked = item;

        var fd = new FormData();
        fd.append('action', 'dialog');
        fd.append('id', String(item.id));
        fd.append('_glpi_csrf_token', state.csrf);

        // Sem o Accept o core devolve a página de erro em HTML e o
        // resp.json() abaixo quebra (9e-2).
        fetch(state.ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (resp) { return resp.json(); })
            .then(function (res) {
                state.busy = false;
                if (res && typeof res.csrf === 'string' && res.csrf !== '') {
                    state.csrf = res.csrf;
                }
                // O modal pode ter sido fechado (ou trocado de tarefa)
                // enquanto a resposta vinha — thread de OUTRA tarefa não
                // pode aterrissar na aberta agora.
                if (!state.dialogItem || state.dialogItem.id !== asked.id) {
                    return;
                }
                if (!res || !res.success) {
                    toast((res && res.message) ? res.message : 'Erro ao abrir o diálogo', true);
                }
                renderDialog((res && Array.isArray(res.comments)) ? res.comments : []);
            })
            .catch(function () {
                state.busy = false;
                toast('Falha de comunicação com o servidor', true);
            });
    }

    /** Thread do modal — textContent SEMPRE (nada de HTML do usuário). */
    function renderDialog(comments) {
        var list = $('tp-h-d-list');
        if (!list) {
            return;
        }
        list.textContent = '';
        var empty = $('tp-h-d-empty');
        if (empty) {
            empty.hidden = comments.length > 0;
        }
        comments.forEach(function (c) {
            var li = el('li', 'taskplus-dialog__item');

            var head = el('div', 'taskplus-dialog__meta');
            var who = document.createElement('strong');
            who.textContent = c.author || '(usuário removido)';
            head.appendChild(who);
            head.appendChild(el('span', '', c.date || ''));
            li.appendChild(head);

            li.appendChild(el('div', 'taskplus-dialog__text', c.content || ''));

            // Anexo: download servido pelo plugin, com o gate próprio do
            // ajax/attachment.php (participante OU gestor do dono) — a
            // mesma régua que já vale na Equipe, sem nada de novo aqui.
            if (c.file_name) {
                var fl = document.createElement('a');
                fl.className = 'taskplus-dialog__attach';
                fl.href = state.attachUrl + '?comment=' + encodeURIComponent(String(c.id));
                fl.target = '_blank';
                fl.rel = 'noopener';
                fl.textContent = '\uD83D\uDCCE ' + c.file_name;
                li.appendChild(fl);
            }
            list.appendChild(li);
        });
        list.scrollTop = list.scrollHeight;
    }

    function init() {
        state.root = $('taskplus-history');
        if (!state.root) {
            return;
        }
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';
        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.attachUrl = state.root.getAttribute('data-attachments-url') || '';

        var dataEl = $('taskplus-history-data');
        if (dataEl) {
            try {
                state.data = safeData(JSON.parse(dataEl.textContent || '{}'));
            } catch (e) {
                state.data = emptyData();
            }
        }

        var dclose = $('tp-h-d-close');
        if (dclose) {
            dclose.addEventListener('click', closeDialog);
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
        // A-2a: filtrar é local — remonta o <select> e NÃO faz POST.
        var exportBtn = $('tp-h-export');
        if (exportBtn) {
            exportBtn.addEventListener('click', exportCsv);
        }
        var vfilter = $('tp-h-viewfilter');
        if (vfilter) {
            vfilter.addEventListener('input', function () {
                state.viewFilter = vfilter.value.trim();
                renderViewOptions();
            });
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
    // `csv` exposto para o harness conferir o TEXTO gerado sem depender
    // de Blob/createObjectURL, que o jsdom não implementa.
    window.TaskplusHistory = {
        init: init, csv: buildCsv, csvName: csvName,
        // 11a: expostos para o harness conferir rótulo e thread sem
        // depender de rede
        dialogLabel: dialogLabel, renderDialog: renderDialog
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
