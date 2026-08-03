/**
 * Task+ — tela "Rotinas" (Etapa 2a).
 *
 * Mesmo contrato do public/js/taskplus.js (tela Hoje):
 *  - estado inicial embutido em <script type="application/json" id="taskplus-routines-data">
 *    (gerado pelo front/routines.php com JSON_HEX_* — seguro contra </script>);
 *  - toda ação vai por POST a ajax/routine.php com `_glpi_csrf_token`;
 *    a resposta traz `csrf` NOVO (rotação obrigatória) e `data` com o
 *    payload completo para re-render;
 *  - defesa de cliente: Array.isArray em toda lista vinda do servidor.
 *
 * Sem dependências. Todo texto de usuário entra no DOM via textContent
 * (nunca innerHTML) — sem XSS por nome/instruções.
 */
(function () {
    'use strict';

    var state = {
        root: null,
        ajaxUrl: '',
        csrf: '',
        showPaused: false,
        editingId: null,
        busy: false,
        data: { today: '', routines: [] }
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
        return {
            today: (typeof d.today === 'string') ? d.today : '',
            routines: Array.isArray(d.routines) ? d.routines : []
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

    function render() {
        var list = $('tpr-list');
        list.textContent = ''; // limpa

        var items = state.data.routines.filter(function (item) {
            return state.showPaused || !item.is_paused;
        });

        if (items.length === 0) {
            list.appendChild(emptyBox());
            return;
        }

        items.forEach(function (item) {
            list.appendChild(card(item));
        });
    }

    function emptyBox() {
        var hasAny = state.data.routines.length > 0;
        var box = el('div', 'taskplus-empty');
        box.appendChild(el('i', 'ti ti-repeat taskplus-empty__icon'));
        box.appendChild(el('h3', null, hasAny
            ? 'Nenhuma rotina pausada para mostrar'
            : 'Nenhuma rotina cadastrada'));
        box.appendChild(el('p', null, hasAny
            ? 'Ative "Mostrar pausadas" para revê-las, ou desative para ver as ativas.'
            : 'Clique em "Nova rotina" para cadastrar a primeira recorrência.'));
        return box;
    }

    function card(item) {
        var c = el('div', 'taskplus-card taskplus-card--routine'
            + (item.is_paused ? ' taskplus-card--paused' : ''));

        var body = el('div', 'taskplus-card__body');
        body.appendChild(el('div', 'taskplus-card__name', item.name || '(sem nome)'));
        if (item.instructions) {
            body.appendChild(el('div', 'taskplus-card__desc', item.instructions));
        }

        var badges = el('div', 'taskplus-card__badges');
        badges.appendChild(el('span', 'taskplus-badge taskplus-badge--category', item.frequency_label || ''));
        if (item.recurrence_label) {
            badges.appendChild(el('span', 'taskplus-badge', item.recurrence_label));
        }
        if (item.time_limit) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--limit', 'até ' + item.time_limit));
        }
        if (item.date_begin) {
            var period = 'a partir de ' + item.date_begin.split('-').reverse().join('/');
            if (item.date_end) {
                period += ' até ' + item.date_end.split('-').reverse().join('/');
            }
            badges.appendChild(el('span', 'taskplus-badge', period));
        }
        if (item.is_paused) {
            badges.appendChild(el('span', 'taskplus-badge taskplus-badge--late', 'pausada'));
        }
        if (badges.childNodes.length > 0) {
            body.appendChild(badges);
        }
        c.appendChild(body);

        var actions = el('div', 'taskplus-card__actions');

        var pauseBtn = el('button', 'taskplus-iconbtn');
        pauseBtn.type = 'button';
        pauseBtn.title = item.is_paused ? 'Retomar' : 'Pausar';
        pauseBtn.appendChild(el('i', 'ti ' + (item.is_paused ? 'ti-player-play' : 'ti-player-pause')));
        pauseBtn.addEventListener('click', function () {
            post({ action: 'pause', id: String(item.id), paused: item.is_paused ? '0' : '1' });
        });
        actions.appendChild(pauseBtn);

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
            if (window.confirm('Excluir a rotina "' + item.name + '"?')) {
                post({ action: 'delete', id: String(item.id) });
            }
        });
        actions.appendChild(del);

        c.appendChild(actions);

        return c;
    }

    // ------------------------------------------------------------------
    // Modal de nova/edição de rotina
    // ------------------------------------------------------------------

    function weekdayCheckboxes() {
        return $('tpr-f-weekdays').querySelectorAll('input[type=checkbox]');
    }

    function setFrequencyBlock(frequency) {
        $('tpr-block-daily').hidden = frequency !== 'daily';
        $('tpr-block-weekly').hidden = frequency !== 'weekly';
        $('tpr-block-monthly').hidden = frequency !== 'monthly';
    }

    function setMonthlyMode(mode) {
        $('tpr-monthly-day-row').hidden = mode !== 'day';
        $('tpr-monthly-pos-row').hidden = mode !== 'pos';
    }

    function currentMonthlyMode() {
        var checked = document.querySelector('input[name="tpr-monthly-mode"]:checked');
        return checked ? checked.value : 'day';
    }

    function openModal(item) {
        state.editingId = item ? item.id : null;
        $('tpr-modal-title').textContent = item ? 'Editar rotina' : 'Nova rotina';

        $('tpr-f-name').value = item ? item.name : '';
        $('tpr-f-instructions').value = item ? item.instructions : '';

        var frequency = item ? item.frequency : 'daily';
        $('tpr-f-frequency').value = frequency;
        setFrequencyBlock(frequency);

        $('tpr-f-only-workdays').checked = !!(item && item.only_workdays);

        var weekSet = (item && Array.isArray(item.weekdays)) ? item.weekdays : [];
        weekdayCheckboxes().forEach(function (cb) {
            cb.checked = weekSet.indexOf(Number(cb.value)) !== -1;
        });

        var mode = (item && item.monthweek) ? 'pos' : 'day';
        document.querySelector('input[name="tpr-monthly-mode"][value="' + mode + '"]').checked = true;
        setMonthlyMode(mode);
        $('tpr-f-monthday').value = (item && item.monthday) ? String(item.monthday) : '';
        $('tpr-f-monthweek').value = (item && item.monthweek) ? String(item.monthweek) : '1';
        $('tpr-f-monthweekday').value = (item && item.monthweekday) ? String(item.monthweekday) : '1';

        $('tpr-f-time').value = (item && item.time_limit) ? item.time_limit : '';
        $('tpr-f-begin').value = (item && item.date_begin) ? item.date_begin : (state.data.today || '');
        $('tpr-f-end').value = (item && item.date_end) ? item.date_end : '';

        $('tpr-modal').hidden = false;
        $('tpr-f-name').focus();
    }

    function closeModal() {
        $('tpr-modal').hidden = true;
        state.editingId = null;
    }

    function saveModal() {
        var name = $('tpr-f-name').value.trim();
        if (name === '') {
            toast('Informe o nome da rotina', true);
            $('tpr-f-name').focus();
            return;
        }

        var frequency = $('tpr-f-frequency').value;

        var fields = {
            action: state.editingId ? 'update' : 'add',
            name: name,
            instructions: $('tpr-f-instructions').value.trim(),
            frequency: frequency,
            only_workdays: $('tpr-f-only-workdays').checked ? '1' : '0',
            weekdays: '',
            monthday: '',
            monthweek: '',
            monthweekday: '',
            time_limit: $('tpr-f-time').value,
            date_begin: $('tpr-f-begin').value,
            date_end: $('tpr-f-end').value
        };

        if (frequency === 'weekly') {
            var days = [];
            weekdayCheckboxes().forEach(function (cb) {
                if (cb.checked) {
                    days.push(cb.value);
                }
            });
            if (days.length === 0) {
                toast('Selecione ao menos um dia da semana', true);
                return;
            }
            fields.weekdays = days.join(',');
        } else if (frequency === 'monthly') {
            if (currentMonthlyMode() === 'day') {
                var md = $('tpr-f-monthday').value.trim();
                if (md === '') {
                    toast('Informe o dia do mês', true);
                    return;
                }
                fields.monthday = md;
            } else {
                fields.monthweek = $('tpr-f-monthweek').value;
                fields.monthweekday = $('tpr-f-monthweekday').value;
            }
        }

        if (state.editingId) {
            fields.id = String(state.editingId);
        }
        post(fields, closeModal);
    }

    // ------------------------------------------------------------------
    // Inicialização
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-routines');
        if (!state.root) {
            return; // não está na tela Rotinas
        }
        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';

        var raw = null;
        var dataEl = $('taskplus-routines-data');
        if (dataEl) {
            try {
                raw = JSON.parse(dataEl.textContent);
            } catch (e) {
                raw = null; // JSON ruim → estrutura vazia, tela não quebra
            }
        }
        state.data = safeData(raw);

        $('tpr-btn-new').addEventListener('click', function () {
            openModal(null);
        });
        $('tpr-btn-cancel').addEventListener('click', closeModal);
        $('tpr-btn-save').addEventListener('click', saveModal);
        $('tpr-show-paused').addEventListener('change', function (ev) {
            state.showPaused = !!ev.target.checked;
            render();
        });
        $('tpr-f-frequency').addEventListener('change', function (ev) {
            setFrequencyBlock(ev.target.value);
        });
        document.querySelectorAll('input[name="tpr-monthly-mode"]').forEach(function (radio) {
            radio.addEventListener('change', function (ev) {
                setMonthlyMode(ev.target.value);
            });
        });
        $('tpr-modal').addEventListener('click', function (ev) {
            if (ev.target === $('tpr-modal')) {
                closeModal(); // clique no fundo fecha
            }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !$('tpr-modal').hidden) {
                closeModal();
            }
        });

        render();
    }

    // Exposto para teste (jsdom) e para depuração no console
    window.TaskplusRoutines = {
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
