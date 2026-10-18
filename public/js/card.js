/**
 * Task+ — 16q-2: o CARD compartilhado (redesign, decisão nº 80).
 *
 * Não substitui o card() de cada tela: POLE o DOM que elas montam. Um
 * MutationObserver em .taskplus-main vê cada card/linha entrar (as telas
 * re-renderizam do zero a cada `list`) e aplica a gramática visual do
 * mockup — uma vez por nó (data-tp-polished). Assim os 5 JS de tela e os
 * harnesses deles não mudam, e o visual fica num lugar só.
 *
 * O que o polidor faz, lendo só o que já está no DOM:
 *  - barra de categoria no topo do card (cor estável por nome);
 *  - "05/10" + faixa "Atrasada · há 13 dias" → um chip "⚠ 05/10 · há 13 dias";
 *  - "30/10" + "faltam 12 dias" (rótulo do prazo) → "🕓 30/10 · faltam 12 dias";
 *  - pendência: ícone de pausa; o MOTIVO sai do chip e vai para o tooltip;
 *  - 💬 não lido, validação, concluída: ícones;
 *  - chips de colaborador → avatares (iniciais, cor estável), à direita;
 *  - rodapé do diálogo (nº 76): só a última entrada + 💬/📎 no chip;
 *  - Equipe: avatar + setores sob o nome + barra segmentada no placar.
 *
 * Carregado em todas as páginas (hook ADD_JAVASCRIPT) e inerte fora do hub.
 */
