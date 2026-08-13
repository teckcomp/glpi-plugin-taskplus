/**
 * Task+ — tela "Configurações", seção Fases do quadro (Etapa 4c,
 * revista na 4c-2: fases POR SETOR).
 *
 * O que a 4c-2 muda aqui:
 *  - payload ganha `managed_groups` (setores onde o usuário pode criar
 *    fase) e `is_admin`; cada fase ganha `groups_id`, `group_name` e
 *    `can_edit` (calculado no servidor — o JS só evita desenhar botão
 *    inútil, a validação de verdade é por ação no endpoint);
 *  - fase customizada leva a etiqueta do setor; as setas sobem/descem
 *    só DENTRO do setor; o modal de criação tem seletor de setor e o
 *    de edição mostra o setor fixo (imutável).
 *
 * Mesmo contrato do public/js/routines.js:
 *  - estado inicial embutido em <script type="application/json" id="taskplus-config-data">
 *    (gerado pelo front/config.form.php com JSON_HEX_* — seguro contra </script>);
 *  - toda ação vai por POST a ajax/phase.php com `_glpi_csrf_token`;
 *    a resposta traz `csrf` NOVO (rotação obrigatória) e `data` com o
 *    payload completo para re-render;
 *  - defesa de cliente: Array.isArray em toda lista vinda do servidor.
 *
 * Sem dependências. Todo texto de usuário entra no DOM via textContent
 * (nunca innerHTML) — sem XSS por nome de fase. A cor só entra em
 * style.backgroundColor depois de validada contra #rrggbb.
 */
