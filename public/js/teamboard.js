/**
 * Task+ — Quadro de Equipe (13b, decisões nº 65/66).
 *
 * Kanban POR SETOR com tarefas de equipe (uma tarefa, vários
 * colaboradores). Mesmo padrão do board.js: payload inicial embutido,
 * re-render com o payload de cada resposta, zero innerHTML. 13c: os
 * cards arrastam entre as fases; todo solte abre o modal de comentário
 * obrigatório (decisão nº 66) e vai ao servidor como `move`; o modal
 * de edição tem o diálogo (mesmo ajax/comment.php da Hoje).
 *
 * Contrato com o ajax/teamboard.php: POST com `action`, `groups_id`
 * (setor exibido, em TODO POST) e campos; resposta {success, message,
 * csrf, data}.
 */
(function () {
    'use strict';

    var state = {
        root: null,
        ajaxUrl: '',
        csrf: '',
        userId: 0,
        busy: false,
        editingCard: null,
        creating: false,
        search: '',
        period: { from: '', to: '' }, // 13c-2: viaja em todo POST
        collab: [],      // 13b-2: ids escolhidos no modal (ordem de escolha)
        collabActive: -1, // índice realçado na lista de sugestões (teclado)
        // 13c
        dragKey: null,
        justDragged: false,
        moveCard: null,   // card do modal de movimento
        moveColId: 0,     // coluna alvo do modal de movimento
        commentsUrl: '',
        attachUrl: '',
        dialogOccId: null,
        data: { date: '', groups: [], group_id: 0, can_manage: false, columns: [], cards: [], members: [] }
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
    // 12a — links clicáveis (cópia idêntica do board.js)
    // ------------------------------------------------------------------

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

    /** 12c — links da descrição abaixo do textarea (cópia do board.js). */
    function renderDescLinks(textareaId) {
        var ta = $(textareaId);
        if (!ta || !ta.parentNode) {
            return;
        }
        var boxId = textareaId + '-links';
        var box = $(boxId);
        if (!box) {
            box = el('div', 'taskplus-desc-links');
            box.id = boxId;
            ta.parentNode.parentNode.insertBefore(box, ta.parentNode.nextSibling);
            ta.addEventListener('input', function () {
                renderDescLinks(textareaId);
            });
        }
        box.textContent = '';
        var anchors = linkify(document.createElement('div'), ta.value).querySelectorAll('a');
        box.hidden = anchors.length === 0;
        if (anchors.length === 0) {
            return;
        }
        box.appendChild(el('span', 'taskplus-desc-links__label', 'Links:'));
        Array.prototype.forEach.call(anchors, function (a) {
            box.appendChild(a);
        });
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
            groups: Array.isArray(d.groups) ? d.groups : [],
            group_id: Number(d.group_id) || 0,
            can_manage: !!d.can_manage,
            columns: Array.isArray(d.columns) ? d.columns : [],
            cards: Array.isArray(d.cards) ? d.cards : [],
            members: Array.isArray(d.members) ? d.members : [],
            period: {
                from: (p.from && typeof p.from === 'string') ? p.from : '',
                to: (p.to && typeof p.to === 'string') ? p.to : '',
                active: !!p.active
            }
        };
    }

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
            var v = fields[key];
            if (Array.isArray(v)) {
                v.forEach(function (item) {
                    fd.append(key + '[]', String(item));
                });
            } else {
                fd.append(key, v);
            }
        });
        // O setor exibido viaja SEMPRE: o payload da resposta é dele.
        if (!fields.groups_id) {
            fd.append('groups_id', String(state.data.group_id || 0));
        }
        // 13c-2: período ativo acompanha toda ação
        if (state.period.from !== '') {
            fd.append('period_from', state.period.from);
        }
        if (state.period.to !== '') {
            fd.append('period_to', state.period.to);
        }
        fd.append('_glpi_csrf_token', state.csrf);

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
                    toast((res && res.message) ? res.message : 'Erro ao processar a ação', true);
                } else if (res.message) {
                    toast(res.message, false);
                }
                if (res && res.data) {
                    state.data = safeData(res.data);
                    render();
                }
                if (res && res.success && typeof onSuccess === 'function') {
                    onSuccess(res);
                }
            })
            .catch(function () {
                state.busy = false;
                toast('Falha de comunicação com o servidor', true);
            });
    }

    // ------------------------------------------------------------------
    // Busca local
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
        if (norm(item.name).indexOf(q) !== -1
            || norm(item.description).indexOf(q) !== -1
            || norm(item.category).indexOf(q) !== -1) {
            return true;
        }
        // Também pelo nome do colaborador: "o que o Fulano tem?"
        return (Array.isArray(item.collaborators) ? item.collaborators : []).some(function (c) {
            return norm(c.label).indexOf(q) !== -1;
        });
    }

    // ------------------------------------------------------------------
    // Toolbar: seletor de setor + busca + Nova tarefa
    // ------------------------------------------------------------------

    function renderToolbar() {
        var bar = $('tp-tb-toolbar');
        if (!bar) {
            return;
        }
        bar.textContent = '';

        var groups = state.data.groups;
        var sector = el('div', 'taskplus-toolbar2__sector');
        sector.appendChild(el('span', '', 'Setor:'));
        if (groups.length > 1) {
            var sel = document.createElement('select');
            sel.id = 'tp-tb-group';
            sel.className = 'taskplus-toolbar2__select';
            sel.setAttribute('aria-label', 'Setor');
            groups.forEach(function (g) {
                var opt = document.createElement('option');
                opt.value = String(g.id);
                opt.textContent = g.name || ('Setor #' + g.id);
                opt.selected = Number(g.id) === Number(state.data.group_id);
                sel.appendChild(opt);
            });
            sel.addEventListener('change', function () {
                state.search = '';
                post({ action: 'list', groups_id: sel.value });
            });
            sector.appendChild(sel);
        } else {
            var g0 = groups[0];
            sector.appendChild(el('strong', '', g0 ? (g0.name || ('Setor #' + g0.id)) : '—'));
        }
        if (state.data.can_manage) {
            sector.appendChild(el('span', 'taskplus-badge taskplus-badge--sector', 'gestor'));
        }
        bar.appendChild(sector);

        var search = document.createElement('input');
        search.type = 'search';
        search.id = 'tp-tb-search';
        search.className = 'taskplus-toolbar2__search';
        search.placeholder = 'Buscar por título, descrição, categoria ou colaborador';
        search.setAttribute('aria-label', 'Buscar');
        search.value = state.search;
        search.addEventListener('input', function () {
            state.search = search.value.trim();
            renderBoard();
        });
        bar.appendChild(search);

        // 13c-2: período (De/Até) — mesmo contrato do Quadro pessoal
        bar.appendChild(dateField('De', 'tp-tb-from', state.period.from));
        bar.appendChild(dateField('Até', 'tp-tb-to', state.period.to));
        var apply = el('button', 'btn btn-primary btn-sm', 'Aplicar');
        apply.type = 'button';
        apply.id = 'tp-tb-apply';
        apply.addEventListener('click', applyPeriod);
        bar.appendChild(apply);
        var clear = el('button', 'btn btn-ghost-secondary btn-sm', 'Limpar');
        clear.type = 'button';
        clear.id = 'tp-tb-clear';
        clear.hidden = !state.data.period.active;
        clear.addEventListener('click', clearPeriod);
        bar.appendChild(clear);

        var add = el('button', 'btn btn-primary btn-sm taskplus-toolbar2__new');
        add.type = 'button';
        add.id = 'tp-tb-new';
        add.appendChild(el('i', 'ti ti-plus'));
        add.appendChild(document.createTextNode('\u00a0Nova tarefa de equipe'));
        add.disabled = state.data.group_id === 0;
        add.addEventListener('click', openCreateModal);
        bar.appendChild(add);

        var note = el('div', 'taskplus-toolbar2__note');
        note.id = 'tp-tb-note';
        note.hidden = !state.data.period.active;
        note.textContent = state.data.period.active
            ? 'Período ativo ' + periodLabel() + ' — todas as tarefas de equipe do intervalo, inclusive concluídas.'
            : '';
        bar.appendChild(note);
    }

    function dateField(label, id, value) {
        var wrap = el('label', 'taskplus-toolbar2__label', label);
        var input = document.createElement('input');
        input.type = 'date';
        input.id = id;
        input.value = value || '';
        wrap.appendChild(input);
        return wrap;
    }

    function fmtDate(iso) {
        return (iso && iso.length === 10) ? iso.substr(8, 2) + '/' + iso.substr(5, 2) + '/' + iso.substr(0, 4) : '';
    }

    function periodLabel() {
        var f = fmtDate(state.data.period.from);
        var t = fmtDate(state.data.period.to);
        if (f !== '' && t !== '') {
            return 'de ' + f + ' a ' + t;
        }
        return (f !== '') ? 'a partir de ' + f : 'até ' + t;
    }

    function applyPeriod() {
        state.period = {
            from: ($('tp-tb-from') ? $('tp-tb-from').value : '') || '',
            to: ($('tp-tb-to') ? $('tp-tb-to').value : '') || ''
        };
        post({ action: 'list' });
    }

    function clearPeriod() {
        state.period = { from: '', to: '' };
        post({ action: 'list' });
    }

    /** O servidor normaliza o período; o estado local espelha o eco. */
    function syncPeriod() {
        var p = state.data.period;
        state.period = { from: p.active ? p.from : '', to: p.active ? p.to : '' };
    }

    // ------------------------------------------------------------------
    // Render do quadro
    // ------------------------------------------------------------------

    function render() {
        syncPeriod();
        renderToolbar();
        renderBoard();
    }

    function renderBoard() {
        var board = $('tp-tboard');
        if (!board) {
            return;
        }
        board.textContent = '';

        if (state.data.group_id === 0 || state.data.columns.length === 0) {
            var empty = el('div', 'taskplus-empty');
            empty.appendChild(el('i', 'ti ti-users-group taskplus-empty__icon'));
            empty.appendChild(el('h3', null, 'Quadro de Equipe indisponível'));
            empty.appendChild(el('p', null, state.data.group_id === 0
                ? 'Você não participa de nenhum setor.'
                : 'As fases do quadro não puderam ser carregadas.'));
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
            return Number(card.column) === colId && matchesSearch(card);
        });

        var head = el('div', 'taskplus-bcol__head');
        head.style.borderTopColor = col.color || '#5a6b7b';
        var title = el('div', 'taskplus-bcol__title');
        title.appendChild(el('span', 'taskplus-bcol__name', col.name || ''));
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

        // 13c: alvos de solte (acendem no dragstart com --allowed)
        box.addEventListener('dragover', function (ev) {
            if (state.dragKey === null) {
                return;
            }
            var dragged = cardByKey(state.dragKey);
            if (dragged && allowedTargets(dragged).indexOf(colId) !== -1) {
                ev.preventDefault();
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

    // ------------------------------------------------------------------
    // 13c — arrasto entre fases (mesmas regras do Quadro pessoal)
    // ------------------------------------------------------------------

    function systemCol(key) {
        var found = null;
        state.data.columns.forEach(function (col) {
            if (col.is_system && col.system_key === key) {
                found = col;
            }
        });
        return found;
    }

    function cardByKey(key) {
        var found = null;
        state.data.cards.forEach(function (card) {
            if (String(card.card_key) === String(key)) {
                found = card;
            }
        });
        return found;
    }

    /**
     * Colunas onde o card PODE ser solto: Atrasadas nunca; atrasado só
     * Concluídas/Pendentes; concluído volta a fase de trabalho;
     * pendente vai a fase de trabalho ou Concluídas; normal vai a outra
     * fase de trabalho, Concluídas ou Pendentes.
     */
    function allowedTargets(card) {
        var late = systemCol('late');
        var pending = systemCol('pending');
        var done = systemCol('done');
        var lateId = late ? Number(late.id) : -1;
        var pendingId = pending ? Number(pending.id) : -1;
        var doneId = done ? Number(done.id) : -1;
        var current = Number(card.column);

        var workIds = [];
        state.data.columns.forEach(function (col) {
            var id = Number(col.id);
            if (!col.is_system || col.system_key === 'today') {
                workIds.push(id);
            }
        });

        var targets;
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

    /** Todo solte válido abre o modal de comentário (decisão nº 66). */
    function dropOn(cardKey, colId) {
        var card = cardByKey(cardKey);
        if (!card || allowedTargets(card).indexOf(Number(colId)) === -1) {
            return;
        }
        openMoveModal(card, Number(colId));
    }

    function columnById(id) {
        var found = null;
        state.data.columns.forEach(function (col) {
            if (Number(col.id) === Number(id)) {
                found = col;
            }
        });
        return found;
    }

    function tomorrow() {
        var d = new Date();
        d.setDate(d.getDate() + 1);
        var m = String(d.getMonth() + 1);
        var day = String(d.getDate());
        return d.getFullYear() + '-' + (m.length < 2 ? '0' + m : m) + '-' + (day.length < 2 ? '0' + day : day);
    }

    function openMoveModal(card, colId) {
        var col = columnById(colId);
        if (!col) {
            return;
        }
        state.moveCard = card;
        state.moveColId = colId;
        var isPending = col.is_system && col.system_key === 'pending';
        var isDone = col.is_system && col.system_key === 'done';
        $('tp-tm-title').textContent = isDone
            ? 'Concluir para a equipe'
            : (isPending ? 'Marcar como pendente' : 'Mover para "' + (col.name || '') + '"');
        $('tp-tm-subject').textContent = card.name || '(sem título)';
        $('tp-tm-label').textContent = (isPending ? 'Motivo' : 'Comentário') + ' *';
        $('tp-tm-comment').value = '';
        $('tp-tm-pending').hidden = !isPending;
        if (isPending) {
            $('tp-tm-until').value = card.pending_until || tomorrow();
            $('tp-tm-time').value = card.pending_time || '18:00';
        }
        $('tp-tm-save').textContent = isDone ? 'Concluir' : (isPending ? 'Salvar' : 'Mover');
        $('tp-tm-modal').hidden = false;
        $('tp-tm-comment').focus();
    }

    function closeMoveModal() {
        $('tp-tm-modal').hidden = true;
        state.moveCard = null;
        state.moveColId = 0;
    }

    function saveMove() {
        var card = state.moveCard;
        if (!card) {
            return;
        }
        var comment = $('tp-tm-comment').value.trim();
        if (comment === '') {
            toast('Escreva um comentário para mover a tarefa', true);
            $('tp-tm-comment').focus();
            return;
        }
        var fields = {
            action: 'move',
            id: String(card.id),
            phases_id: String(state.moveColId),
            comment: comment
        };
        if (!$('tp-tm-pending').hidden) {
            fields.pending_until = $('tp-tm-until').value;
            fields.pending_time = $('tp-tm-time').value;
            if (fields.pending_until === '' || fields.pending_time === '') {
                toast('Informe a data e a hora de retorno', true);
                return;
            }
        }
        post(fields, closeMoveModal);
    }

    function card(item) {
        var c = el('div', 'taskplus-bcard taskplus-bcard--team'
            + (item.is_done ? ' taskplus-bcard--done' : '')
            + (item.is_pending ? ' taskplus-bcard--pending' : '')
            + (item.is_late ? ' taskplus-bcard--late' : ''));
        c.setAttribute('data-card-key', String(item.card_key || ''));
        c.draggable = true; // 13c
        c.title = 'Clique para ver ou editar; arraste entre as fases';

        c.appendChild(el('div', 'taskplus-bcard__name', item.name || '(sem título)'));
        if (item.description) {
            var desc = linkify(el('div', 'taskplus-bcard__desc'), item.description);
            desc.querySelectorAll('a').forEach(function (a) {
                a.draggable = false; // o arrasto leva o CARD, não a URL
            });
            c.appendChild(desc);
        }

        var badges = el('div', 'taskplus-card__badges');
        if (item.date && state.data.date && item.date !== state.data.date && item.date_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--late', item.date_label));
        }
        if (item.time_limit) {
            badges.appendChild(el('span',
                'taskplus-badge' + (item.is_late ? ' taskplus-badge--late' : ' taskplus-badge--limit'),
                'até ' + item.time_limit));
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
            if (item.pending_by_label) {
                badges.appendChild(el('span', 'taskplus-badge', 'por ' + item.pending_by_label));
            }
        }
        if (item.is_done && item.done_time) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--done',
                'concluída às ' + item.done_time + (item.done_by_label ? ' por ' + item.done_by_label : '')));
        }
        var unread = Number(item.unread) || 0;
        if (unread > 0) {
            var ub = el('span', 'taskplus-badge taskplus-badge--unread',
                '\uD83D\uDCAC ' + (unread > 9 ? '9+' : String(unread)));
            ub.title = (unread === 1)
                ? '1 comentário não lido — abrir diálogo'
                : unread + ' comentários não lidos — abrir diálogo';
            badges.appendChild(ub);
        }
        if (badges.childNodes.length > 0) {
            c.appendChild(badges);
        }

        // Colaboradores: um chip por pessoa; o próprio usuário em destaque
        var collab = el('div', 'taskplus-bcard__collab');
        (Array.isArray(item.collaborators) ? item.collaborators : []).forEach(function (p) {
            var isMe = Number(p.id) === state.userId;
            var chip = el('span', 'taskplus-chip' + (isMe ? ' taskplus-chip--me' : ''), p.label || '');
            chip.title = p.label || '';
            collab.appendChild(chip);
        });
        if (collab.childNodes.length > 0) {
            c.appendChild(collab);
        }

        c.addEventListener('click', function () {
            if (state.justDragged) {
                return;
            }
            openEditModal(item);
        });
        c.addEventListener('dragstart', function (ev) {
            state.dragKey = String(item.card_key || '');
            c.classList.add('taskplus-bcard--dragging');
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
                    // estado já em state.dragKey
                }
            }
        });
        c.addEventListener('dragend', function () {
            state.dragKey = null;
            state.justDragged = true; // suprime o click fantasma pós-solte
            window.setTimeout(function () { state.justDragged = false; }, 50);
            c.classList.remove('taskplus-bcard--dragging');
            document.querySelectorAll('.taskplus-bcol').forEach(function (colEl) {
                colEl.classList.remove('taskplus-bcol--allowed', 'taskplus-bcol--over');
            });
        });
        return c;
    }

    // ------------------------------------------------------------------
    // Modal de nova/edição
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // 13b-2 — seletor de colaboradores com busca
    //
    // Chips dos escolhidos + campo que filtra os membros do setor. A
    // fonte da verdade é state.collab (ids); o DOM só espelha. Escala
    // para setores grandes: a lista mostra no máximo MAX_SUGGEST
    // nomes por vez, e o resto aparece ao digitar.
    // ------------------------------------------------------------------

    var MAX_SUGGEST = 8;

    function memberById(id) {
        var found = null;
        state.data.members.forEach(function (m) {
            if (Number(m.id) === Number(id)) {
                found = m;
            }
        });
        return found;
    }

    /** (Re)inicia a seleção com os ids dados (só os que são membros). */
    function renderCollab(selectedIds) {
        state.collab = [];
        (selectedIds || []).forEach(function (id) {
            if (memberById(id) && state.collab.indexOf(Number(id)) === -1) {
                state.collab.push(Number(id));
            }
        });
        var input = $('tp-te-collab-search');
        if (input) {
            input.value = '';
            input.disabled = state.data.members.length === 0;
            input.placeholder = state.data.members.length === 0
                ? 'Nenhum membro com direito de tarefa neste setor'
                : 'Digite um nome para adicionar…';
        }
        renderChips();
        hideSuggest();
    }

    function renderChips() {
        var box = $('tp-te-collab-chips');
        if (!box) {
            return;
        }
        box.textContent = '';
        state.collab.forEach(function (id) {
            var m = memberById(id);
            if (!m) {
                return;
            }
            var chip = el('span', 'taskplus-chip' + (Number(id) === state.userId ? ' taskplus-chip--me' : ''), m.label || '');
            var x = el('button', 'taskplus-chip__x', '\u00D7');
            x.type = 'button';
            x.title = 'Remover ' + (m.label || '');
            x.setAttribute('aria-label', x.title);
            x.addEventListener('click', function () {
                removeCollab(id);
            });
            chip.appendChild(x);
            box.appendChild(chip);
        });
    }

    function addCollab(id) {
        id = Number(id);
        if (!memberById(id) || state.collab.indexOf(id) !== -1) {
            return;
        }
        state.collab.push(id);
        renderChips();
        var input = $('tp-te-collab-search');
        if (input) {
            input.value = '';
            input.focus();
        }
        renderSuggest();
    }

    function removeCollab(id) {
        state.collab = state.collab.filter(function (x) {
            return x !== Number(id);
        });
        renderChips();
        renderSuggest();
    }

    function selectedCollab() {
        return state.collab.slice();
    }

    /** Membros que casam com o texto digitado e ainda não foram escolhidos. */
    function suggestions() {
        var input = $('tp-te-collab-search');
        var q = norm(input ? input.value.trim() : '');
        return state.data.members.filter(function (m) {
            return state.collab.indexOf(Number(m.id)) === -1
                && (q === '' || norm(m.label).indexOf(q) !== -1);
        });
    }

    function renderSuggest() {
        var list = $('tp-te-collab-list');
        var input = $('tp-te-collab-search');
        if (!list || !input || input.disabled) {
            return;
        }
        list.textContent = '';
        var all = suggestions();
        var shown = all.slice(0, MAX_SUGGEST);
        if (state.collabActive >= shown.length) {
            state.collabActive = shown.length - 1;
        }
        if (shown.length === 0) {
            list.appendChild(el('li', 'is-empty', all.length === 0 && state.collab.length === state.data.members.length
                ? 'Todos os membros já estão na tarefa'
                : 'Ninguém com esse nome no setor'));
        }
        shown.forEach(function (m, i) {
            var li = el('li', i === state.collabActive ? 'is-active' : '');
            li.setAttribute('data-id', String(m.id));
            li.textContent = m.label || '';
            // mousedown, não click: o click chegaria depois do blur do
            // input, que já teria escondido a lista.
            li.addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                addCollab(m.id);
            });
            list.appendChild(li);
        });
        if (all.length > shown.length) {
            list.appendChild(el('li', 'is-empty', '… mais ' + (all.length - shown.length) + ' — continue digitando'));
        }
        list.hidden = false;
    }

    function hideSuggest() {
        var list = $('tp-te-collab-list');
        if (list) {
            list.hidden = true;
        }
        state.collabActive = -1;
    }

    function onCollabKey(ev) {
        var shown = suggestions().slice(0, MAX_SUGGEST);
        if (ev.key === 'ArrowDown') {
            ev.preventDefault();
            state.collabActive = Math.min(state.collabActive + 1, shown.length - 1);
            renderSuggest();
        } else if (ev.key === 'ArrowUp') {
            ev.preventDefault();
            state.collabActive = Math.max(state.collabActive - 1, 0);
            renderSuggest();
        } else if (ev.key === 'Enter') {
            ev.preventDefault();
            // Enter com um só resultado adiciona direto; senão o realçado
            var pick = (shown.length === 1) ? shown[0] : shown[state.collabActive];
            if (pick) {
                addCollab(pick.id);
            }
        } else if (ev.key === 'Backspace' && ev.target.value === '' && state.collab.length > 0) {
            removeCollab(state.collab[state.collab.length - 1]);
        } else if (ev.key === 'Escape') {
            hideSuggest();
            ev.stopPropagation(); // não fecha o modal junto
        }
    }

    function bindCollab() {
        var input = $('tp-te-collab-search');
        if (!input) {
            return;
        }
        input.addEventListener('input', function () {
            state.collabActive = 0;
            renderSuggest();
        });
        input.addEventListener('focus', function () {
            state.collabActive = -1;
            renderSuggest();
        });
        input.addEventListener('blur', hideSuggest);
        input.addEventListener('keydown', onCollabKey);
        var all = $('tp-te-collab-all');
        if (all) {
            all.addEventListener('click', function () {
                state.data.members.forEach(function (m) {
                    if (state.collab.indexOf(Number(m.id)) === -1) {
                        state.collab.push(Number(m.id));
                    }
                });
                renderChips();
                hideSuggest();
            });
        }
        var none = $('tp-te-collab-none');
        if (none) {
            none.addEventListener('click', function () {
                state.collab = [];
                renderChips();
                hideSuggest();
            });
        }
    }

    function openCreateModal() {
        if (state.data.group_id === 0) {
            return;
        }
        state.editingCard = null;
        state.creating = true;
        $('tp-te-title').textContent = 'Nova tarefa de equipe';
        $('tp-te-name').value = '';
        $('tp-te-date').value = state.data.date || '';
        $('tp-te-time').value = '';
        $('tp-te-category').value = '';
        $('tp-te-description').value = '';
        renderDescLinks('tp-te-description');
        // Quem cria já entra como colaborador, se for membro
        renderCollab([state.userId]);
        setDialog(null); // 13c: tarefa nova não tem diálogo
        $('tp-te-delete').hidden = true;
        $('tp-te-modal').hidden = false;
        $('tp-te-name').focus();
    }

    function openEditModal(item) {
        state.creating = false;
        state.editingCard = item;
        $('tp-te-title').textContent = 'Editar tarefa de equipe';
        $('tp-te-name').value = item.name || '';
        $('tp-te-date').value = item.date || (state.data.date || '');
        $('tp-te-time').value = item.time_limit || '';
        $('tp-te-category').value = item.category || '';
        $('tp-te-description').value = item.description || '';
        renderDescLinks('tp-te-description');
        renderCollab(Array.isArray(item.collaborator_ids) ? item.collaborator_ids.map(Number) : []);
        // Excluir: criador ou gestor (o servidor revalida — T18)
        $('tp-te-delete').hidden = !(state.data.can_manage || Number(item.created_by_id) === state.userId);
        setDialog(item); // 13c
        $('tp-te-modal').hidden = false;
        $('tp-te-name').focus();
    }

    function closeModal() {
        $('tp-te-modal').hidden = true;
        state.editingCard = null;
        state.creating = false;
        state.dialogOccId = null;
    }

    // ------------------------------------------------------------------
    // 13c — Diálogo da tarefa (mesmo contrato da Hoje: 8e-1/8e-3/9a-1,
    // ids tp-td-*). Mesmo endpoint, mesma rotação do csrf, textContent.
    // ------------------------------------------------------------------

    function setDialog(item) {
        state.dialogOccId = item ? item.id : null;
        var dlg = $('tp-td-dialog');
        if (!dlg) {
            return;
        }
        dlg.hidden = !item;
        renderDialog([]);
        var dTxt = $('tp-td-text');
        if (dTxt) {
            dTxt.value = '';
        }
        var dFile = $('tp-td-file');
        if (dFile) {
            dFile.value = '';
        }
        if (item) {
            postComment({ action: 'list' });
        }
    }

    function postComment(fields, file) {
        if (state.busy || !state.dialogOccId) {
            return;
        }
        state.busy = true;
        var occId = state.dialogOccId; // fixado antes do fetch

        var fd = new FormData();
        Object.keys(fields).forEach(function (key) {
            fd.append(key, fields[key]);
        });
        if (file) {
            fd.append('file', file);
        }
        fd.append('occurrences_id', String(occId));
        fd.append('_glpi_csrf_token', state.csrf);

        fetch(state.commentsUrl, {
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
                renderDialog((res && Array.isArray(res.comments)) ? res.comments : []);
                if (res && res.success) {
                    clearUnread(occId);
                }
            })
            .catch(function () {
                state.busy = false;
                toast('Falha de comunicação com o servidor', true);
            });
    }

    function clearUnread(occId) {
        var changed = false;
        state.data.cards.forEach(function (it) {
            if (it && Number(it.id) === Number(occId) && (Number(it.unread) || 0) > 0) {
                it.unread = 0;
                changed = true;
            }
        });
        if (changed) {
            renderBoard();
        }
    }

    function renderDialog(comments) {
        var list = $('tp-td-list');
        var empty = $('tp-td-empty');
        if (!list || !empty) {
            return;
        }
        list.textContent = '';
        empty.hidden = comments.length > 0;
        comments.forEach(function (c) {
            var li = el('li', 'taskplus-dialog__item');
            var head = el('div', 'taskplus-dialog__meta');
            head.appendChild(el('strong', '', c.author || '(usuário removido)'));
            head.appendChild(el('span', '', c.date || ''));
            if (c.own) {
                var del = el('button', 'taskplus-dialog__del', '\u00D7');
                del.type = 'button';
                del.title = 'Excluir comentário';
                del.addEventListener('click', function () {
                    if (window.confirm('Excluir este comentário?')) {
                        postComment({ action: 'delete', id: String(c.id) });
                    }
                });
                head.appendChild(del);
            }
            li.appendChild(head);
            li.appendChild(el('div', 'taskplus-dialog__text', c.content || ''));
            if (c.file_name) {
                var fl = el('a', 'taskplus-dialog__attach', '\uD83D\uDCCE ' + c.file_name);
                fl.href = state.attachUrl + '?comment=' + encodeURIComponent(String(c.id));
                fl.target = '_blank';
                fl.rel = 'noopener';
                li.appendChild(fl);
            }
            list.appendChild(li);
        });
        list.scrollTop = list.scrollHeight;
    }

    function sendComment() {
        var txt = $('tp-td-text');
        var fileInput = $('tp-td-file');
        if (!txt) {
            return;
        }
        var text = txt.value.trim();
        var file = (fileInput && fileInput.files && fileInput.files.length > 0)
            ? fileInput.files[0] : null;
        if (text === '' && !file) {
            toast('Escreva o comentário ou anexe um arquivo', true);
            txt.focus();
            return;
        }
        txt.value = '';
        postComment({ action: 'add', content: text }, file);
        if (fileInput) {
            fileInput.value = '';
        }
    }

    function saveModal() {
        var item = state.editingCard;
        if (!item && !state.creating) {
            return;
        }
        var name = $('tp-te-name').value.trim();
        if (name === '') {
            toast('Informe o título da tarefa', true);
            $('tp-te-name').focus();
            return;
        }
        var collab = selectedCollab();
        if (collab.length === 0) {
            toast('Escolha pelo menos um colaborador', true);
            return;
        }
        var fields = {
            action: state.creating ? 'add' : 'update',
            name: name,
            date: $('tp-te-date').value,
            time_limit: $('tp-te-time').value,
            category: $('tp-te-category').value.trim(),
            description: $('tp-te-description').value.trim(),
            collaborators: collab
        };
        if (!state.creating) {
            fields.id = String(item.id);
        }
        post(fields, closeModal);
    }

    function deleteTask() {
        var item = state.editingCard;
        if (!item) {
            return;
        }
        if (!window.confirm('Excluir esta tarefa de equipe para todos os colaboradores?')) {
            return;
        }
        post({ action: 'delete', id: String(item.id) }, closeModal);
    }

    // ------------------------------------------------------------------
    // Init
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-teamboard');
        if (!state.root) {
            return;
        }
        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';
        state.userId = Number(state.root.getAttribute('data-user-id')) || 0;
        state.commentsUrl = state.root.getAttribute('data-comments-url') || '';
        state.attachUrl = state.root.getAttribute('data-attachments-url') || '';

        var raw = null;
        var dataEl = $('taskplus-teamboard-data');
        if (dataEl) {
            try {
                raw = JSON.parse(dataEl.textContent);
            } catch (e) {
                raw = null;
            }
        }
        state.data = safeData(raw);

        $('tp-te-cancel').addEventListener('click', closeModal);
        $('tp-te-save').addEventListener('click', saveModal);
        $('tp-te-delete').addEventListener('click', deleteTask);
        bindCollab(); // 13b-2
        $('tp-te-modal').addEventListener('click', function (ev) {
            if (ev.target === $('tp-te-modal')) {
                closeModal();
            }
        });
        var tdSend = $('tp-td-send');
        if (tdSend) {
            tdSend.addEventListener('click', sendComment);
        }
        // 13c: modal de movimento
        $('tp-tm-cancel').addEventListener('click', closeMoveModal);
        $('tp-tm-save').addEventListener('click', saveMove);
        $('tp-tm-modal').addEventListener('click', function (ev) {
            if (ev.target === $('tp-tm-modal')) {
                closeMoveModal();
            }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') {
                return;
            }
            if (!$('tp-tm-modal').hidden) {
                closeMoveModal();
            } else if (!$('tp-te-modal').hidden) {
                closeModal();
            }
        });

        render();
    }

    // Exposto para teste (jsdom) e para depuração no console
    window.TaskplusTeamBoard = {
        init: init,
        render: render,
        safeData: safeData,
        openEditModal: openEditModal,
        openCreateModal: openCreateModal,
        addCollab: addCollab,
        removeCollab: removeCollab,
        selectedCollab: selectedCollab,
        saveModal: saveModal,
        deleteTask: deleteTask,
        allowedTargets: allowedTargets,
        dropOn: dropOn,
        saveMove: saveMove,
        renderDialog: renderDialog,
        sendComment: sendComment,
        matchesSearch: matchesSearch,
        state: state
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
