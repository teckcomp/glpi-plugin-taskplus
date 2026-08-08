/**
 * Task+ — tela "Equipe" (Etapa 5a: leitura para o gestor).
 *
 * Contrato com o servidor:
 *  - estado inicial embutido em <script type="application/json"
 *    id="taskplus-team-data"> (gerado pelo front/team.php com
 *    JSON_HEX_* — seguro contra </script>);
 *  - SEM AJAX nesta sub-etapa: a tela é leitura pura, o expandir/
 *    recolher é local. As ações do gestor chegam na 5a-2.
 *
 * Todo texto de usuário entra no DOM via textContent (nunca innerHTML).
 * Módulo expõe window.TaskplusTeam.init() — o harness jsdom roda com
 * runScripts 'outside-only' e o DOMContentLoaded nunca dispara lá (T14).
 */
(function () {
    'use strict';

    var state = {
        root: null,
        // Filtro de setor (pacote 3): 'all' ou o NOME do grupo — o
        // payload identifica setor por nome (os chips), não por id
        group: 'all',
        // Técnicos expandidos (por id): sobrevive ao re-render do filtro
        open: {},
        data: { date: '', groups: [], techs: [] }
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
        return {
            date: (typeof d.date === 'string') ? d.date : '',
            groups: Array.isArray(d.groups) ? d.groups : [],
            techs: Array.isArray(d.techs) ? d.techs.map(safeTech) : []
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
            name: (typeof i.name === 'string') ? i.name : '',
            status: (typeof i.status === 'string') ? i.status : 'today',
            detail: (typeof i.detail === 'string') ? i.detail : '',
            is_native: !!i.is_native,
            source: (typeof i.source === 'string') ? i.source : '',
            url: (typeof i.url === 'string') ? i.url : ''
        };
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

        var techs = visibleTechs();
        if (techs.length === 0) {
            box.appendChild(el('p', 'taskplus-empty', state.group === 'all'
                ? 'Nenhum técnico nos setores que você administra.'
                : 'Nenhum técnico neste setor.'));
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
        pills.appendChild(pill(tech.kpis.today, 'para hoje', STATUS_BADGE.today));
        pills.appendChild(pill(tech.kpis.pending, 'pendentes', STATUS_BADGE.pending));
        pills.appendChild(pill(tech.kpis.done, 'concluídas', STATUS_BADGE.done));
        head.appendChild(pills);

        // -------- corpo (lista do dia; a memória de expandidos faz o
        // técnico aberto continuar aberto quando o filtro re-renderiza) --------
        var isOpen = !!state.open[tech.id];
        var body = el('div', 'taskplus-team__body');
        body.hidden = !isOpen;
        head.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        chev.className = 'ti taskplus-team__chevron ti-chevron-'
            + (isOpen ? 'down' : 'right');

        if (tech.items.length === 0) {
            body.appendChild(el('p', 'taskplus-empty', 'Nada para hoje.'));
        } else {
            tech.items.forEach(function (item) {
                body.appendChild(renderItem(item));
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

    function renderItem(item) {
        var row = el('div', 'taskplus-team__item');

        row.appendChild(el('span',
            'taskplus-badge ' + (STATUS_BADGE[item.status] || STATUS_BADGE.today),
            STATUS_LABEL[item.status] || STATUS_LABEL.today));

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

        if (item.detail) {
            row.appendChild(el('span', 'taskplus-team__detail', item.detail));
        }

        return row;
    }

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------

    function init() {
        state.root = $('taskplus-team');
        if (!state.root) {
            return;
        }

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
        render();
    }

    window.TaskplusTeam = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