(function () {
    'use strict';

    var PAL = ['#0B5F6B', '#6B4FBB', '#B35C1E', '#2F6DB5', '#8A2E6C', '#3A7D44',
        '#A33D3D', '#5B6B7A', '#1F7A8C', '#8C6D1F', '#4A5FC1', '#2E8B7A'];

    function hash(s) {
        var h = 0, i;
        s = String(s || '');
        for (i = 0; i < s.length; i++) {
            h = ((h << 5) - h + s.charCodeAt(i)) | 0;
        }
        return Math.abs(h);
    }
    function color(s) {
        return PAL[hash(s) % PAL.length];
    }
    function initials(name) {
        var parts = String(name || '').trim().split(/[\s.\-_]+/).filter(Boolean);
        if (parts.length === 0) {
            return '?';
        }
        var a = parts[0].charAt(0), b = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
        return (a + b).toUpperCase();
    }
    function icon(name) {
        var i = document.createElement('i');
        i.className = 'ti ' + name;
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function prependIcon(node, name) {
        if (node && !node.querySelector('i.ti')) {
            node.insertBefore(icon(name), node.firstChild);
        }
    }
    function avatar(name, me) {
        var a = document.createElement('span');
        a.className = 'taskplus-avatar' + (me ? ' taskplus-avatar--me' : '');
        a.style.background = color(name);
        a.title = name;
        a.textContent = initials(name);
        return a;
    }
    function trimTitle(s) {
        return String(s || '').replace(/\s+/g, ' ').trim();
    }

    // ------------------------------------------------------------------
    // Card (Hoje .taskplus-card · Quadros .taskplus-bcard · Semana .taskplus-wcard)
    // ------------------------------------------------------------------
    function polishCard(c) {
        if (c.getAttribute('data-tp-polished')) {
            return;
        }
        c.setAttribute('data-tp-polished', '1');

        var isNative = c.classList.contains('taskplus-card--native')
            || c.classList.contains('taskplus-bcard--native')
            || !!c.querySelector('.taskplus-badge--ticket, .taskplus-badge--project');

        // Barra de categoria no topo (a ÚNICA cor "livre" do sistema)
        var catBadge = c.querySelector('.taskplus-badge--category');
        var lbl = document.createElement('span');
        lbl.className = 'taskplus-card__lbl';
        if (catBadge) {
            lbl.style.background = color(trimTitle(catBadge.textContent));
            lbl.title = trimTitle(catBadge.textContent);
            var dot = document.createElement('i');
            dot.className = 'taskplus-badge__dot';
            dot.style.background = lbl.style.background;
            catBadge.insertBefore(dot, catBadge.firstChild);
        } else {
            lbl.classList.add(isNative ? 'taskplus-card__lbl--native' : 'taskplus-card__lbl--none');
        }
        c.insertBefore(lbl, c.firstChild);

        var badges = c.querySelector('.taskplus-card__badges');

        // Atraso: a faixa "Atrasada · há N" vira complemento do chip da data
        var strip = c.querySelector('.taskplus-bcard__late');
        var lateBadge = c.querySelector('.taskplus-badge--late');
        if (strip) {
            var txt = trimTitle(strip.textContent).replace(/^Atrasada\s*/i, '').replace(/^[\u00b7\-\s]+/, '');
            if (lateBadge) {
                lateBadge.appendChild(document.createTextNode(txt ? ' \u00b7 ' + txt : ''));
                strip.parentNode.removeChild(strip);
            } else {
                prependIcon(strip, 'ti-alert-triangle');
            }
        }
        if (lateBadge) {
            prependIcon(lateBadge, 'ti-alert-triangle');
        }

        // Prazo: "faltam N dias" (rótulo da barra) junta-se ao chip da data
        var dl = c.querySelector('.taskplus-bcard__deadline-label');
        var limit = c.querySelector('.taskplus-badge--limit');
        if (dl && trimTitle(dl.textContent)) {
            if (limit && /\d{1,2}\/\d{1,2}|hoje/i.test(limit.textContent)) {
                limit.appendChild(document.createTextNode(' \u00b7 ' + trimTitle(dl.textContent)));
                dl.hidden = true;
            }
        }
        if (limit) {
            prependIcon(limit, 'ti-clock');
        }

        // Pendência: ícone + motivo no tooltip (o chip solto confundia)
        var pend = c.querySelector('.taskplus-badge--pending');
        if (pend) {
            prependIcon(pend, 'ti-player-pause');
            var reason = pend.nextElementSibling;
            if (reason && reason.className === 'taskplus-badge') {
                pend.title = 'Motivo: ' + trimTitle(reason.textContent);
                reason.parentNode.removeChild(reason);
            }
        }

        // Não lido / validação / concluída: ícones
        var unread = c.querySelector('.taskplus-badge--unread');
        if (unread) {
            var n = trimTitle(unread.textContent).replace(/^\uD83D\uDCAC\s*/, '');
            unread.textContent = n;
            prependIcon(unread, 'ti-message-circle');
        }
        prependIcon(c.querySelector('.taskplus-badge--validate'), 'ti-shield');
        prependIcon(c.querySelector('.taskplus-badge--validated'), 'ti-shield-check');
        prependIcon(c.querySelector('.taskplus-badge--done'), 'ti-check');

        // Rodapé do diálogo (nº 76, ajuste 16q-2): só a última entrada;
        // a existência do diálogo vira 💬 (quieto) e 📎 no chip.
        var recent = c.querySelector('.taskplus-card__recent');
        if (recent) {
            var lines = recent.querySelectorAll('.taskplus-card__recent-line');
            var hasFile = false, i;
            for (i = 0; i < lines.length; i++) {
                if (/\uD83D\uDCCE|anexo/i.test(lines[i].textContent)) {
                    hasFile = true;
                }
                if (i < lines.length - 1) {
                    lines[i].hidden = true;
                }
            }
            if (badges) {
                if (!unread) {
                    var m = document.createElement('span');
                    m.className = 'taskplus-badge taskplus-badge--talk';
                    m.title = 'Diálogo da tarefa';
                    m.appendChild(icon('ti-message-circle'));
                    badges.appendChild(m);
                }
                if (hasFile) {
                    var f = document.createElement('span');
                    f.className = 'taskplus-badge taskplus-badge--file';
                    f.title = 'Com anexo';
                    f.appendChild(icon('ti-paperclip'));
                    badges.appendChild(f);
                }
            }
        }

        // Colaboradores: chips → avatares, no fim da linha de metadados
        var collab = c.querySelector('.taskplus-bcard__collab');
        if (collab) {
            var chips = collab.querySelectorAll('.taskplus-chip');
            var stack = document.createElement('span');
            stack.className = 'taskplus-avatars';
            var k;
            for (k = 0; k < chips.length; k++) {
                stack.appendChild(avatar(trimTitle(chips[k].textContent),
                    chips[k].classList.contains('taskplus-chip--me')));
            }
            collab.textContent = '';
            collab.classList.add('taskplus-bcard__collab--avatars');
            collab.appendChild(stack);
            if (badges && collab.parentNode !== badges) {
                badges.appendChild(collab);
            }
        }
    }

    // ------------------------------------------------------------------
    // Equipe: cabeçalho do técnico
    // ------------------------------------------------------------------
    function polishTech(wrap) {
        if (wrap.getAttribute('data-tp-polished')) {
            return;
        }
        wrap.setAttribute('data-tp-polished', '1');
        var head = wrap.querySelector('.taskplus-team__head');
        var name = wrap.querySelector('.taskplus-team__name');
        if (!head || !name) {
            return;
        }
        var label = trimTitle(name.textContent);
        var id = document.createElement('span');
        id.className = 'taskplus-team__id';
        id.appendChild(avatar(label, false));
        var text = document.createElement('span');
        text.className = 'taskplus-team__idtext';
        var sectors = [];
        wrap.querySelectorAll('.taskplus-team__head > .taskplus-badge--sector').forEach(function (s) {
            sectors.push(trimTitle(s.textContent));
            s.hidden = true;
        });
        text.appendChild(name);
        if (sectors.length) {
            var sec = document.createElement('span');
            sec.className = 'taskplus-team__sectors';
            sec.textContent = sectors.join(' \u00b7 ');
            text.appendChild(sec);
        }
        id.appendChild(text);
        var chev = head.querySelector('.taskplus-team__chevron');
        head.insertBefore(id, chev ? chev.nextSibling : head.firstChild);

        // Placar: pílulas "3 atrasadas" → barra segmentada + números
        var pills = head.querySelector('.taskplus-team__pills');
        if (pills) {
            var vals = [], order = ['late', 'limit', 'pending', 'done'];
            order.forEach(function (k) {
                var p = pills.querySelector('.taskplus-badge--' + k);
                var v = p ? parseInt(trimTitle(p.textContent), 10) : 0;
                vals.push(isNaN(v) ? 0 : v);
                if (p) {
                    p.classList.add('taskplus-team__pill--raw');
                }
            });
            var total = vals[0] + vals[1] + vals[2] + vals[3];
            var load = document.createElement('span');
            load.className = 'taskplus-team__load' + (total === 0 ? ' taskplus-team__load--zero' : '');
            var bar = document.createElement('span');
            bar.className = 'taskplus-team__bar';
            var cls = ['l', 'h', 'p', 'c'];
            vals.forEach(function (v, i) {
                var seg = document.createElement('i');
                seg.className = 'taskplus-team__seg taskplus-team__seg--' + cls[i];
                seg.style.width = (total ? (v / total * 100) : 0) + '%';
                bar.appendChild(seg);
            });
            var nums = document.createElement('span');
            nums.className = 'taskplus-team__nums';
            var titles = ['atrasadas', 'para hoje', 'pendentes', 'concluídas'];
            vals.forEach(function (v, i) {
                var b = document.createElement('b');
                b.className = 'taskplus-team__num taskplus-team__num--' + cls[i] + (v === 0 ? ' taskplus-team__num--zero' : '');
                b.textContent = String(v);
                b.title = v + ' ' + titles[i];
                nums.appendChild(b);
            });
            load.appendChild(bar);
            load.appendChild(nums);
            pills.insertBefore(load, pills.firstChild);
        }
        // Validação pendente visível no placar (prepara a nº 78)
        var waiting = wrap.querySelectorAll('.taskplus-team__item .taskplus-badge--validate').length;
        if (waiting > 0 && pills) {
            var w = document.createElement('span');
            w.className = 'taskplus-badge taskplus-badge--validate taskplus-team__waiting';
            w.title = waiting + ' concluída(s) aguardando sua validação';
            w.appendChild(icon('ti-shield'));
            w.appendChild(document.createTextNode(String(waiting)));
            pills.appendChild(w);
        }
    }

    function polishAll(root) {
        root = root || document;
        root.querySelectorAll('.taskplus-card, .taskplus-bcard, .taskplus-wcard').forEach(polishCard);
        root.querySelectorAll('.taskplus-team__tech').forEach(polishTech);
    }

    function init() {
        var main = document.querySelector('.taskplus-app .taskplus-main');
        if (!main || !window.MutationObserver) {
            return;
        }
        polishAll(main);
        var pending = false;
        var obs = new MutationObserver(function () {
            if (pending) {
                return;
            }
            pending = true;
            window.setTimeout(function () {
                pending = false;
                polishAll(main);
            }, 0);
        });
        obs.observe(main, { childList: true, subtree: true });
    }

    window.TaskplusCard = { polishAll: polishAll, polishCard: polishCard, polishTech: polishTech, color: color, initials: initials };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
