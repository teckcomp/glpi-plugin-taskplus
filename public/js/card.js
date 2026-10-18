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
 *  - rodapé do diálogo (nº 76): as 3 últimas entradas + 💬/📎 no chip;
 *  - Equipe: avatar + setores sob o nome + barra segmentada no placar.
 *
 * Carregado em todas as páginas (hook ADD_JAVASCRIPT) e inerte fora do hub.
 */
(function () {
    'use strict';

    // 16q-7: paleta viva, sem o petróleo do fundo — selo tem que saltar do card
    var PAL = ['#E05A6B', '#7C5CFF', '#F28C28', '#2EA8E5', '#C33FA0', '#3DBF6E',
        '#E8B931', '#5B8DEF', '#F0623D', '#9A6BFF', '#1FB7B0', '#D9538E'];

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
    // 16q-8: cor de PESSOA sem colisão na página — registro nome→cor; o
    // primeiro a pedir pega a cor do hash e, se ela já for de outra pessoa,
    // a próxima livre. O Quadro de Equipe registra o setor inteiro antes
    // (ordem alfabética), então até 12 pessoas nunca repetem cor.
    var people = {}, taken = {};
    function personColor(name) {
        name = String(name || '');
        if (people[name]) {
            return people[name];
        }
        var start = hash(name) % PAL.length, i, idx = start;
        for (i = 0; i < PAL.length; i++) {
            idx = (start + i) % PAL.length;
            if (!taken[idx]) {
                break;
            }
        }
        if (i === PAL.length) {
            idx = start; // mais de 12 pessoas: repete a do hash
        }
        taken[idx] = true;
        people[name] = PAL[idx];
        return people[name];
    }
    function registerPeople(names) {
        names.slice().sort(function (a, b) { return String(a).localeCompare(String(b)); })
            .forEach(personColor);
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
        a.style.background = personColor(name);
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

        // Semana, "Depois desta semana" (16q-12): a data solta vira chip
        var bdate = c.querySelector('.taskplus-wbeyond__date');
        if (bdate && !c.querySelector('.taskplus-badge--limit, .taskplus-badge--late') && trimTitle(bdate.textContent)) {
            if (!badges) {
                badges = document.createElement('div');
                badges.className = 'taskplus-card__badges';
                c.insertBefore(badges, c.querySelector('.taskplus-bcard__deadline, .taskplus-card__recent, .taskplus-bcard__collab') || null);
            }
            var bl = document.createElement('span');
            bl.className = 'taskplus-badge taskplus-badge--limit';
            bl.textContent = trimTitle(bdate.textContent);
            badges.insertBefore(bl, badges.firstChild);
            bdate.hidden = true;
        }

        // Prazo: "faltam N dias" (rótulo da barra) junta-se ao chip da data
        var dl = c.querySelector('.taskplus-bcard__deadline-label');
        var limit = c.querySelector('.taskplus-badge--limit');
        if (dl && trimTitle(dl.textContent)) {
            if (limit && /\d{1,2}\/\d{1,2}|hoje/i.test(limit.textContent)) {
                var dlText = trimTitle(dl.textContent);
                limit.appendChild(document.createTextNode(' \u00b7 ' + dlText));
                dl.hidden = true;
                // a Hoje já põe o mesmo rótulo como chip solto (16): some o repetido
                c.querySelectorAll('.taskplus-card__badges > .taskplus-badge').forEach(function (b) {
                    if (b.className === 'taskplus-badge' && trimTitle(b.textContent) === dlText) {
                        b.parentNode.removeChild(b);
                    }
                });
            }
        }
        if (limit) {
            prependIcon(limit, 'ti-clock');
        }
        // Tarefa de hoje sem data: o rótulo do prazo ("até hoje" / "até 17:00")
        // vira o chip âmbar da linha de metadados
        if (dl && !dl.hidden && trimTitle(dl.textContent) && !limit) {
            var tl = document.createElement('span');
            tl.className = 'taskplus-badge taskplus-badge--pending taskplus-badge--today';
            tl.appendChild(icon('ti-clock'));
            tl.appendChild(document.createTextNode(trimTitle(dl.textContent)));
            dl.hidden = true;
            if (!badges) {
                badges = document.createElement('div');
                badges.className = 'taskplus-card__badges';
                var anchor = c.querySelector('.taskplus-bcard__deadline, .taskplus-card__recent, .taskplus-bcard__collab');
                var holder = c.querySelector('.taskplus-card__body') || c;
                if (anchor && anchor.parentNode === holder) {
                    holder.insertBefore(badges, anchor);
                } else {
                    holder.appendChild(badges);
                }
            }
            badges.insertBefore(tl, badges.firstChild);
        }
        // Hoje (seção Atrasadas): a data vermelha sem "há N" — calcula do dd/mm
        if (lateBadge && !/\u00b7/.test(lateBadge.textContent)) {
            var md = /(\d{1,2})\/(\d{1,2})/.exec(lateBadge.textContent);
            if (md) {
                var now = new Date(); now.setHours(0, 0, 0, 0);
                var when = new Date(now.getFullYear(), parseInt(md[2], 10) - 1, parseInt(md[1], 10));
                if (when > now) {
                    when.setFullYear(when.getFullYear() - 1);
                }
                var days = Math.round((now - when) / 86400000);
                if (days > 0) {
                    lateBadge.appendChild(document.createTextNode(' \u00b7 h\u00e1 ' + days + ' d'));
                }
            }
        }
        // "há 13 dias" → "há 13 d" (mockup)
        c.querySelectorAll('.taskplus-badge--late, .taskplus-badge--limit').forEach(function (b) {
            b.childNodes.forEach(function (n) {
                if (n.nodeType === 3 && /\d+ dias?\b/.test(n.nodeValue)) {
                    n.nodeValue = n.nodeValue.replace(/(\d+) dias?\b/g, '$1 d');
                }
            });
        });

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

        // Rodapé do diálogo (nº 76): as 3 últimas entradas ficam (o cliente
        // confirmou em 18/10); a existência do diálogo vira 💬 (quieto) e
        // 📎 no chip.
        var recent = c.querySelector('.taskplus-card__recent');
        if (recent) {
            var lines = recent.querySelectorAll('.taskplus-card__recent-line');
            var hasFile = false, i;
            for (i = 0; i < lines.length; i++) {
                if (/\uD83D\uDCCE|anexo/i.test(lines[i].textContent)) {
                    hasFile = true;
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

        // Quadro pessoal (16q-6): selo do dono — o usuário logado — à direita
        var shell = document.getElementById('taskplus-shell');
        var me = shell ? (shell.getAttribute('data-user-label') || '') : '';
        if (me && c.classList.contains('taskplus-bcard') && !c.classList.contains('taskplus-bcard--team')
            && !c.querySelector('.taskplus-bcard__collab')) {
            if (!badges) {
                badges = document.createElement('div');
                badges.className = 'taskplus-card__badges';
                c.insertBefore(badges, c.querySelector('.taskplus-bcard__deadline, .taskplus-card__recent') || null);
            }
            var stamp = document.createElement('span');
            stamp.className = 'taskplus-bcard__collab taskplus-bcard__collab--avatars taskplus-bcard__collab--me';
            var st = document.createElement('span');
            st.className = 'taskplus-avatars';
            st.appendChild(avatar(me, false));
            stamp.appendChild(st);
            badges.appendChild(stamp);
        }

        // Colaboradores: chips → avatares, no fim da linha de metadados
        var collab = c.querySelector('.taskplus-bcard__collab:not(.taskplus-bcard__collab--me)');
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

    // Coluna do quadro: "+ Adicionar tarefa" no rodapé — aciona o botão
    // "Nova tarefa" da tela (que o shell levou para a linha do título)
    function polishColumn(col) {
        if (col.getAttribute('data-tp-polished')) {
            return;
        }
        col.setAttribute('data-tp-polished', '1');
        var add = document.createElement('button');
        add.type = 'button';
        add.className = 'taskplus-bcol__add';
        add.appendChild(icon('ti-plus'));
        add.appendChild(document.createTextNode(' Adicionar tarefa'));
        add.addEventListener('click', function () {
            var btn = document.querySelector('.taskplus-toolbar2__new');
            if (btn) {
                btn.click();
            }
        });
        if (col.classList.contains('taskplus-bcol--done') || /conclu/i.test((col.querySelector('.taskplus-bcol__name') || {}).textContent || '')) {
            return;
        }
        col.appendChild(add);
    }

    // Quadro de Equipe: avatares dos membros do setor — 5 visíveis + "+N no
    // setor"; clicar expande todos, clicar de novo recolhe.
    var MEMBERS_SHOWN = 5;
    function polishMembers(box) {
        if (box.getAttribute('data-tp-polished')) {
            return;
        }
        box.setAttribute('data-tp-polished', '1');
        var names = [];
        try {
            names = JSON.parse(box.getAttribute('data-members') || '[]');
        } catch (e) {
            names = [];
        }
        if (!names.length) {
            return;
        }
        registerPeople(names);
        var stack = document.createElement('span');
        stack.className = 'taskplus-avatars taskplus-tb-members__stack';
        var more = document.createElement('button');
        more.type = 'button';
        more.className = 'taskplus-tb-members__more';
        var expanded = false;
        function draw() {
            stack.textContent = '';
            var n = expanded ? names.length : Math.min(MEMBERS_SHOWN, names.length);
            for (var i = 0; i < n; i++) {
                stack.appendChild(avatar(names[i], false));
            }
            var rest = names.length - MEMBERS_SHOWN;
            more.hidden = rest <= 0;
            more.textContent = expanded ? 'recolher' : ('+' + rest + ' no setor');
            more.title = expanded ? 'Mostrar só os primeiros' : names.slice(MEMBERS_SHOWN).join(', ');
            box.classList.toggle('taskplus-tb-members--open', expanded);
        }
        more.addEventListener('click', function () {
            expanded = !expanded;
            draw();
        });
        draw();
        box.appendChild(stack);
        box.appendChild(more);
    }

    // ------------------------------------------------------------------
    // Equipe (16q-9): linha do técnico vira card; barra única; legenda
    // ------------------------------------------------------------------
    function daysSince(ddmm) {
        var md = /(\d{1,2})\/(\d{1,2})/.exec(ddmm || '');
        if (!md) {
            return 0;
        }
        var now = new Date(); now.setHours(0, 0, 0, 0);
        var when = new Date(now.getFullYear(), parseInt(md[2], 10) - 1, parseInt(md[1], 10));
        if (when > now) {
            when.setFullYear(when.getFullYear() - 1);
        }
        return Math.round((now - when) / 86400000);
    }
    var ACT_ICON = {
        'taskplus-team__act--pend': ['ti-player-pause', null],
        'taskplus-team__act--edit': ['ti-pencil', 'Editar'],
        'taskplus-team__act--dialog': ['ti-message-circle', null]
    };
    function polishTeamItem(row) {
        if (row.getAttribute('data-tp-polished')) {
            return;
        }
        row.setAttribute('data-tp-polished', '1');
        row.classList.add('taskplus-team__item--card');
        var status = row.querySelector(':scope > .taskplus-badge');
        var name = row.querySelector(':scope > .taskplus-team__title, :scope > .taskplus-team__link');
        var detail = row.querySelector(':scope > .taskplus-team__detail');
        var acts = row.querySelector(':scope > .taskplus-team__acts');

        var main = document.createElement('div');
        main.className = 'taskplus-team__cardmain';
        var meta = document.createElement('div');
        meta.className = 'taskplus-card__badges';
        if (name) {
            main.appendChild(name);
        }
        main.appendChild(meta);

        // Estado → chip no padrão dos cards
        if (status) {
            var st = trimTitle(status.textContent).toLowerCase();
            var dm = detail ? /de (\d{1,2}\/\d{1,2})/.exec(detail.textContent) : null;
            if (status.classList.contains('taskplus-badge--late')) {
                var dd = dm ? dm[1] : '';
                status.textContent = dd ? dd + ' \u00b7 h\u00e1 ' + daysSince(dd) + ' d' : 'atrasada';
                prependIcon(status, 'ti-alert-triangle');
                if (dm && detail) {
                    detail.hidden = true;
                }
            } else if (st === 'para hoje' || st === 'aberta') {
                status.className = 'taskplus-badge taskplus-badge--pending taskplus-badge--today';
                status.textContent = st === 'aberta' ? 'aberta' : 'at\u00e9 hoje';
                prependIcon(status, 'ti-clock');
            } else if (status.classList.contains('taskplus-badge--pending')) {
                status.textContent = 'pendente';
                prependIcon(status, 'ti-player-pause');
            } else if (status.classList.contains('taskplus-badge--done')) {
                status.textContent = 'conclu\u00edda';
                prependIcon(status, 'ti-check');
            }
            meta.appendChild(status);
        }
        // Demais chips (ticket, validação, autoria) e o detalhe
        Array.prototype.slice.call(row.children).forEach(function (n) {
            if (n !== main && n !== acts && n.classList.contains('taskplus-badge')) {
                meta.appendChild(n);
            }
        });
        prependIcon(meta.querySelector('.taskplus-badge--validate'), 'ti-shield');
        prependIcon(meta.querySelector('.taskplus-badge--validated'), 'ti-shield-check');
        if (detail && !detail.hidden) {
            detail.classList.add('taskplus-badge');
            meta.appendChild(detail);
        }
        // 16q-9b: descrição sob o nome; rodapé do diálogo abaixo dos chips
        var desc = row.querySelector(':scope > .taskplus-team__desc');
        if (desc) {
            main.insertBefore(desc, meta);
        }
        var recent = row.querySelector(':scope > .taskplus-card__recent');
        if (recent) {
            main.appendChild(recent);
            var hasFile = /\uD83D\uDCCE|anexo/i.test(recent.textContent);
            var hasUnread = !!(acts && acts.querySelector('.taskplus-team__act--unread'));
            if (!hasUnread) {
                var tk = document.createElement('span');
                tk.className = 'taskplus-badge taskplus-badge--talk';
                tk.title = 'Diálogo da tarefa';
                tk.appendChild(icon('ti-message-circle'));
                meta.appendChild(tk);
            }
            if (hasFile) {
                var fl = document.createElement('span');
                fl.className = 'taskplus-badge taskplus-badge--file';
                fl.title = 'Com anexo';
                fl.appendChild(icon('ti-paperclip'));
                meta.appendChild(fl);
            }
        }
        // Barra de categoria no topo (separador) e de prazo na base
        var cat = meta.querySelector('.taskplus-badge--category');
        var lbl = document.createElement('span');
        lbl.className = 'taskplus-card__lbl';
        if (cat) {
            lbl.style.background = color(trimTitle(cat.textContent));
            lbl.title = trimTitle(cat.textContent);
            var dot = document.createElement('i');
            dot.className = 'taskplus-badge__dot';
            dot.style.background = lbl.style.background;
            cat.insertBefore(dot, cat.firstChild);
        } else {
            lbl.classList.add(row.querySelector('.taskplus-team__link') ? 'taskplus-card__lbl--native' : 'taskplus-card__lbl--none');
        }
        row.insertBefore(lbl, row.firstChild);
        var dl = row.querySelector(':scope > .taskplus-bcard__deadline');
        var dlLabel = dl ? trimTitle((dl.querySelector('.taskplus-bcard__deadline-label') || {}).textContent || '') : '';
        var todayChip = meta.querySelector('.taskplus-badge--today');
        if (todayChip && dlLabel && /^at\u00e9 /.test(dlLabel)) {
            todayChip.textContent = dlLabel;
            prependIcon(todayChip, 'ti-clock');
        }
        row.insertBefore(main, lbl.nextSibling);

        // Ações: Concluir → círculo; Pendência/Editar/Diálogo → ícones (no hover)
        if (acts) {
            acts.querySelectorAll('.taskplus-team__act').forEach(function (b) {
                var label = trimTitle(b.textContent);
                if (/^(Concluir|Desfazer)$/.test(label)) {
                    b.classList.add('taskplus-team__act--check');
                    if (label === 'Desfazer') {
                        b.classList.add('taskplus-team__act--checked');
                    }
                    b.title = label === 'Desfazer' ? 'Desfazer conclusão' : 'Concluir';
                    b.setAttribute('aria-label', b.title);
                    b.textContent = '';
                    b.appendChild(icon('ti-check'));
                    return;
                }
                Object.keys(ACT_ICON).forEach(function (cls) {
                    if (b.classList.contains(cls)) {
                        var n = /\((\d+\+?)\)/.exec(label);
                        b.title = label;
                        b.setAttribute('aria-label', label);
                        b.classList.add('taskplus-team__act--icon');
                        b.textContent = '';
                        b.appendChild(icon(cls === 'taskplus-team__act--pend' && /Liberar/.test(label) ? 'ti-player-play' : ACT_ICON[cls][0]));
                        if (n) {
                            b.appendChild(document.createTextNode(n[1]));
                        }
                    }
                });
            });
            // check por último, à direita
            var chk = acts.querySelector('.taskplus-team__act--check');
            if (chk) {
                acts.appendChild(chk);
            }
        }
    }

    function polishTeamPage() {
        var list = document.getElementById('tp-team');
        if (!list) {
            return;
        }
        var app = list.closest('.taskplus-main') || document;
        var bar = app.querySelector('.taskplus-toolbar2');
        // Setores na linha da busca (o team.js recria o filtro a cada render)
        var filters = app.querySelectorAll('.taskplus-team__filter');
        var fresh = null;
        filters.forEach(function (f) {
            if (!f.getAttribute('data-tp-moved')) {
                fresh = f;
            }
        });
        if (bar && fresh) {
            bar.querySelectorAll('.taskplus-team__filter').forEach(function (old) {
                old.parentNode.removeChild(old);
            });
            fresh.setAttribute('data-tp-moved', '1');
            bar.insertBefore(fresh, bar.firstChild);
        }
        // Legenda (uma vez)
        if (!app.querySelector('.taskplus-team__legend')) {
            var lg = document.createElement('div');
            lg.className = 'taskplus-team__legend';
            [['l', 'atrasadas'], ['h', 'para hoje'], ['p', 'pendentes'], ['c', 'conclu\u00eddas']].forEach(function (x) {
                var it = document.createElement('span');
                var sw = document.createElement('i');
                sw.className = 'taskplus-team__seg taskplus-team__seg--' + x[0];
                it.appendChild(sw);
                it.appendChild(document.createTextNode(x[1]));
                lg.appendChild(it);
            });
            var val = document.createElement('span');
            val.className = 'taskplus-team__legend-val';
            val.appendChild(icon('ti-shield'));
            val.appendChild(document.createTextNode(' aguardando valida\u00e7\u00e3o'));
            lg.appendChild(val);
            list.parentNode.insertBefore(lg, list);
        }
    }

    // Semana (16q-12): cabeçalho "12 Seg", contador só com tarefa, sem "—";
    // navegação agrupada (o shell a leva para a linha do título); o
    // intervalo da semana vira subtítulo.
    var DOW = { 'segunda': 'Seg', 'ter\u00e7a': 'Ter', 'quarta': 'Qua', 'quinta': 'Qui', 'sexta': 'Sex', 's\u00e1bado': 'S\u00e1b', 'domingo': 'Dom' };
    function polishWeek() {
        var prev = document.getElementById('tp-w-prev');
        if (prev && !document.querySelector('.taskplus-weeknav')) {
            var nav = document.createElement('span');
            nav.className = 'taskplus-weeknav';
            prev.parentNode.insertBefore(nav, prev);
            ['tp-w-prev', 'tp-w-label', 'tp-w-current', 'tp-w-next'].forEach(function (id) {
                var n = document.getElementById(id);
                if (n) {
                    nav.appendChild(n);
                }
            });
        }
        var label = document.getElementById('tp-w-label');
        if (label) {
            var m = /^(\d{1,2}\/\d{1,2}) a (\d{1,2}\/\d{1,2})( \(esta semana\))?$/.exec(trimTitle(label.textContent));
            if (m) {
                label.setAttribute('data-range', m[1] + ' \u2013 ' + m[2]);
                label.textContent = m[3] ? 'Esta semana' : m[1] + ' \u2013 ' + m[2];
                var sub = document.getElementById('taskplus-ctx-sub');
                if (sub) {
                    sub.textContent = m[1] + ' \u2013 ' + m[2];
                }
            }
        }
        document.querySelectorAll('.taskplus-wday__head:not([data-tp-polished])').forEach(function (h) {
            h.setAttribute('data-tp-polished', '1');
            var dt = h.querySelector('.taskplus-wday__date');
            var dw = h.querySelector('.taskplus-wday__dow');
            if (dt) {
                dt.textContent = trimTitle(dt.textContent).split('/')[0];
            }
            if (dw) {
                var k = trimTitle(dw.textContent).toLowerCase();
                dw.textContent = DOW[k] || trimTitle(dw.textContent).slice(0, 3);
            }
            var cnt = h.querySelector('.taskplus-section__count');
            if (cnt && trimTitle(cnt.textContent) === '0') {
                cnt.hidden = true;
            }
        });
        document.querySelectorAll('.taskplus-wday__empty').forEach(function (e) {
            if (trimTitle(e.textContent) === '\u2014') {
                e.hidden = true;
            }
        });
    }

    // Painel/Histórico (16q-13): período como subtítulo; seletor "ver
    // painel de" e o CSV acompanham a visibilidade do que os controla
    // (foram levados para a linha do título pelo shell); tabelas lado a lado.
    function polishPeriodScreens() {
        ['tp-p-note', 'tp-h-note'].forEach(function (id) {
            var note = document.getElementById(id);
            var sub = document.getElementById('taskplus-ctx-sub');
            if (!note || !sub) {
                return;
            }
            var m = /Per\u00edodo:\s*(\d{1,2}\/\d{1,2})\s*a\s*(\d{1,2}\/\d{1,2})\s*\((\d+) dias?\)/.exec(note.textContent || '');
            if (m) {
                var txt = m[1] + ' \u2013 ' + m[2] + ' \u00b7 ' + m[3] + ' dias';
                if (sub.textContent !== txt) {
                    sub.textContent = txt;
                }
                note.classList.add('taskplus-toolbar2__note--moved');
            }
        });
        [['tp-p-viewbox', 'tp-p-view'], ['tp-h-viewbox', 'tp-h-view']].forEach(function (pair) {
            var vb = document.getElementById(pair[0]);
            var sel = document.getElementById(pair[1]);
            if (vb && sel && sel.parentNode !== vb && sel.hidden !== vb.hidden) {
                sel.hidden = vb.hidden;
            }
        });
        var ob = document.getElementById('tp-p-owners-block');
        var ex = document.getElementById('tp-p-export');
        if (ob && ex && ex.hidden !== ob.hidden) {
            ex.hidden = ob.hidden;
        }
        var rb = document.getElementById('tp-p-routines-block');
        if (rb && ob && !rb.parentNode.classList.contains('taskplus-panel-grid')) {
            var grid = document.createElement('div');
            grid.className = 'taskplus-panel-grid';
            rb.parentNode.insertBefore(grid, rb);
            grid.appendChild(rb);
            grid.appendChild(ob);
        }
    }

    // Histórico (16q-15): trilha em linha única — ponto · hora · nome +
    // origem/categoria · à direita 💬/📎 e o estado. Descrição e motivo
    // viram tooltip; os botões (Diálogo, Restaurar) mantêm os listeners.
    var DOWL = { 'seg': 'Segunda', 'ter': 'Ter\u00e7a', 'qua': 'Quarta', 'qui': 'Quinta', 'sex': 'Sexta', 's\u00e1b': 'S\u00e1bado', 'sab': 'S\u00e1bado', 'dom': 'Domingo' };
    function polishHistoryPage() {
        var search = document.getElementById('tp-h-search');
        var bar = search ? search.parentNode : null;
        if (bar && bar.firstElementChild !== search) {
            bar.insertBefore(search, bar.firstElementChild);
        }
        document.querySelectorAll('.taskplus-hday__head:not([data-tp-polished])').forEach(function (h) {
            h.setAttribute('data-tp-polished', '1');
            var lb = h.querySelector('.taskplus-hday__label');
            var m = lb ? /^(\S+)\s*\u00b7\s*(\d{1,2}\/\d{1,2})( \(hoje\))?$/.exec(trimTitle(lb.textContent)) : null;
            if (m) {
                var full = DOWL[m[1].toLowerCase()] || m[1];
                lb.textContent = m[3] ? 'Hoje \u00b7 ' + full.toLowerCase() + ', ' + m[2] : full + ', ' + m[2];
            }
            var cnt = h.querySelector('.taskplus-section__count');
            if (cnt) {
                cnt.hidden = true;
            }
        });
    }
    function polishHistoryCard(c) {
        if (c.getAttribute('data-tp-polished')) {
            return;
        }
        c.setAttribute('data-tp-polished', '1');
        c.classList.add('taskplus-hcard--row');
        var name = c.querySelector(':scope > .taskplus-hcard__name');
        var badges = c.querySelector(':scope > .taskplus-card__badges');
        var desc = c.querySelector(':scope > .taskplus-hcard__desc');
        var time = document.createElement('span');
        time.className = 'taskplus-hcard__time';
        var main = document.createElement('div');
        main.className = 'taskplus-hcard__main';
        var right = document.createElement('div');
        right.className = 'taskplus-hcard__right';
        if (name) {
            main.appendChild(name);
            if (desc) {
                name.title = trimTitle(desc.textContent);
                desc.hidden = true;
            }
        }
        if (badges) {
            var kids = Array.prototype.slice.call(badges.children);
            var st = kids[0];
            kids.forEach(function (b, i) {
                var cls = b.className;
                if (i === 0) {
                    return;
                }
                // origem/categoria/edição ficam junto do nome; o resto vai à direita
                if (/--category/.test(cls) || /^rotina/.test(trimTitle(b.textContent)) || trimTitle(b.textContent) === 'editada') {
                    b.classList.add('taskplus-hcard__tag');
                    main.appendChild(b);
                } else if (cls === 'taskplus-badge' && st) {
                    // motivo (pulada/pendente) → tooltip do estado
                    st.title = (st.title ? st.title + ' \u00b7 ' : '') + trimTitle(b.textContent);
                    b.parentNode.removeChild(b);
                } else {
                    right.appendChild(b);
                }
            });
            if (st) {
                var tx = trimTitle(st.textContent);
                var hm = /\b(\d{1,2}:\d{2})\b/.exec(tx);
                if (hm) {
                    time.textContent = hm[1];
                }
                if (/conclu/i.test(tx)) {
                    st.textContent = 'conclu\u00edda';
                    st.className = 'taskplus-badge taskplus-badge--done';
                    prependIcon(st, 'ti-check');
                } else if (/atrasada/i.test(tx)) {
                    time.textContent = '\u2014';
                    st.textContent = 'ainda aberta';
                    prependIcon(st, 'ti-alert-triangle');
                } else if (/pulad/i.test(tx)) {
                    prependIcon(st, 'ti-player-skip-forward');
                } else if (/pend/i.test(tx)) {
                    prependIcon(st, 'ti-player-pause');
                } else if (/exclu/i.test(tx)) {
                    prependIcon(st, 'ti-trash');
                }
                right.appendChild(st);
            }
            badges.parentNode.removeChild(badges);
        }
        prependIcon(right.querySelector('.taskplus-badge--validate'), 'ti-shield');
        prependIcon(right.querySelector('.taskplus-badge--validated'), 'ti-check');
        // Diálogo: botão vira "💬 N 📎" (mesmo nó, mesmo listener)
        var db = c.querySelector('.taskplus-hcard__dialog');
        if (db) {
            var lab = trimTitle(db.textContent);
            var n = /\((\d+\+?)\)/.exec(lab);
            var hasFile = /\uD83D\uDCCE/.test(lab);
            db.title = lab;
            db.setAttribute('aria-label', lab);
            db.className = 'taskplus-hcard__dialog taskplus-hcard__iconbtn';
            db.textContent = '';
            db.appendChild(icon('ti-message-circle'));
            db.appendChild(document.createTextNode(n ? n[1] : ''));
            if (hasFile) {
                db.appendChild(icon('ti-paperclip'));
            }
            right.insertBefore(db, right.firstChild);
        }
        var rb = c.querySelector('.taskplus-hcard__restore');
        if (rb) {
            right.appendChild(rb);
        }
        c.querySelectorAll(':scope > .taskplus-hcard__actions').forEach(function (a) {
            if (!a.children.length) {
                a.parentNode.removeChild(a);
            }
        });
        c.insertBefore(right, c.firstChild);
        c.insertBefore(main, c.firstChild);
        c.insertBefore(time, c.firstChild);
    }

    function polishAll(root) {
        root = root || document;
        polishHistoryPage();
        root.querySelectorAll('.taskplus-hcard').forEach(polishHistoryCard);
        polishPeriodScreens();
        polishWeek();
        polishTeamPage();
        root.querySelectorAll('.taskplus-team__item').forEach(polishTeamItem);
        root.querySelectorAll('.taskplus-tb-members').forEach(polishMembers);
        root.querySelectorAll('.taskplus-card, .taskplus-bcard, .taskplus-wcard').forEach(polishCard);
        root.querySelectorAll('.taskplus-team__tech').forEach(polishTech);
        root.querySelectorAll('.taskplus-bcol').forEach(polishColumn);
    }

    function init() {
        // 16q-8: setor registrado antes de qualquer avatar (faixa inclusive)
        document.querySelectorAll('.taskplus-tb-members').forEach(function (box) {
            try { registerPeople(JSON.parse(box.getAttribute('data-members') || '[]')); } catch (e) { /* */ }
        });
        // Avatar do usuário na faixa (o partial só manda o rótulo)
        var meBox = document.getElementById('taskplus-top-me');
        var shellEl = document.getElementById('taskplus-shell');
        if (meBox && shellEl && shellEl.getAttribute('data-user-label')) {
            meBox.appendChild(avatar(shellEl.getAttribute('data-user-label'), false));
        }
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
        obs.observe(main.closest('.taskplus-app') || main, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['hidden'] });
    }

    window.TaskplusCard = { polishAll: polishAll, polishCard: polishCard, polishTech: polishTech, color: color, personColor: personColor, registerPeople: registerPeople, initials: initials };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
