/**
 * Task+ — tela "Hoje" (Etapa 1; grupos na 2b; nativas na 3; dois blocos na 4a).
 *
 * Contrato com o servidor:
 *  - estado inicial embutido em <script type="application/json" id="taskplus-data">
 *    (gerado pelo front/today.php com JSON_HEX_* — seguro contra </script>);
 *  - toda ação vai por POST a ajax/occurrence.php com `_glpi_csrf_token`;
 *    a resposta traz `csrf` NOVO (rotação obrigatória — o token é de uso
 *    único) e `data` com o payload completo para re-render;
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
        csrf: '',
        showDone: false,
        source: 'all',
        editingId: null,
        busy: false,
        data: { date: '', kpis: { today: 0, done: 0, late: 0 }, today: [], overdue: [] }
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
        return {
            date: (typeof d.date === 'string') ? d.date : '',
            kpis: {
                late: Number(k.late) || 0,
                today: Number(k.today) || 0,
                // Chega com valor na Etapa 4b; payload antigo vira 0
                pending: Number(k.pending) || 0,
                done: Number(k.done) || 0
            },
            today: Array.isArray(d.today) ? d.today : [],
            overdue: Array.isArray(d.overdue) ? d.overdue : []
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
    // Render
    // ------------------------------------------------------------------

    /** Ordem e rótulo dos grupos da seção "Hoje" (Etapa 2b; Chamados na 3a). */
    var GROUPS = [
        ['daily', 'Rotinas diárias'],
        ['weekly', 'Rotinas semanais'],
        ['monthly', 'Rotinas mensais'],
        ['avulsa', 'Avulsas'],
        ['ticket', 'Chamados'],
        ['project', 'Projetos']
    ];

    /** Grupos que pertencem a cada bloco da tela (Etapa 4a). */
    var OWN_GROUPS = ['daily', 'weekly', 'monthly', 'avulsa'];
    var NATIVE_GROUPS = ['ticket', 'project'];

    /**
     * Filtro por origem (Etapa 3b, virou botões na 4a): vale APENAS para
     * o bloco "Do GLPI" — rotinas e avulsas moram no outro bloco e não
     * são afetadas.
     */
    var SOURCE_FILTERS = {
        ticket: ['ticket'],
        project: ['project']
    };

    /** O item nativo passa pelo filtro de origem ativo? */
    function matchesSource(item) {
        var allowed = SOURCE_FILTERS[state.source];
        if (!allowed) {
            return true; // 'all' ou valor desconhecido: não filtra
        }
        return allowed.indexOf(item.group || '') !== -1;
    }

    function groupOf(item) {
        return item.group || 'avulsa';
    }

    function render() {
        var kpis = state.data.kpis;
        $('tp-kpi-late').textContent = String(kpis.late);
        $('tp-kpi-today').textContent = String(kpis.today);
        // "Pendentes" só ganha valor real na Etapa 4b
        $('tp-kpi-pending').textContent = String(kpis.pending || 0);
        $('tp-kpi-done').textContent = String(kpis.done);

        renderOwn();
        renderNative();
    }

    /** Bloco 1 — tarefas do próprio Task+ (atrasadas, rotinas, avulsas). */
    function renderOwn() {
        var list = $('tp-list-own');
        list.textContent = '';

        var todayItems = state.data.today.filter(function (item) {
            return OWN_GROUPS.indexOf(groupOf(item)) !== -1
                && (state.showDone || !item.is_done);
        });
        var overdueItems = state.data.overdue.filter(function (item) {
            return OWN_GROUPS.indexOf(groupOf(item)) !== -1;
        });

        if (overdueItems.length === 0 && todayItems.length === 0) {
            list.appendChild(emptyOwn());
            return;
        }

        if (overdueItems.length > 0) {
            list.appendChild(section('Atrasadas', overdueItems, true));
        }

        if (todayItems.length > 0) {
            // Só com avulsas mantém a seção única "Hoje" (Etapa 1);
            // basta uma ocorrência de rotina para valer o agrupamento.
            var hasRoutine = todayItems.some(function (item) {
                return groupOf(item) !== 'avulsa';
            });

            if (!hasRoutine) {
                list.appendChild(section('Hoje', todayItems, false));
            } else {
                GROUPS.forEach(function (group) {
                    if (OWN_GROUPS.indexOf(group[0]) === -1) {
                        return;
                    }
                    var items = todayItems.filter(function (item) {
                        return groupOf(item) === group[0];
                    });
                    if (items.length > 0) {
                        list.appendChild(section(group[1], items, false));
                    }
                });
            }
        }
    }

    /** Bloco 2 — origens nativas do GLPI (chamados e projetos). */
    function renderNative() {
        var list = $('tp-list-native');
        list.textContent = '';

        var items = state.data.today.filter(function (item) {
            return NATIVE_GROUPS.indexOf(groupOf(item)) !== -1 && matchesSource(item);
        });

        if (items.length === 0) {
            list.appendChild(emptyNative());
            return;
        }

        GROUPS.forEach(function (group) {
            if (NATIVE_GROUPS.indexOf(group[0]) === -1) {
                return;
            }
            var ofGroup = items.filter(function (item) {
                return groupOf(item) === group[0];
            });
            if (ofGroup.length > 0) {
                list.appendChild(section(group[1], ofGroup, false));
            }
        });
    }

    /** Estado vazio do bloco 1 (tarefas próprias). */
    function emptyOwn() {
        var own = state.data.today.filter(function (item) {
            return OWN_GROUPS.indexOf(groupOf(item)) !== -1;
        });
        var allDone = own.length > 0
            && own.every(function (item) { return item.is_done; })
            && state.data.overdue.length === 0;

        var box = el('div', 'taskplus-empty');
        box.appendChild(el('i', 'ti ' + (allDone ? 'ti-confetti' : 'ti-checklist') + ' taskplus-empty__icon'));
        box.appendChild(el('h3', null, allDone
            ? 'Tudo concluído por hoje!'
            : 'Nenhuma tarefa para hoje'));
        box.appendChild(el('p', null, allDone
            ? 'Ative "Mostrar concluídas" para rever o que foi feito.'
            : 'Clique em "Nova tarefa" para lançar a primeira avulsa do dia.'));
        return box;
    }

    /** Estado vazio do bloco 2 (origens do GLPI). */
    function emptyNative() {
        // Filtro ativo escondendo tudo é diferente de "não há nada":
        // dizer "nenhuma tarefa" aqui confundiria.
        var filtering = state.source !== 'all';
        var box = el('div', 'taskplus-empty taskplus-empty--sm');
        box.appendChild(el('i', 'ti ' + (filtering ? 'ti-filter-off' : 'ti-inbox') + ' taskplus-empty__icon'));
        box.appendChild(el('h3', null, filtering
            ? 'Nada nesta origem'
            : 'Nada vindo do GLPI'));
        box.appendChild(el('p', null, filtering
            ? 'Volte para "Todas" para ver as demais.'
            : 'Tarefas de chamado e de projeto atribuídas a você aparecem aqui.'));
        return box;
    }

    function section(title, items, isOverdue) {
        var sec = el('div', 'taskplus-section');
        var head = el('div', 'taskplus-section__title' + (isOverdue ? ' taskplus-section__title--late' : ''));
        head.appendChild(el('span', null, title));
        head.appendChild(el('span', 'taskplus-section__count', String(items.length)));
        sec.appendChild(head);
        items.forEach(function (item) {
            sec.appendChild(card(item, isOverdue));
        });
        return sec;
    }

    function card(item, isOverdue) {
        var isNative = !!item.is_native;

        var c = el('div', 'taskplus-card'
            + (isNative ? ' taskplus-card--native' : '')
            + (item.is_done ? ' taskplus-card--done' : '')
            + (item.is_late ? ' taskplus-card--late' : ''));

        if (isNative) {
            // Origem nativa é LEITURA (Etapa 3): não existe check, porque
            // concluir gravaria em tabela do GLPI — decisão adiada. No
            // lugar dele vai o ícone da origem, sem ação.
            var isProject = item.source === 'project';
            var mark = el('span', 'taskplus-check taskplus-check--native');
            mark.title = isProject
                ? 'Tarefa de projeto — concluir pela tarefa do projeto'
                : 'Tarefa de chamado — concluir pelo chamado';
            mark.setAttribute('aria-label', mark.title);
            mark.appendChild(el('i', 'ti ' + (isProject ? 'ti-subtask' : 'ti-headset')));
            c.appendChild(mark);
        } else {
            // Check de conclusão em 1 clique
            var check = el('button', 'taskplus-check' + (item.is_done ? ' taskplus-check--on' : ''));
            check.type = 'button';
            check.title = item.is_done ? 'Desfazer conclusão' : 'Concluir';
            check.setAttribute('aria-label', check.title);
            check.appendChild(el('i', 'ti ' + (item.is_done ? 'ti-check' : '')));
            check.addEventListener('click', function () {
                post({ action: 'toggle', id: String(item.id), done: item.is_done ? '0' : '1' });
            });
            c.appendChild(check);
        }

        // Corpo: nome, descrição, badges
        var body = el('div', 'taskplus-card__body');
        body.appendChild(el('div', 'taskplus-card__name', item.name || '(sem título)'));
        if (item.description) {
            body.appendChild(el('div', 'taskplus-card__desc', item.description));
        }

        var badges = el('div', 'taskplus-card__badges');
        if (isOverdue && item.date_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--late', item.date_label));
        }
        // Na seção "Atrasadas" as origens se misturam (o agrupamento por
        // rotina só existe na seção do dia) — marca de onde a tarefa veio.
        if (isOverdue && item.is_routine) {
            badges.appendChild(el('span', 'taskplus-badge', 'rotina'));
        }
        if (item.time_limit) {
            badges.appendChild(el('span',
                'taskplus-badge' + (item.is_late ? ' taskplus-badge--late' : ' taskplus-badge--limit'),
                'até ' + item.time_limit));
        }
        if (item.is_late && !item.time_limit && !isOverdue) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--late', 'atrasada'));
        }
        if (isNative && item.ticket_label) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--ticket',
                'Chamado ' + item.ticket_label));
        }
        if (isNative && item.project_name) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--project',
                'Projeto: ' + item.project_name));
        }
        if (isNative && item.percent_label) {
            badges.appendChild(el('span', 'taskplus-badge', item.percent_label));
        }
        if (isNative && item.planned_label) {
            badges.appendChild(el('span', 'taskplus-badge', item.planned_label));
        }
        if (item.category) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--category', item.category));
        }
        if (item.is_done && item.done_time) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--done', 'concluída às ' + item.done_time));
        }
        if (badges.childNodes.length > 0) {
            body.appendChild(badges);
        }
        c.appendChild(body);

        // Nativa: única ação é abrir o item no GLPI (leitura + link).
        if (isNative) {
            if (item.url) {
                var open = el('a', 'taskplus-iconbtn');
                open.href = item.url;
                open.title = (item.source === 'project')
                    ? 'Abrir a tarefa do projeto'
                    : 'Abrir o chamado';
                open.setAttribute('aria-label', open.title);
                open.appendChild(el('i', 'ti ti-external-link'));

                var nativeActions = el('div', 'taskplus-card__actions');
                nativeActions.appendChild(open);
                c.appendChild(nativeActions);
            }
            return c;
        }

        // Ações: editar/excluir só para avulsas (ocorrência de rotina não)
        if (!item.is_routine) {
            var actions = el('div', 'taskplus-card__actions');

            var edit = el('button', 'taskplus-iconbtn');
            edit.type = 'button';
            edit.title = 'Editar';
            edit.appendChild(el('i', 'ti ti-pencil'));
            edit.addEventListener('click', function () {
                openModal(item);
            });
            actions.appendChild(edit);

            var del = el('button', 'taskplus-iconbtn taskplus-iconbtn--danger');
            del.type = 'button';
            del.title = 'Excluir';
            del.appendChild(el('i', 'ti ti-trash'));
            del.addEventListener('click', function () {
                if (window.confirm('Excluir a tarefa "' + item.name + '"?')) {
                    post({ action: 'delete', id: String(item.id) });
                }
            });
            actions.appendChild(del);

            c.appendChild(actions);
        }

        return c;
    }

    // ------------------------------------------------------------------
    // Modal de nova/edição de avulsa
    // ------------------------------------------------------------------

    function openModal(item) {
        state.editingId = item ? item.id : null;
        $('tp-modal-title').textContent = item ? 'Editar tarefa' : 'Nova tarefa avulsa';
        $('tp-f-name').value = item ? item.name : '';
        $('tp-f-date').value = item ? item.date : (state.data.date || '');
        $('tp-f-time').value = (item && item.time_limit) ? item.time_limit : '';
        $('tp-f-category').value = item ? item.category : '';
        $('tp-f-description').value = item ? item.description : '';
        $('tp-modal').hidden = false;
        $('tp-f-name').focus();
    }

    function closeModal() {
        $('tp-modal').hidden = true;
        state.editingId = null;
    }

    function saveModal() {
        var name = $('tp-f-name').value.trim();
        if (name === '') {
            toast('Informe o título da tarefa', true);
            $('tp-f-name').focus();
            return;
        }
        var fields = {
            action: state.editingId ? 'update' : 'add',
            name: name,
            date: $('tp-f-date').value,
            time_limit: $('tp-f-time').value,
            category: $('tp-f-category').value.trim(),
            description: $('tp-f-description').value.trim()
        };
        if (state.editingId) {
            fields.id = String(state.editingId);
        }
        post(fields, closeModal);
    }

    // ------------------------------------------------------------------
    // Inicialização
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-today');
        if (!state.root) {
            return; // não está na tela Hoje
        }
        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';

        var raw = null;
        var dataEl = $('taskplus-data');
        if (dataEl) {
            try {
                raw = JSON.parse(dataEl.textContent);
            } catch (e) {
                raw = null; // JSON ruim → estrutura vazia, tela não quebra
            }
        }
        state.data = safeData(raw);

        $('tp-btn-new').addEventListener('click', function () {
            openModal(null);
        });
        $('tp-btn-cancel').addEventListener('click', closeModal);
        $('tp-btn-save').addEventListener('click', saveModal);
        $('tp-show-done').addEventListener('change', function (ev) {
            state.showDone = !!ev.target.checked;
            render();
        });
        var filter = $('tp-filter-source');
        if (filter) { // template antigo sem o filtro não quebra
            filter.addEventListener('click', function (ev) {
                var btn = ev.target.closest('.taskplus-segmented__btn');
                if (!btn) {
                    return;
                }
                state.source = btn.getAttribute('data-source') || 'all';
                filter.querySelectorAll('.taskplus-segmented__btn').forEach(function (b) {
                    b.classList.toggle('taskplus-segmented__btn--on', b === btn);
                });
                renderNative(); // o filtro não toca no bloco de tarefas próprias
            });
        }
        $('tp-modal').addEventListener('click', function (ev) {
            if (ev.target === $('tp-modal')) {
                closeModal(); // clique no fundo fecha
            }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !$('tp-modal').hidden) {
                closeModal();
            }
        });

        render();
    }

    // Exposto para teste (jsdom) e para depuração no console
    window.TaskplusToday = {
        init: init,
        render: render,
        safeData: safeData,
        state: state
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
