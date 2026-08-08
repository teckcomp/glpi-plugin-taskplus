/**
 * Task+ — tela "Quadro" (Etapa 4d: kanban por fases).
 *
 * Contrato com o servidor:
 *  - estado inicial embutido em <script type="application/json"
 *    id="taskplus-board-data"> (gerado pelo front/board.php com
 *    JSON_HEX_* — seguro contra </script>);
 *  - toda ação vai por POST a ajax/board.php com `_glpi_csrf_token`;
 *    a resposta traz `csrf` NOVO (rotação obrigatória) e `data` com o
 *    payload completo do quadro para re-render;
 *  - as REGRAS DE MOVIMENTO moram no servidor (Board::handle). Aqui elas
 *    são só ESPELHADAS para a UX (colunas válidas acendem no arrasto);
 *    esconder alvo no JS nunca é a proteção.
 *
 * Drag-and-drop: API nativa do HTML5, sem dependências. Todo texto de
 * usuário entra no DOM via textContent (nunca innerHTML) — sem XSS.
 */
(function () {
    'use strict';

    var state = {
        root: null,
        ajaxUrl: '',
        csrf: '',
        busy: false,
        dragKey: null,
        justDragged: false,
        pendingCard: null,
        editingCard: null,
        // 5b-2 p2: busca local + período (viaja em todo POST)
        search: '',
        period: { from: '', to: '' },
        data: { date: '', columns: [], cards: [], period: { from: '', to: '', active: false } }
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
        var p = (d.period && typeof d.period === 'object') ? d.period : {};
        return {
            date: (typeof d.date === 'string') ? d.date : '',
            columns: Array.isArray(d.columns) ? d.columns : [],
            cards: Array.isArray(d.cards) ? d.cards : [],
            // 5b-2 p2: eco do período (o servidor normaliza as datas)
            period: {
                from: (typeof p.from === 'string') ? p.from : '',
                to: (typeof p.to === 'string') ? p.to : '',
                active: !!p.active
            }
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
        // re-renderiza o quadro e precisa devolver o MESMO recorte.
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

    /** O card casa com a busca (título + descrição + categoria)? */
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

    /** Barra de busca + período, criada UMA vez no init, acima do quadro. */
    function buildToolbar() {
        var anchor = $('tp-board');
        if (!anchor || $('tp-bflt-search')) {
            return;
        }

        var bar = el('div', 'taskplus-toolbar2');

        var search = document.createElement('input');
        search.type = 'search';
        search.id = 'tp-bflt-search';
        search.className = 'taskplus-toolbar2__search';
        search.placeholder = 'Buscar por título, descrição ou categoria';
        search.setAttribute('aria-label', 'Buscar');
        search.addEventListener('input', function () {
            state.search = search.value.trim();
            render();
        });
        bar.appendChild(search);

        bar.appendChild(dateField('De', 'tp-bflt-from'));
        bar.appendChild(dateField('Até', 'tp-bflt-to'));

        var apply = el('button', 'btn btn-primary btn-sm', 'Aplicar');
        apply.type = 'button';
        apply.id = 'tp-bflt-apply';
        apply.addEventListener('click', applyPeriod);
        bar.appendChild(apply);

        var clear = el('button', 'btn btn-ghost-secondary btn-sm', 'Limpar');
        clear.type = 'button';
        clear.id = 'tp-bflt-clear';
        clear.hidden = true;
        clear.addEventListener('click', clearPeriod);
        bar.appendChild(clear);

        var note = el('div', 'taskplus-toolbar2__note');
        note.id = 'tp-bflt-note';
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
            from: ($('tp-bflt-from') ? $('tp-bflt-from').value : '') || '',
            to: ($('tp-bflt-to') ? $('tp-bflt-to').value : '') || ''
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

        var from = $('tp-bflt-from');
        var to = $('tp-bflt-to');
        if (from) {
            from.value = state.period.from;
        }
        if (to) {
            to.value = state.period.to;
        }
        var clear = $('tp-bflt-clear');
        if (clear) {
            clear.hidden = !p.active;
        }
        var note = $('tp-bflt-note');
        if (note) {
            note.hidden = !p.active;
            note.textContent = p.active
                ? 'Período ativo ' + periodLabel()
                    + ' — cards do período; os itens do GLPI seguem no estado atual.'
                : '';
        }
    }

    // ------------------------------------------------------------------
    // Regras de movimento (espelho da validação do servidor)
    // ------------------------------------------------------------------

    /** Coluna de sistema pela chave ('late'|'today'|'pending'|'done'). */
    function systemCol(key) {
        var found = null;
        state.data.columns.forEach(function (col) {
            if (col.is_system && col.system_key === key) {
                found = col;
            }
        });
        return found;
    }

    /** Card pela chave composta tipo:id (id cru pode colidir entre
     *  ocorrência e item nativo — o servidor manda `card_key`). */
    function cardByKey(key) {
        var found = null;
        state.data.cards.forEach(function (card) {
            if (String(card.card_key) === String(key)) {
                found = card;
            }
        });
        return found;
    }

    /** Itemtype do card para o POST (o servidor revalida). */
    function typeOf(card) {
        if (!card.is_native) {
            return 'Occurrence';
        }
        return card.pending_type || 'Occurrence';
    }

    /**
     * Ids das colunas onde o card PODE ser solto:
     *  - Atrasadas nunca recebe (é automática);
     *  - card atrasado só vai para Concluídas ou Pendentes;
     *  - card concluído volta para fase de trabalho (não vira pendente);
     *  - card pendente vai para fase de trabalho ou Concluídas;
     *  - card normal vai para outra fase de trabalho, Concluídas ou
     *    Pendentes.
     */
    function allowedTargets(card) {
        var late = systemCol('late');
        var pending = systemCol('pending');
        var done = systemCol('done');
        var today = systemCol('today');

        var lateId = late ? Number(late.id) : -1;
        var pendingId = pending ? Number(pending.id) : -1;
        var doneId = done ? Number(done.id) : -1;
        var todayId = today ? Number(today.id) : -1;
        var current = Number(card.column);

        // Nativa: vai para Pendentes ou Concluídas (4d-3 — concluir
        // grava no GLPI via objeto nativo, sem texto); pendente volta
        // para "Para hoje" (encerra a pendência) ou conclui direto.
        if (card.is_native) {
            return (current === pendingId ? [todayId, doneId] : [pendingId, doneId])
                .filter(function (id) { return id > 0; });
        }

        // Fases de trabalho = "Para hoje" + customizadas
        var workIds = [];
        state.data.columns.forEach(function (col) {
            var id = Number(col.id);
            if (!col.is_system || col.system_key === 'today') {
                workIds.push(id);
            }
        });

        var targets = [];
        if (current === lateId) {
            targets = [doneId, pendingId];
        } else if (current === doneId) {
            targets = workIds.slice();
        } else if (current === pendingId) {
            targets = workIds.concat([doneId]);
        } else {
            targets = workIds.filter(function (id) { return id !== current; })
                .concat([doneId, pendingId]);
        }

        return targets.filter(function (id) { return id > 0; });
    }

    /** Roteia o solte para a ação certa do servidor. */
    function dropOn(cardKey, colId) {
        var card = cardByKey(cardKey);
        if (!card) {
            return;
        }
        if (allowedTargets(card).indexOf(Number(colId)) === -1) {
            return; // alvo inválido: o dragover já bloqueou, isto é cinto
        }

        var done = systemCol('done');
        var pending = systemCol('pending');

        if (pending && Number(colId) === Number(pending.id)) {
            openPendingModal(card);
            return;
        }
        if (done && Number(colId) === Number(done.id)) {
            // 4d-3: o itemtype VAI no POST — para nativa, o servidor
            // grava no GLPI via objeto nativo (e o card some do quadro
            // no re-render, porque deixa de casar com as consultas).
            post({ action: 'done', id: String(card.id), itemtype: typeOf(card) });
            return;
        }
        if (card.is_native) {
            // Restou "Para hoje" com nativa pendente: encerra a pendência.
            post({ action: 'unpending', id: String(card.id), itemtype: typeOf(card) });
            return;
        }
        post({ action: 'set_phase', id: String(card.id), itemtype: 'Occurrence', phases_id: String(colId) });
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------

    function render() {
        var board = $('tp-board');
        board.textContent = '';

        if (state.data.columns.length === 0) {
            var empty = el('div', 'taskplus-empty');
            empty.appendChild(el('i', 'ti ti-layout-kanban taskplus-empty__icon'));
            empty.appendChild(el('h3', null, 'Quadro indisponível'));
            empty.appendChild(el('p', null, 'As fases do quadro não puderam ser carregadas.'));
            board.appendChild(empty);
            return;
        }

        state.data.columns.forEach(function (col) {
            board.appendChild(column(col));
        });
    }

    function column(col) {
        var colId = Number(col.id);

        var box = el('div', 'taskplus-bcol'
            + (col.is_system ? ' taskplus-bcol--system' : '')
            + (col.is_system && col.system_key === 'late' ? ' taskplus-bcol--late' : ''));
        box.setAttribute('data-col-id', String(colId));

        var cards = state.data.cards.filter(function (card) {
            return Number(card.column) === colId
                && matchesSearch(card);
        });

        // Cabeçalho: barra na cor da fase + nome + tag do setor + contagem
        var head = el('div', 'taskplus-bcol__head');
        head.style.borderTopColor = col.color || '#5a6b7b'; // cor já normalizada (#rrggbb) no servidor
        var title = el('div', 'taskplus-bcol__title');
        title.appendChild(el('span', 'taskplus-bcol__name', col.name || ''));
        if (col.group_name) {
            title.appendChild(el('span', 'taskplus-badge taskplus-badge--sector', col.group_name));
        }
        head.appendChild(title);
        head.appendChild(el('span', 'taskplus-section__count', String(cards.length)));
        box.appendChild(head);

        var body = el('div', 'taskplus-bcol__body');
        if (cards.length === 0) {
            body.appendChild(el('div', 'taskplus-bcol__empty',
                (col.is_system && col.system_key === 'late') ? 'Nada atrasado' : 'Sem tarefas'));
        } else {
            cards.forEach(function (item) {
                body.appendChild(card(item));
            });
        }
        box.appendChild(body);

        // Alvos de solte: acendem no dragstart (classe --allowed)
        box.addEventListener('dragover', function (ev) {
            if (state.dragKey === null) {
                return;
            }
            var dragged = cardByKey(state.dragKey);
            if (dragged && allowedTargets(dragged).indexOf(colId) !== -1) {
                ev.preventDefault(); // sem preventDefault o navegador recusa o drop
                box.classList.add('taskplus-bcol--over');
            }
        });
        box.addEventListener('dragleave', function () {
            box.classList.remove('taskplus-bcol--over');
        });
        box.addEventListener('drop', function (ev) {
            ev.preventDefault();
            box.classList.remove('taskplus-bcol--over');
            if (state.dragKey !== null) {
                dropOn(state.dragKey, colId);
            }
        });

        return box;
    }

    function card(item) {
        var c = el('div', 'taskplus-bcard'
            + (item.is_native ? ' taskplus-bcard--native' : '')
            + (item.is_done ? ' taskplus-bcard--done' : '')
            + (item.is_pending ? ' taskplus-bcard--pending' : '')
            + (item.is_late ? ' taskplus-bcard--late' : ''));
        c.draggable = true;
        c.title = item.is_native
            ? 'Clique para abrir no GLPI; arraste para Pendentes ou Concluídas'
            : 'Clique para editar; arraste entre as colunas';

        c.appendChild(el('div', 'taskplus-bcard__name', item.name || '(sem título)'));

        var badges = el('div', 'taskplus-card__badges');
        if (item.is_routine) {
            badges.appendChild(el('span', 'taskplus-badge', 'rotina'));
        }
        if (item.date && state.data.date && item.date !== state.data.date && item.date_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--late', item.date_label));
        }
        if (item.time_limit) {
            badges.appendChild(el('span',
                'taskplus-badge' + (item.is_late ? ' taskplus-badge--late' : ' taskplus-badge--limit'),
                'até ' + item.time_limit));
        }
        if (item.is_native && item.ticket_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--ticket',
                'Chamado ' + item.ticket_label));
        }
        if (item.is_native && item.project_name) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--project',
                'Projeto: ' + item.project_name));
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
        }
        if (item.is_done && item.done_time) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--done',
                'concluída às ' + item.done_time));
        }
        if (badges.childNodes.length > 0) {
            c.appendChild(badges);
        }

        // Clique (que não foi arrasto): própria abre o modal de edição
        // (regras da 4b: rotina muda só o dia); nativa abre o item no
        // GLPI em outra aba.
        c.addEventListener('click', function () {
            if (state.justDragged) {
                return;
            }
            if (item.is_native) {
                if (item.url) {
                    window.open(item.url, '_blank');
                }
                return;
            }
            openEditModal(item);
        });

        c.addEventListener('dragstart', function (ev) {
            state.dragKey = String(item.card_key || '');
            c.classList.add('taskplus-bcard--dragging');
            // Acende os alvos válidos deste card
            var targets = allowedTargets(item);
            document.querySelectorAll('.taskplus-bcol').forEach(function (colEl) {
                var id = Number(colEl.getAttribute('data-col-id'));
                colEl.classList.toggle('taskplus-bcol--allowed', targets.indexOf(id) !== -1);
            });
            if (ev.dataTransfer) {
                ev.dataTransfer.effectAllowed = 'move';
                try {
                    ev.dataTransfer.setData('text/plain', String(item.card_key || ''));
                } catch (e) {
                    // IE11 e afins: o estado já está em state.dragKey
                }
            }
        });
        c.addEventListener('dragend', function () {
            state.dragKey = null;
            // Suprime o click fantasma que alguns navegadores disparam
            // logo depois do solte (abriria o modal sem querer).
            state.justDragged = true;
            window.setTimeout(function () { state.justDragged = false; }, 50);
            c.classList.remove('taskplus-bcard--dragging');
            document.querySelectorAll('.taskplus-bcol').forEach(function (colEl) {
                colEl.classList.remove('taskplus-bcol--allowed', 'taskplus-bcol--over');
            });
        });

        return c;
    }

    // ------------------------------------------------------------------
    // Modal de edição (4d-2 — mesmas regras da tela Hoje / Etapa 4b)
    // ------------------------------------------------------------------

    function openEditModal(item) {
        state.editingCard = item;
        $('tp-be-title').textContent = item.is_routine
            ? 'Editar só a tarefa de hoje (a rotina não muda)'
            : 'Editar tarefa';
        $('tp-be-name').value = item.name || '';
        $('tp-be-date').value = item.date || (state.data.date || '');
        // Ocorrência de rotina: a DATA não é editável (regra da 4b,
        // confirmada na homologação do 4d — o servidor a descarta de
        // qualquer jeito; aqui só fica claro). Atrasada se resolve
        // concluindo ou pendenciando.
        $('tp-be-date').disabled = !!item.is_routine;
        $('tp-be-time').value = item.time_limit || '';
        $('tp-be-category').value = item.category || '';
        $('tp-be-description').value = item.description || '';
        $('tp-be-modal').hidden = false;
        $('tp-be-name').focus();
    }

    function closeEditModal() {
        $('tp-be-modal').hidden = true;
        state.editingCard = null;
    }

    function saveEdit() {
        var item = state.editingCard;
        if (!item) {
            return;
        }
        var name = $('tp-be-name').value.trim();
        if (name === '') {
            toast('Informe o título da tarefa', true);
            $('tp-be-name').focus();
            return;
        }
        post({
            action: 'update',
            id: String(item.id),
            itemtype: 'Occurrence',
            name: name,
            date: $('tp-be-date').value,
            time_limit: $('tp-be-time').value,
            category: $('tp-be-category').value.trim(),
            description: $('tp-be-description').value.trim()
        }, closeEditModal);
    }

    // ------------------------------------------------------------------
    // Modal de pendência (mesmo contrato da Etapa 4b)
    // ------------------------------------------------------------------

    function openPendingModal(item) {
        state.pendingCard = item;
        $('tp-bp-title').textContent = item.name || '(sem título)';
        $('tp-bp-reason').value = item.pending_reason || '';
        // Sugestão: amanhã (pendência para hoje nasceria vencida) e fim
        // de expediente — mesmos defaults da tela Hoje.
        $('tp-bp-until').value = item.pending_until || tomorrow();
        $('tp-bp-time').value = item.pending_time || '18:00';
        $('tp-bp-modal').hidden = false;
        $('tp-bp-reason').focus();
    }

    function closePendingModal() {
        $('tp-bp-modal').hidden = true;
        state.pendingCard = null;
    }

    function savePending() {
        var item = state.pendingCard;
        if (!item) {
            return;
        }
        var reason = $('tp-bp-reason').value.trim();
        if (reason === '') {
            toast('Informe o motivo da pendência', true);
            $('tp-bp-reason').focus();
            return;
        }
        var time = $('tp-bp-time').value.trim();
        if (time === '') {
            toast('Informe a hora de retorno', true);
            $('tp-bp-time').focus();
            return;
        }
        post({
            action: 'pending',
            id: String(item.id),
            itemtype: typeOf(item),
            reason: reason,
            pending_until: $('tp-bp-until').value,
            pending_time: time
        }, closePendingModal);
    }

    function tomorrow() {
        var d = new Date();
        d.setDate(d.getDate() + 1);
        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    // ------------------------------------------------------------------
    // Inicialização
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-board');
        if (!state.root) {
            return; // não está na tela Quadro
        }
        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';

        var raw = null;
        var dataEl = $('taskplus-board-data');
        if (dataEl) {
            try {
                raw = JSON.parse(dataEl.textContent);
            } catch (e) {
                raw = null; // JSON ruim → estrutura vazia, tela não quebra
            }
        }
        state.data = safeData(raw);

        // 5b-2 p2: barra de busca + período acima do quadro
        buildToolbar();
        syncToolbar();

        $('tp-be-cancel').addEventListener('click', closeEditModal);
        $('tp-be-save').addEventListener('click', saveEdit);
        $('tp-be-modal').addEventListener('click', function (ev) {
            if (ev.target === $('tp-be-modal')) {
                closeEditModal(); // clique no fundo fecha
            }
        });
        $('tp-bp-cancel').addEventListener('click', closePendingModal);
        $('tp-bp-save').addEventListener('click', savePending);
        $('tp-bp-modal').addEventListener('click', function (ev) {
            if (ev.target === $('tp-bp-modal')) {
                closePendingModal(); // clique no fundo fecha
            }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') {
                return;
            }
            if (!$('tp-bp-modal').hidden) {
                closePendingModal();
            }
            if (!$('tp-be-modal').hidden) {
                closeEditModal();
            }
        });

        render();
    }

    // Exposto para teste (jsdom) e para depuração no console
    window.TaskplusBoard = {
        init: init,
        render: render,
        safeData: safeData,
        allowedTargets: allowedTargets,
        dropOn: dropOn,
        openEditModal: openEditModal,
        state: state
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
