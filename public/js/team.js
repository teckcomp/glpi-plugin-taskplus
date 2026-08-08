/**
 * Task+ — tela "Equipe" (Etapa 5a: leitura para o gestor).
 *
 * Contrato com o servidor:
 *  - estado inicial embutido em <script type="application/json"
 *    id="taskplus-team-data"> (gerado pelo front/team.php com
 *    JSON_HEX_* — seguro contra </script>);
 *  - ações do gestor (5b-1: concluir/desfazer tarefa própria do Task+
 *    do técnico) vão por POST ao ajax/team.php com `_glpi_csrf_token`;
 *    a resposta traz `csrf` NOVO (rotação — o token é de uso único) e
 *    `data` com o payload atualizado, que re-renderiza a tela inteira
 *    preservando os técnicos expandidos (state.open).
 *
 * Todo texto de usuário entra no DOM via textContent (nunca innerHTML).
 * Módulo expõe window.TaskplusTeam.init() — o harness jsdom roda com
 * runScripts 'outside-only' e o DOMContentLoaded nunca dispara lá (T14).
 */
(function () {
    'use strict';

    var state = {
        root: null,
        csrf: '',
        ajaxUrl: '',
        busy: false,
        // Filtro de setor (pacote 3): 'all' ou o NOME do grupo — o
        // payload identifica setor por nome (os chips), não por id
        group: 'all',
        // Técnicos expandidos (por id): sobrevive ao re-render do filtro
        open: {},
        // Modais do 5b-2: {item, tech} em edição / pendência
        editCtx: null,
        pendCtx: null,
        // 5b-2 p2: busca local + período (viaja em todo POST)
        search: '',
        period: { from: '', to: '' },
        data: { date: '', groups: [], techs: [], period: { from: '', to: '', active: false } }
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
     * Chave nova no payload do Team.php precisa entrar AQUI (padrão
     * safeData de todas as telas).
     */
    function safeData(raw) {
        var d = (raw && typeof raw === 'object') ? raw : {};
        var p = (d.period && typeof d.period === 'object') ? d.period : {};
        return {
            date: (typeof d.date === 'string') ? d.date : '',
            groups: Array.isArray(d.groups) ? d.groups : [],
            techs: Array.isArray(d.techs) ? d.techs.map(safeTech) : [],
            // 5b-2 p2: eco do período (o servidor normaliza as datas)
            period: {
                from: (typeof p.from === 'string') ? p.from : '',
                to: (typeof p.to === 'string') ? p.to : '',
                active: !!p.active
            }
        };
    }

    function safeTech(raw) {
        var t = (raw && typeof raw === 'object') ? raw : {};
        var k = (t.kpis && typeof t.kpis === 'object') ? t.kpis : {};
        return {
            id: (typeof t.id === 'number') ? t.id : 0,
            label: (typeof t.label === 'string') ? t.label : '',
            groups: Array.isArray(t.groups) ? t.groups : [],
            load_error: !!t.load_error,
            kpis: {
                late: (typeof k.late === 'number') ? k.late : 0,
                today: (typeof k.today === 'number') ? k.today : 0,
                pending: (typeof k.pending === 'number') ? k.pending : 0,
                done: (typeof k.done === 'number') ? k.done : 0
            },
            items: Array.isArray(t.items) ? t.items.map(safeItem) : []
        };
    }

    function safeItem(raw) {
        var i = (raw && typeof raw === 'object') ? raw : {};
        return {
            id: (typeof i.id === 'number') ? i.id : 0,
            name: (typeof i.name === 'string') ? i.name : '',
            status: (typeof i.status === 'string') ? i.status : 'today',
            detail: (typeof i.detail === 'string') ? i.detail : '',
            // Ações decididas pelo SERVIDOR (5b-1/5b-2) — o JS só obedece
            can_act: !!i.can_act,
            can_edit: !!i.can_edit,
            can_pend: !!i.can_pend,
            can_unpend: !!i.can_unpend,
            is_done: !!i.is_done,
            // Campos do modal de edição
            description: (typeof i.description === 'string') ? i.description : '',
            category: (typeof i.category === 'string') ? i.category : '',
            date: (typeof i.date === 'string') ? i.date : '',
            time_limit: (typeof i.time_limit === 'string') ? i.time_limit : '',
            is_routine: !!i.is_routine,
            pending_reason: (typeof i.pending_reason === 'string') ? i.pending_reason : '',
            pending_until: (typeof i.pending_until === 'string') ? i.pending_until : '',
            pending_time: (typeof i.pending_time === 'string') ? i.pending_time : '',
            // Nome de quem concluiu / marcou a pendência, quando NÃO foi
            // o próprio técnico
            done_by: (typeof i.done_by === 'string') ? i.done_by : '',
            pending_by: (typeof i.pending_by === 'string') ? i.pending_by : '',
            is_native: !!i.is_native,
            source: (typeof i.source === 'string') ? i.source : '',
            url: (typeof i.url === 'string') ? i.url : ''
        };
    }

    // ------------------------------------------------------------------
    // Toast de feedback + comunicação com o servidor (padrão taskplus.js)
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

    function post(fields, onSuccess) {
        if (state.busy) {
            return;
        }
        state.busy = true;

        var fd = new FormData();
        Object.keys(fields).forEach(function (key) {
            fd.append(key, fields[key]);
        });
        fd.append('_glpi_csrf_token', state.csrf);
        // Período ativo acompanha TODA ação (5b-2 p2): a resposta
        // re-renderiza a tela e precisa devolver o MESMO recorte.
        if (state.period.from !== '') {
            fd.append('period_from', state.period.from);
        }
        if (state.period.to !== '') {
            fd.append('period_to', state.period.to);
        }

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
                    syncToolbar();
                }
                if (!res || !res.success) {
                    toast((res && res.message) ? res.message : 'Erro ao processar a ação', true);
                    render();
                    return;
                }
                if (res.message) {
                    toast(res.message, false);
                }
                render();
                if (onSuccess) {
                    onSuccess();
                }
            })
            .catch(function () {
                state.busy = false;
                toast('Falha de comunicação com o servidor', true);
            });
    }

    // ------------------------------------------------------------------
    // Busca local + período (5b-2 pacote 2 — mesmo padrão da tela Hoje)
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

    function periodActive() {
        return !!(state.data.period && state.data.period.active);
    }

    /** "2026-08-01" → "01/08". */
    function brDate(iso) {
        return (typeof iso === 'string' && iso.length >= 10)
            ? iso.substr(8, 2) + '/' + iso.substr(5, 2)
            : '';
    }

    /** "de 01/08 a 07/08" | "a partir de 01/08" | "até 07/08". */
    function periodLabel() {
        var p = state.data.period || {};
        var f = brDate(p.from);
        var t = brDate(p.to);
        if (f !== '' && t !== '') {
            return 'de ' + f + ' a ' + t;
        }
        return (f !== '') ? 'a partir de ' + f : 'até ' + t;
    }

    /** Barra de busca + período, criada UMA vez no init, acima da lista. */
    function buildToolbar() {
        var anchor = $('tp-team');
        if (!anchor || $('tp-tflt-search')) {
            return;
        }

        var bar = el('div', 'taskplus-toolbar2');

        var search = document.createElement('input');
        search.type = 'search';
        search.id = 'tp-tflt-search';
        search.className = 'taskplus-toolbar2__search';
        search.placeholder = 'Buscar por título, descrição ou categoria';
        search.setAttribute('aria-label', 'Buscar');
        search.addEventListener('input', function () {
            state.search = search.value.trim();
            render();
        });
        bar.appendChild(search);

        bar.appendChild(dateField('De', 'tp-tflt-from'));
        bar.appendChild(dateField('Até', 'tp-tflt-to'));

        var apply = el('button', 'btn btn-primary btn-sm', 'Aplicar');
        apply.type = 'button';
        apply.id = 'tp-tflt-apply';
        apply.addEventListener('click', applyPeriod);
        bar.appendChild(apply);

        var clear = el('button', 'btn btn-ghost-secondary btn-sm', 'Limpar');
        clear.type = 'button';
        clear.id = 'tp-tflt-clear';
        clear.hidden = true;
        clear.addEventListener('click', clearPeriod);
        bar.appendChild(clear);

        var note = el('div', 'taskplus-toolbar2__note');
        note.id = 'tp-tflt-note';
        note.hidden = true;
        bar.appendChild(note);

        anchor.parentNode.insertBefore(bar, anchor);
    }

    function dateField(label, id) {
        var wrap = el('label', 'taskplus-toolbar2__label', label);
        var input = document.createElement('input');
        input.type = 'date';
        input.id = id;
        wrap.appendChild(input);
        return wrap;
    }

    function applyPeriod() {
        state.period = {
            from: ($('tp-tflt-from') ? $('tp-tflt-from').value : '') || '',
            to: ($('tp-tflt-to') ? $('tp-tflt-to').value : '') || ''
        };
        post({ action: 'list' });
    }

    function clearPeriod() {
        state.period = { from: '', to: '' };
        post({ action: 'list' });
    }

    /** Espelha o período ecoado pelo servidor (fonte da verdade). */
    function syncToolbar() {
        var p = state.data.period || { from: '', to: '', active: false };
        state.period = { from: p.active ? p.from : '', to: p.active ? p.to : '' };

        var from = $('tp-tflt-from');
        var to = $('tp-tflt-to');
        if (from) {
            from.value = state.period.from;
        }
        if (to) {
            to.value = state.period.to;
        }
        var clear = $('tp-tflt-clear');
        if (clear) {
            clear.hidden = !p.active;
        }
        var note = $('tp-tflt-note');
        if (note) {
            note.hidden = !p.active;
            note.textContent = p.active
                ? 'Período ativo ' + periodLabel()
                    + ' — placares e listas sobre o período; os itens do GLPI seguem no estado atual.'
                : '';
        }
    }

    // ------------------------------------------------------------------
    // Renderização
    // ------------------------------------------------------------------

    var STATUS_LABEL = {
        late: 'Atrasada',
        today: 'Para hoje',
        pending: 'Pendente',
        done: 'Concluída'
    };

    var STATUS_BADGE = {
        late: 'taskplus-badge--late',
        today: 'taskplus-badge--limit',
        pending: 'taskplus-badge--pending',
        done: 'taskplus-badge--done'
    };

    function pill(count, label, cls) {
        var p = el('span', 'taskplus-badge ' + cls
            + (count === 0 ? ' taskplus-team__pill--zero' : ''),
            count + ' ' + label);
        return p;
    }

    function segButton(label, value) {
        var b = el('button', 'taskplus-segmented__btn'
            + (state.group === value ? ' taskplus-segmented__btn--on' : ''), label);
        b.type = 'button';
        b.setAttribute('data-group', value);
        return b;
    }

    /**
     * Filtro de setor (pacote 3): só aparece quando o gestor administra
     * MAIS de um grupo — com um só, seria um botão inútil. Filtrar é
     * 100% local (o payload já traz os setores de cada técnico); técnico
     * em dois setores geridos aparece nos dois filtros.
     */
    function renderFilter(box) {
        if (state.data.groups.length < 2) {
            return;
        }

        // Filtro apontando para setor que sumiu do payload volta ao Todos
        if (state.group !== 'all' && state.data.groups.indexOf(state.group) === -1) {
            state.group = 'all';
        }

        var seg = el('div', 'taskplus-segmented taskplus-team__filter');
        seg.setAttribute('role', 'group');
        seg.setAttribute('aria-label', 'Setor');
        seg.appendChild(segButton('Todos', 'all'));
        state.data.groups.forEach(function (g) {
            seg.appendChild(segButton(g, g));
        });
        seg.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.taskplus-segmented__btn');
            if (!btn) {
                return;
            }
            state.group = btn.getAttribute('data-group') || 'all';
            render();
        });
        box.appendChild(seg);
    }

    function visibleTechs() {
        if (state.group === 'all') {
            return state.data.techs;
        }
        return state.data.techs.filter(function (tech) {
            return tech.groups.indexOf(state.group) !== -1;
        });
    }

    function render() {
        var box = $('tp-team');
        if (!box) {
            return;
        }
        box.textContent = '';

        renderFilter(box);

        var searching = state.search !== '';
        var techs = visibleTechs();
        // Buscando, técnico SEM resultado sai da tela — sobra só quem
        // tem tarefa que casa (padrão de qualquer busca de lista).
        if (searching) {
            techs = techs.filter(function (tech) {
                return tech.items.some(matchesSearch);
            });
        }
        if (techs.length === 0) {
            box.appendChild(el('p', 'taskplus-empty', searching
                ? 'Nenhuma tarefa da equipe casa com a busca.'
                : (state.group === 'all'
                    ? 'Nenhum técnico nos setores que você administra.'
                    : 'Nenhum técnico neste setor.')));
            return;
        }

        techs.forEach(function (tech) {
            box.appendChild(renderTech(tech));
        });
    }

    function renderTech(tech) {
        var wrap = el('div', 'taskplus-team__tech');
        wrap.setAttribute('data-tech-id', String(tech.id));

        // -------- cabeçalho (placar do técnico, clicável) --------
        var head = el('div', 'taskplus-team__head');
        head.setAttribute('role', 'button');
        head.setAttribute('tabindex', '0');
        head.setAttribute('aria-expanded', 'false');

        var chev = el('i', 'ti ti-chevron-right taskplus-team__chevron');
        chev.setAttribute('aria-hidden', 'true');
        head.appendChild(chev);

        head.appendChild(el('span', 'taskplus-team__name', tech.label));

        tech.groups.forEach(function (g) {
            head.appendChild(el('span', 'taskplus-badge taskplus-badge--sector', g));
        });

        if (tech.load_error) {
            head.appendChild(el('span', 'taskplus-badge taskplus-badge--late',
                'dados indisponíveis'));
        }

        var pills = el('span', 'taskplus-team__pills');
        pills.appendChild(pill(tech.kpis.late, 'atrasadas', STATUS_BADGE.late));
        // No modo período "para hoje" enganaria: o número é de ABERTAS
        // no intervalo (hoje ou futuras) — o rótulo acompanha.
        pills.appendChild(pill(tech.kpis.today,
            periodActive() ? 'abertas' : 'para hoje', STATUS_BADGE.today));
        pills.appendChild(pill(tech.kpis.pending, 'pendentes', STATUS_BADGE.pending));
        pills.appendChild(pill(tech.kpis.done, 'concluídas', STATUS_BADGE.done));
        head.appendChild(pills);

        // -------- corpo (lista do dia; a memória de expandidos faz o
        // técnico aberto continuar aberto quando o filtro re-renderiza).
        // Buscando (5b-2 p2): só os itens que casam, e o técnico abre
        // sozinho — SEM gravar em state.open: limpar a busca devolve o
        // estado de antes.
        var searching = state.search !== '';
        var items = searching ? tech.items.filter(matchesSearch) : tech.items;
        var isOpen = !!state.open[tech.id] || searching;
        var body = el('div', 'taskplus-team__body');
        body.hidden = !isOpen;
        head.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        chev.className = 'ti taskplus-team__chevron ti-chevron-'
            + (isOpen ? 'down' : 'right');

        if (items.length === 0) {
            body.appendChild(el('p', 'taskplus-empty',
                periodActive() ? 'Nada no período.' : 'Nada para hoje.'));
        } else {
            items.forEach(function (item) {
                body.appendChild(renderItem(item, tech));
            });
        }

        function toggle() {
            var open = body.hidden;
            body.hidden = !open;
            state.open[tech.id] = open;
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
            chev.className = 'ti taskplus-team__chevron ti-chevron-'
                + (open ? 'down' : 'right');
        }

        head.addEventListener('click', toggle);
        head.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                toggle();
            }
        });

        wrap.appendChild(head);
        wrap.appendChild(body);
        return wrap;
    }

    function renderItem(item, tech) {
        var row = el('div', 'taskplus-team__item');

        row.appendChild(el('span',
            'taskplus-badge ' + (STATUS_BADGE[item.status] || STATUS_BADGE.today),
            (item.status === 'today' && periodActive())
                ? 'Aberta'
                : (STATUS_LABEL[item.status] || STATUS_LABEL.today)));

        // Nativa: o nome é um LINK para o item no GLPI (leitura + link,
        // decisão nº 12 — o gestor não age sobre item nativo de outro).
        if (item.is_native && item.url) {
            var a = el('a', 'taskplus-team__link', item.name);
            a.href = item.url;
            a.target = '_blank';
            a.rel = 'noopener';
            row.appendChild(a);
        } else {
            row.appendChild(el('span', 'taskplus-team__title', item.name));
        }

        if (item.source === 'ticket') {
            row.appendChild(el('span', 'taskplus-badge taskplus-badge--ticket', 'Chamado'));
        } else if (item.source === 'project') {
            row.appendChild(el('span', 'taskplus-badge taskplus-badge--project', 'Projeto'));
        }

        // Auditoria: concluída (5b-1) ou colocada pendente (5b-2) por
        // OUTRO usuário (gestor/admin)
        if (item.status === 'done' && item.done_by) {
            row.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                'pelo gestor ' + item.done_by));
        }
        if (item.status === 'pending' && item.pending_by) {
            row.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                'pelo gestor ' + item.pending_by));
        }

        if (item.detail) {
            row.appendChild(el('span', 'taskplus-team__detail', item.detail));
        }

        // Ações do gestor (5b-1/5b-2), habilitadas item a item pelo
        // SERVIDOR (can_*: nunca em nativa; cada uma revalidada no POST).
        var acts = el('span', 'taskplus-team__acts'
            + (item.detail ? '' : ' taskplus-team__acts--solo'));

        function actBtn(label, cls, onClick) {
            var b = el('button', 'taskplus-team__act' + (cls ? ' ' + cls : ''), label);
            b.type = 'button';
            b.addEventListener('click', onClick);
            acts.appendChild(b);
        }

        if (item.can_act) {
            actBtn(item.is_done ? 'Desfazer' : 'Concluir',
                item.is_done ? 'taskplus-team__act--undo' : '',
                function () {
                    post({
                        action: 'toggle',
                        id: item.id,
                        tech_id: tech.id,
                        done: item.is_done ? 0 : 1
                    });
                });
        }
        if (item.can_pend) {
            actBtn('Pendência', 'taskplus-team__act--pend', function () {
                openPendModal(item, tech);
            });
        }
        if (item.can_unpend) {
            actBtn('Liberar', 'taskplus-team__act--pend', function () {
                post({
                    action: 'unpending',
                    id: item.id,
                    tech_id: tech.id
                });
            });
        }
        if (item.can_edit) {
            actBtn('Editar', 'taskplus-team__act--edit', function () {
                openEditModal(item, tech);
            });
        }

        if (acts.childNodes.length > 0) {
            row.appendChild(acts);
        }

        return row;
    }

    // ------------------------------------------------------------------
    // Modais do 5b-2 (edição e pendência) — mesmo padrão da tela Hoje
    // ------------------------------------------------------------------

    function tomorrow() {
        var d = new Date();
        d.setDate(d.getDate() + 1);
        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    function openEditModal(item, tech) {
        state.editCtx = { item: item, tech: tech };
        $('tp-t-e-tech').textContent = item.name + ' — ' + tech.label;
        $('tp-t-e-name').value = item.name || '';
        $('tp-t-e-date').value = item.date || '';
        // Ocorrência de rotina: a DATA não é editável (regra da 4b) —
        // o servidor a descarta, o campo travado só deixa isso claro.
        $('tp-t-e-date').disabled = !!item.is_routine;
        $('tp-t-e-time').value = item.time_limit || '';
        $('tp-t-e-category').value = item.category || '';
        $('tp-t-e-description').value = item.description || '';
        $('tp-t-edit-modal').hidden = false;
        $('tp-t-e-name').focus();
    }

    function closeEditModal() {
        $('tp-t-edit-modal').hidden = true;
        state.editCtx = null;
    }

    function saveEditModal() {
        var ctx = state.editCtx;
        if (!ctx) {
            return;
        }
        var name = $('tp-t-e-name').value.trim();
        if (name === '') {
            toast('Informe o título da tarefa', true);
            $('tp-t-e-name').focus();
            return;
        }
        post({
            action: 'update',
            id: String(ctx.item.id),
            tech_id: String(ctx.tech.id),
            name: name,
            date: $('tp-t-e-date').value,
            time_limit: $('tp-t-e-time').value,
            category: $('tp-t-e-category').value.trim(),
            description: $('tp-t-e-description').value.trim()
        }, closeEditModal);
    }

    function openPendModal(item, tech) {
        state.pendCtx = { item: item, tech: tech };
        $('tp-t-p-title').textContent = item.name + ' — ' + tech.label;
        $('tp-t-p-reason').value = item.pending_reason || '';
        // Sugestão: amanhã (pendência para hoje nasceria vencida) e fim
        // de expediente — mesmas sugestões da tela Hoje (4b).
        $('tp-t-p-until').value = item.pending_until || tomorrow();
        $('tp-t-p-time').value = item.pending_time || '18:00';
        $('tp-t-pending-modal').hidden = false;
        $('tp-t-p-reason').focus();
    }

    function closePendModal() {
        $('tp-t-pending-modal').hidden = true;
        state.pendCtx = null;
    }

    function savePendModal() {
        var ctx = state.pendCtx;
        if (!ctx) {
            return;
        }
        var reason = $('tp-t-p-reason').value.trim();
        if (reason === '') {
            toast('Informe o motivo da pendência', true);
            $('tp-t-p-reason').focus();
            return;
        }
        var time = $('tp-t-p-time').value.trim();
        if (time === '') {
            toast('Informe a hora de retorno', true);
            $('tp-t-p-time').focus();
            return;
        }
        post({
            action: 'pending',
            id: String(ctx.item.id),
            tech_id: String(ctx.tech.id),
            reason: reason,
            pending_until: $('tp-t-p-until').value,
            pending_time: time
        }, closePendModal);
    }

    /**
     * Liga os botões dos modais UMA vez. Os elementos podem não existir
     * (template antigo em cache) — cada listener é opcional.
     */
    function bindModals() {
        var map = {
            'tp-t-e-cancel': closeEditModal,
            'tp-t-e-save': saveEditModal,
            'tp-t-p-cancel': closePendModal,
            'tp-t-p-save': savePendModal
        };
        Object.keys(map).forEach(function (id) {
            var node = $(id);
            if (node) {
                node.addEventListener('click', map[id]);
            }
        });
    }

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-team');
        if (!state.root) {
            return;
        }

        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';

        var raw = {};
        var holder = $('taskplus-team-data');
        if (holder) {
            try {
                raw = JSON.parse(holder.textContent || '{}');
            } catch (e) {
                raw = {};
            }
        }
        state.data = safeData(raw);
        // 5b-2 p2: barra de busca + período acima da lista
        buildToolbar();
        syncToolbar();
        bindModals();
        render();
    }

    window.TaskplusTeam = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
