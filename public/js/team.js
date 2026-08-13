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
        // Modal de criação do 5c-1: {tech} de destino
        createCtx: null,
        // 8e-2: {item, tech} do diálogo aberto
        dialogCtx: null,
        attachUrl: '',
        // 5b-2 p2: busca local + período (viaja em todo POST)
        search: '',
        period: { from: '', to: '' },
        data: { date: '', groups: [], sectors: [], techs: [], period: { from: '', to: '', active: false } }
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
            // 5c-3: setores administrados (id+nome) — seletor do modal
            sectors: Array.isArray(d.sectors) ? d.sectors.map(safeSector) : [],
            techs: Array.isArray(d.techs) ? d.techs.map(safeTech) : [],
            // 5b-2 p2: eco do período (o servidor normaliza as datas)
            period: {
                from: (typeof p.from === 'string') ? p.from : '',
                to: (typeof p.to === 'string') ? p.to : '',
                active: !!p.active
            }
        };
    }

    function safeSector(raw) {
        var s = (raw && typeof raw === 'object') ? raw : {};
        return {
            id: (typeof s.id === 'number') ? s.id : 0,
            name: (typeof s.name === 'string') ? s.name : ''
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
            // 5c-1: quem criou, quando não foi o próprio técnico
            created_by: (typeof i.created_by === 'string') ? i.created_by : '',
            is_native: !!i.is_native,
            // 9a-2: não lidos DO GESTOR nesta tarefa (payload antigo = 0)
            unread: (typeof i.unread === 'number') ? i.unread : 0,
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

        // 5c-1: criar AVULSA para o técnico. O botão vive no corpo
        // expandido — a tela inteira já é só de quem passou pelo gate
        // canTeam(), e o escopo real é revalidado no POST (T18).
        var createRow = el('div', 'taskplus-team__create');
        var createBtn = el('button',
            'taskplus-team__act taskplus-team__act--create',
            '+ Nova tarefa para ' + tech.label);
        createBtn.type = 'button';
        createBtn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            openCreateModal(tech);
        });
        createRow.appendChild(createBtn);
        body.appendChild(createRow);

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
        // 5c-1: a tarefa foi CRIADA por um gestor (vale em qualquer
        // status — a autoria da criação não muda com o andamento)
        if (item.created_by) {
            row.appendChild(el('span', 'taskplus-badge taskplus-badge--manager',
                'criada pelo gestor ' + item.created_by));
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

        // 8e-2: diálogo — todo item do PLUGIN (nativa fica fora: o
        // diálogo dela vive no objeto do GLPI). Leitura para qualquer
        // gestor do setor; escrita decidida pelo servidor (can_write).
        if (!item.is_native) {
            // 9a-2: contador de não lido no PRÓPRIO botão — a Equipe é
            // uma lista densa, um badge solto se perderia entre os
            // chips de status.
            var unread = Number(item.unread) || 0;
            var label = (unread > 0)
                ? 'Diálogo (' + (unread > 9 ? '9+' : String(unread)) + ')'
                : 'Diálogo';
            actBtn(label,
                'taskplus-team__act--dialog' + (unread > 0 ? ' taskplus-team__act--unread' : ''),
                function () {
                    openDialogModal(item, tech);
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

    // ------------------------------------------------------------------
    // Modal de criação (5c-1) — mesmos campos do "Nova tarefa" da tela
    // Hoje; o dono é o TÉCNICO e o autor é o gestor (badge de autoria)
    // ------------------------------------------------------------------

    function openCreateModal(tech) {
        state.createCtx = { tech: tech };
        $('tp-t-c-tech').textContent = tech.label;
        $('tp-t-c-name').value = '';
        // Sugestão: hoje (vazio no servidor também vira hoje — a
        // sugestão só deixa a regra visível para o gestor)
        $('tp-t-c-date').value = state.data.date || '';
        $('tp-t-c-time').value = '';
        $('tp-t-c-category').value = '';
        $('tp-t-c-description').value = '';
        // 5c-3: destino sempre volta ao TÉCNICO expandido; o seletor de
        // setor é repovoado a cada abertura (o payload pode ter mudado)
        setCreateDest('tech');
        fillSectorSelect(tech);
        var includeMe = $('tp-t-c-include-me');
        if (includeMe) {
            includeMe.checked = false;
        }
        // 5c-2: estado inicial da recorrência — sempre volta a Avulsa
        setCreateKind('avulsa');
        $('tp-t-c-frequency').value = 'daily';
        $('tp-t-c-only-workdays').checked = false;
        weekdayBoxes().forEach(function (b) { b.checked = false; });
        setMonthlyMode('day');
        $('tp-t-c-monthday').value = '';
        $('tp-t-c-monthweek').value = '1';
        $('tp-t-c-monthweekday').value = '1';
        $('tp-t-c-begin').value = state.data.date || '';
        $('tp-t-c-end').value = '';
        syncCreateDest();
        syncCreateKind();
        syncCreateFreq();
        syncMonthlyMode();
        $('tp-t-create-modal').hidden = false;
        $('tp-t-c-name').focus();
    }

    // ---- helpers do destino (5c-3) ----

    function createDest() {
        var checked = document.querySelector('input[name="tp-t-c-dest"]:checked');
        return checked ? checked.value : 'tech';
    }

    function setCreateDest(dest) {
        var radios = document.querySelectorAll('input[name="tp-t-c-dest"]');
        Array.prototype.forEach.call(radios, function (r) {
            r.checked = (r.value === dest);
        });
    }

    /**
     * Repovoa o seletor de setor com os setores ADMINISTRADOS (payload
     * `sectors`). Pré-seleção: o setor de mesmo nome do primeiro chip
     * do técnico expandido — o caso comum "criar para o setor deste
     * técnico" sai sem toque extra; sem casamento, fica o primeiro.
     */
    function fillSectorSelect(tech) {
        var select = $('tp-t-c-group');
        if (!select) {
            return;
        }
        while (select.firstChild) {
            select.removeChild(select.firstChild);
        }
        var techGroup = (tech && Array.isArray(tech.groups) && tech.groups.length > 0)
            ? tech.groups[0] : '';
        var preselect = '';
        state.data.sectors.forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = String(s.id);
            opt.textContent = s.name;
            select.appendChild(opt);
            if (preselect === '' && techGroup !== '' && s.name === techGroup) {
                preselect = String(s.id);
            }
        });
        if (preselect !== '') {
            select.value = preselect;
        }
    }

    /** Destino "setor" mostra o bloco de setor (seletor + incluir a mim). */
    function syncCreateDest() {
        var group = createDest() === 'group';
        var block = $('tp-t-c-group-block');
        if (block) {
            block.hidden = !group;
        }
    }

    // ---- helpers do tipo/recorrência (5c-2) ----

    function createKind() {
        var checked = document.querySelector('input[name="tp-t-c-kind"]:checked');
        return checked ? checked.value : 'avulsa';
    }

    function setCreateKind(kind) {
        var radios = document.querySelectorAll('input[name="tp-t-c-kind"]');
        Array.prototype.forEach.call(radios, function (r) {
            r.checked = (r.value === kind);
        });
    }

    function monthlyMode() {
        var checked = document.querySelector('input[name="tp-t-c-monthly-mode"]:checked');
        return checked ? checked.value : 'day';
    }

    function setMonthlyMode(mode) {
        var radios = document.querySelectorAll('input[name="tp-t-c-monthly-mode"]');
        Array.prototype.forEach.call(radios, function (r) {
            r.checked = (r.value === mode);
        });
    }

    function weekdayBoxes() {
        return Array.prototype.slice.call(
            document.querySelectorAll('#tp-t-c-weekdays input[type="checkbox"]'));
    }

    /** Avulsa mostra Data+Categoria; Rotina mostra o bloco de recorrência. */
    function syncCreateKind() {
        var routine = createKind() === 'rotina';
        $('tp-t-c-date-wrap').hidden = routine;
        $('tp-t-c-cat-wrap').hidden = routine;
        $('tp-t-c-routine-block').hidden = !routine;
        // Na rotina, a descrição vira as INSTRUÇÕES (é o que o cron
        // copia para a descrição de cada dia gerado)
        $('tp-t-c-desc-label').textContent = routine
            ? 'Instruções (como fazer)' : 'Descrição';
    }

    function syncCreateFreq() {
        var f = $('tp-t-c-frequency').value;
        $('tp-t-c-block-daily').hidden = (f !== 'daily');
        $('tp-t-c-block-weekly').hidden = (f !== 'weekly');
        $('tp-t-c-block-monthly').hidden = (f !== 'monthly');
    }

    function syncMonthlyMode() {
        var pos = monthlyMode() === 'pos';
        $('tp-t-c-monthly-day-row').hidden = pos;
        $('tp-t-c-monthly-pos-row').hidden = !pos;
    }

    function closeCreateModal() {
        $('tp-t-create-modal').hidden = true;
        state.createCtx = null;
    }

    function saveCreateModal() {
        var ctx = state.createCtx;
        if (!ctx) {
            return;
        }
        var name = $('tp-t-c-name').value.trim();
        if (name === '') {
            toast('Informe o título da tarefa', true);
            $('tp-t-c-name').focus();
            return;
        }

        // 5c-3: destino do POST — técnico expandido (fluxo 5c-1/5c-2,
        // intacto) ou todos de um setor administrado. No setor viajam
        // group_id + include_me; a ação ganha o sufixo _group.
        var toGroup = createDest() === 'group';
        var dest;
        if (toGroup) {
            var select = $('tp-t-c-group');
            var groupId = (select && select.value) ? select.value : '';
            if (groupId === '') {
                toast('Selecione o setor', true);
                return;
            }
            dest = {
                group_id: groupId,
                include_me: ($('tp-t-c-include-me') && $('tp-t-c-include-me').checked) ? '1' : '0'
            };
        } else {
            dest = { tech_id: String(ctx.tech.id) };
        }

        if (createKind() !== 'rotina') {
            dest.action = toGroup ? 'create_group' : 'create';
            dest.name = name;
            dest.date = $('tp-t-c-date').value;
            dest.time_limit = $('tp-t-c-time').value;
            dest.category = $('tp-t-c-category').value.trim();
            dest.description = $('tp-t-c-description').value.trim();
            post(dest, closeCreateModal);
            return;
        }

        // 5c-2: rotina para o técnico. Checagens locais só das duas
        // ausências óbvias (mesmas mensagens do servidor); o resto —
        // XOR do mensal, datas — é o cleanFields da Routine, e o erro
        // dele volta em toast COM o modal aberto para corrigir.
        var frequency = $('tp-t-c-frequency').value;
        var weekdays = weekdayBoxes()
            .filter(function (b) { return b.checked; })
            .map(function (b) { return b.value; })
            .join(',');
        if (frequency === 'weekly' && weekdays === '') {
            toast('Selecione ao menos um dia da semana', true);
            return;
        }
        var mode = monthlyMode();
        if (frequency === 'monthly' && mode === 'day'
                && $('tp-t-c-monthday').value.trim() === '') {
            toast('Informe o dia do mês', true);
            $('tp-t-c-monthday').focus();
            return;
        }

        dest.action = toGroup ? 'create_group_routine' : 'create_routine';
        dest.name = name;
        dest.instructions = $('tp-t-c-description').value.trim();
        dest.frequency = frequency;
        dest.only_workdays = $('tp-t-c-only-workdays').checked ? '1' : '0';
        dest.weekdays = weekdays;
        // XOR do mensal: só o modo escolhido viaja preenchido
        dest.monthday = (frequency === 'monthly' && mode === 'day')
            ? $('tp-t-c-monthday').value.trim() : '';
        dest.monthweek = (frequency === 'monthly' && mode === 'pos')
            ? $('tp-t-c-monthweek').value : '';
        dest.monthweekday = (frequency === 'monthly' && mode === 'pos')
            ? $('tp-t-c-monthweekday').value : '';
        dest.time_limit = $('tp-t-c-time').value;
        dest.date_begin = $('tp-t-c-begin').value;
        dest.date_end = $('tp-t-c-end').value;
        post(dest, closeCreateModal);
    }

    // ------------------------------------------------------------------
    // Diálogo da tarefa do técnico (8e-2)
    // ------------------------------------------------------------------

    function openDialogModal(item, tech) {
        state.dialogCtx = { item: item, tech: tech };
        $('tp-t-d-title').textContent = item.name + ' — ' + tech.label;
        renderTeamDialog([], false);
        var txt = $('tp-t-d-text');
        if (txt) { txt.value = ''; }
        var fi = $('tp-t-d-file');
        if (fi) { fi.value = ''; }
        $('tp-t-dialog-modal').hidden = false;
        postDialog({ action: 'comment_list' });
    }

    function closeDialogModal() {
        $('tp-t-dialog-modal').hidden = true;
        state.dialogCtx = null;
    }

    /**
     * POST das ações do diálogo — mesmo endpoint, token e trava de busy
     * do post() principal; a resposta traz a thread (`comments`) e a
     * permissão de escrita (`can_write`) recalculadas pelo servidor.
     */
    function postDialog(fields, file) {
        if (state.busy || !state.dialogCtx) {
            return;
        }
        state.busy = true;
        // Fixado ANTES do fetch: o modal pode ter fechado (dialogCtx
        // vira null) quando a resposta chegar.
        var ctx = state.dialogCtx;

        var fd = new FormData();
        Object.keys(fields).forEach(function (key) {
            fd.append(key, fields[key]);
        });
        // 8e-3: anexo opcional — valida tipo/tamanho o SERVIDOR
        if (file) {
            fd.append('file', file);
        }
        fd.append('occurrences_id', String(state.dialogCtx.item.id));
        fd.append('tech_id', String(state.dialogCtx.tech.id));
        fd.append('_glpi_csrf_token', state.csrf);

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
                if (res && typeof res.csrf === 'string' && res.csrf !== '') {
                    state.csrf = res.csrf;
                }
                if (!res || !res.success) {
                    toast((res && res.message) ? res.message : 'Erro no diálogo', true);
                }
                renderTeamDialog(
                    (res && Array.isArray(res.comments)) ? res.comments : [],
                    !!(res && res.can_write)
                );
                // 9a-2: o servidor acabou de marcar a leitura do gestor —
                // o botão atrás do modal perde o contador na hora.
                if (res && res.success) {
                    clearUnread(ctx);
                }
            })
            .catch(function () {
                state.busy = false;
                toast('Falha de comunicação com o servidor', true);
            });
    }

    /**
     * 9a-2 — zera o não lido desta tarefa no estado local e re-renderiza
     * a lista. Só repinta se algo mudou de fato (abrir diálogo já lido
     * não deve reconstruir a tela inteira da equipe, que é grande).
     */
    function clearUnread(ctx) {
        if (!ctx || !ctx.item || !ctx.tech) {
            return;
        }
        var changed = false;
        (state.data.techs || []).forEach(function (t) {
            if (Number(t.id) !== Number(ctx.tech.id)) {
                return;
            }
            (t.items || []).forEach(function (it) {
                if (it && !it.is_native && Number(it.id) === Number(ctx.item.id)
                    && (Number(it.unread) || 0) > 0) {
                    it.unread = 0;
                    changed = true;
                }
            });
        });
        if (changed) {
            render();
        }
    }

    /** Thread do modal — textContent SEMPRE (nada de HTML do usuário). */
    function renderTeamDialog(comments, canWrite) {
        var list = $('tp-t-d-list');
        if (!list) {
            return;
        }
        list.textContent = '';
        $('tp-t-d-empty').hidden = comments.length > 0;
        $('tp-t-d-composer').hidden = !canWrite;
        $('tp-t-d-readonly').hidden = canWrite;
        comments.forEach(function (c) {
            var li = el('li', 'taskplus-dialog__item');

            var head = el('div', 'taskplus-dialog__meta');
            var who = document.createElement('strong');
            who.textContent = c.author || '(usuário removido)';
            head.appendChild(who);
            head.appendChild(el('span', '', c.date || ''));
            if (c.own) {
                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'taskplus-dialog__del';
                del.title = 'Excluir comentário';
                del.textContent = '×';
                del.addEventListener('click', function () {
                    if (window.confirm('Excluir este comentário?')) {
                        postDialog({ action: 'comment_delete', id: String(c.id) });
                    }
                });
                head.appendChild(del);
            }
            li.appendChild(head);

            var body = el('div', 'taskplus-dialog__text', c.content || '');
            li.appendChild(body);

            // 8e-3: anexo — download servido pelo plugin (gate próprio)
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

    function sendTeamComment() {
        var txt = $('tp-t-d-text');
        var text = txt ? txt.value.trim() : '';
        var fileInput = $('tp-t-d-file');
        var file = (fileInput && fileInput.files && fileInput.files.length > 0)
            ? fileInput.files[0] : null;
        if (text === '' && !file) {
            toast('Escreva o comentário ou anexe um arquivo', true);
            if (txt) { txt.focus(); }
            return;
        }
        if (txt) { txt.value = ''; }
        postDialog({ action: 'comment_add', content: text }, file);
        if (fileInput) { fileInput.value = ''; }
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
            'tp-t-p-save': savePendModal,
            'tp-t-c-cancel': closeCreateModal,
            'tp-t-c-save': saveCreateModal,
            'tp-t-d-close': closeDialogModal,
            'tp-t-d-send': sendTeamComment
        };
        Object.keys(map).forEach(function (id) {
            var node = $(id);
            if (node) {
                node.addEventListener('click', map[id]);
            }
        });

        // 5c-2: sincronia dos blocos do modal de criação. Elementos
        // podem faltar (template antigo em cache) — tudo opcional.
        Array.prototype.forEach.call(
            document.querySelectorAll('input[name="tp-t-c-kind"]'),
            function (r) { r.addEventListener('change', syncCreateKind); });
        // 5c-3: destino técnico × setor
        Array.prototype.forEach.call(
            document.querySelectorAll('input[name="tp-t-c-dest"]'),
            function (r) { r.addEventListener('change', syncCreateDest); });
        var freq = $('tp-t-c-frequency');
        if (freq) {
            freq.addEventListener('change', syncCreateFreq);
        }
        Array.prototype.forEach.call(
            document.querySelectorAll('input[name="tp-t-c-monthly-mode"]'),
            function (r) { r.addEventListener('change', syncMonthlyMode); });
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
        state.attachUrl = state.root.getAttribute('data-attachments-url') || '';

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