(function () {
    'use strict';

    var state = {
        root: null,
        ajaxUrl: '',
        csrf: '',
        editingId: null,
        busy: false,
        data: { phases: [] }
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
            phases: Array.isArray(d.phases) ? d.phases : [],
            managed_groups: Array.isArray(d.managed_groups) ? d.managed_groups : [],
            is_admin: d.is_admin === true
        };
    }

    /**
     * Só cores #rrggbb validadas entram no style — qualquer outra coisa
     * cai no cinza padrão do plugin.
     */
    function safeColor(color) {
        return (typeof color === 'string' && /^#[0-9a-f]{6}$/i.test(color))
            ? color
            : '#5a6b7b';
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
        var list = $('tpc-phase-list');
        list.textContent = ''; // limpa

        // 4c-2: sem setor administrável não há onde criar fase (admin em
        // GLPI sem grupos cadastrados). O gestor sem grupo nem chega à
        // tela — o gate é do servidor.
        var btnNew = $('tpc-btn-new');
        if (btnNew) {
            var noSector = state.data.managed_groups.length === 0;
            btnNew.disabled = noSector;
            btnNew.title = noSector
                ? 'Cadastre um grupo (setor) no GLPI para criar fases'
                : '';
        }

        var phases = state.data.phases;
        if (phases.length === 0) {
            var box = el('div', 'taskplus-empty taskplus-empty--sm');
            box.appendChild(el('i', 'ti ti-columns taskplus-empty__icon'));
            box.appendChild(el('h3', null, 'Nenhuma fase cadastrada'));
            box.appendChild(el('p', null, 'As fases de sistema são criadas na instalação do plugin.'));
            list.appendChild(box);
            return;
        }

        phases.forEach(function (item) {
            list.appendChild(row(item, phases));
        });
    }

    function row(item, phases) {
        // 4c-2: as setas andam só DENTRO do setor da fase — a lista de
        // vizinhas para as pontas é a das customizadas do MESMO setor.
        var sectorIds = phases.filter(function (p) {
            return !p.is_system && p.groups_id === item.groups_id;
        }).map(function (p) { return p.id; });

        var r = el('div', 'taskplus-phase-row' + (item.is_system ? ' taskplus-phase-row--system' : ''));

        var dot = el('span', 'taskplus-phase-row__dot');
        dot.style.backgroundColor = safeColor(item.color);
        r.appendChild(dot);

        var body = el('div', 'taskplus-phase-row__body');
        body.appendChild(el('span', 'taskplus-phase-row__name', item.name || '(sem nome)'));
        if (item.is_system) {
            body.appendChild(el('span', 'taskplus-badge taskplus-badge--system', 'sistema'));
        } else {
            // 4c-2: toda customizada leva a etiqueta do setor dono.
            // group_name vazio = legada da 4c (sem setor), só o admin vê.
            body.appendChild(el(
                'span',
                'taskplus-badge taskplus-badge--sector',
                item.group_name || 'sem setor'
            ));
        }
        if (item.is_default) {
            var def = el('span', 'taskplus-badge taskplus-badge--default', 'padrão');
            def.title = 'Onde toda tarefa nasce no quadro';
            body.appendChild(def);
        }
        r.appendChild(body);

        var actions = el('div', 'taskplus-card__actions');

        // can_edit vem calculado do servidor (gestor não mexe em fase de
        // sistema nem em setor alheio). Sem botão aqui ≠ proteção: o
        // endpoint revalida por ação.
        if (item.can_edit && !item.is_system) {
            var idx = sectorIds.indexOf(item.id);

            var up = el('button', 'taskplus-iconbtn');
            up.type = 'button';
            up.title = 'Subir';
            up.disabled = (idx <= 0);
            up.appendChild(el('i', 'ti ti-arrow-up'));
            up.addEventListener('click', function () {
                post({ action: 'move', id: String(item.id), dir: 'up' });
            });
            actions.appendChild(up);

            var down = el('button', 'taskplus-iconbtn');
            down.type = 'button';
            down.title = 'Descer';
            down.disabled = (idx === sectorIds.length - 1);
            down.appendChild(el('i', 'ti ti-arrow-down'));
            down.addEventListener('click', function () {
                post({ action: 'move', id: String(item.id), dir: 'down' });
            });
            actions.appendChild(down);
        }

        if (item.can_edit) {
            var edit = el('button', 'taskplus-iconbtn');
            edit.type = 'button';
            edit.title = 'Editar';
            edit.appendChild(el('i', 'ti ti-pencil'));
            edit.addEventListener('click', function () {
                openModal(item);
            });
            actions.appendChild(edit);
        }

        if (item.can_edit && !item.is_system) {
            var del = el('button', 'taskplus-iconbtn taskplus-iconbtn--danger');
            del.type = 'button';
            del.title = 'Excluir';
            del.appendChild(el('i', 'ti ti-trash'));
            del.addEventListener('click', function () {
                if (window.confirm('Excluir a fase "' + item.name + '"? As tarefas dela voltam para a fase padrão.')) {
                    post({ action: 'delete', id: String(item.id) });
                }
            });
            actions.appendChild(del);
        }

        r.appendChild(actions);

        return r;
    }

    // ------------------------------------------------------------------
    // Modal de nova/edição de fase
    // ------------------------------------------------------------------

    function openModal(item) {
        state.editingId = item ? item.id : null;
        $('tpc-modal-title').textContent = item ? 'Editar fase' : 'Nova fase';
        $('tpc-modal-system-hint').hidden = !(item && item.is_system);

        // 4c-2: setor só na criação (imutável depois). Criando, o select
        // é populado com os setores administráveis do payload; editando
        // customizada, entra o aviso fixo com o setor da fase.
        var fieldGroup = $('tpc-field-group');
        var groupFixed = $('tpc-modal-group-fixed');
        var sel = $('tpc-f-group');

        if (!item) {
            sel.textContent = ''; // limpa as options
            state.data.managed_groups.forEach(function (g) {
                var opt = document.createElement('option');
                opt.value = String(g.id);
                opt.textContent = g.name;
                sel.appendChild(opt);
            });
            fieldGroup.hidden = false;
            groupFixed.hidden = true;
        } else {
            fieldGroup.hidden = true;
            if (!item.is_system) {
                groupFixed.textContent = 'Setor: ' + (item.group_name || 'sem setor')
                    + ' — não pode ser alterado depois da criação.';
                groupFixed.hidden = false;
            } else {
                groupFixed.hidden = true;
            }
        }

        $('tpc-f-name').value = item ? item.name : '';
        $('tpc-f-color').value = safeColor(item ? item.color : '#5a6b7b');

        $('tpc-modal').hidden = false;
        $('tpc-f-name').focus();
    }

    function closeModal() {
        $('tpc-modal').hidden = true;
        state.editingId = null;
    }

    function saveModal() {
        var name = $('tpc-f-name').value.trim();
        if (name === '') {
            toast('Informe o nome da fase', true);
            $('tpc-f-name').focus();
            return;
        }

        var fields = {
            action: state.editingId ? 'update' : 'add',
            name: name,
            color: $('tpc-f-color').value
        };
        if (state.editingId) {
            fields.id = String(state.editingId);
        } else {
            // 4c-2: criação exige o setor dono (o servidor valida escopo)
            var gid = $('tpc-f-group').value;
            if (!gid) {
                toast('Informe o setor da fase', true);
                return;
            }
            fields.groups_id = gid;
        }
        post(fields, closeModal);
    }

    // ------------------------------------------------------------------
    // Inicialização
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-config');
        if (!state.root) {
            return; // não está na tela Configurações
        }
        state.csrf = state.root.getAttribute('data-csrf') || '';
        state.ajaxUrl = state.root.getAttribute('data-ajax-url') || '';

        var raw = null;
        var dataEl = $('taskplus-config-data');
        if (dataEl) {
            try {
                raw = JSON.parse(dataEl.textContent);
            } catch (e) {
                raw = null; // JSON ruim → estrutura vazia, tela não quebra
            }
        }
        state.data = safeData(raw);

        $('tpc-btn-new').addEventListener('click', function () {
            openModal(null);
        });
        $('tpc-btn-cancel').addEventListener('click', closeModal);
        $('tpc-btn-save').addEventListener('click', saveModal);
        $('tpc-modal').addEventListener('click', function (ev) {
            if (ev.target === $('tpc-modal')) {
                closeModal(); // clique no fundo fecha
            }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !$('tpc-modal').hidden) {
                closeModal();
            }
        });

        render();
    }

    // Exposto para teste (jsdom) e para depuração no console
    window.TaskplusConfig = {
        init: init,
        render: render,
        safeData: safeData,
        safeColor: safeColor,
        state: state
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
