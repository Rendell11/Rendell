/**
 * cert_editor.js — drag-and-drop layout editor (Template Builder step 5 and
 * the per-document "Edit" in the preview modal).
 *
 * Live preview on the left (template image at the chosen paper size), a clean
 * side panel on the right (grouped field list with search). Click a field to
 * put it on the page, drag it onto the correct line, fine-tune it with the
 * small property bar. Positions are % of the page (saved as-is).
 *
 * CertEditor.mount(host, {
 *   paper, bg_image, bg_opacity,
 *   positions: [...],                      // current layout
 *   groups: [{ title, items: [{ key, label, sample }] }],
 *   values: { key: text },                 // what to show on the page (sample or real values)
 *   onSave(positions) → Promise,           // required
 *   onAiDetect(currentKeys) → Promise<positions>   // optional: shows the "AI Auto Detect" button
 *   saveLabel, note
 * })
 * Requires cert_render.js.
 */
(function (global) {
    'use strict';

    function esc(v) { const x = document.createElement('div'); x.textContent = v == null ? '' : String(v); return x.innerHTML; }
    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function round2(v) { return Math.round(v * 100) / 100; }

    function mount(host, cfg) {
        const state = {
            positions: (cfg.positions || []).map(function (p) { return Object.assign({}, p); }),
            selected: null,
            dirty: false,
        };
        const labelOf = {};
        (cfg.groups || []).forEach(function (g) { g.items.forEach(function (i) { labelOf[i.key] = i.label; }); });
        function valueOf(key) {
            const v = cfg.values && cfg.values[key];
            if (v != null && v !== '') return v;
            return '[' + (labelOf[key] || key) + ']';
        }

        host.innerHTML =
            '<div class="ce-root">' +
            '  <div class="ce-stage">' +
            '    <div class="ce-toolbar">' +
            '      <div class="ce-props" data-ref="props"><span class="ce-hint">Click a field on the page or in the list to edit it.</span></div>' +
            '    </div>' +
            '    <div class="ce-canvas" data-ref="canvas"></div>' +
            '  </div>' +
            '  <aside class="ce-side">' +
            '    <div class="ce-side-head">' +
            '      <p class="ce-title">Fields</p>' +
            '      <div class="ce-search"><span class="material-symbols-outlined">search</span><input type="search" data-ref="search" placeholder="Search fields"></div>' +
            '    </div>' +
            '    <div class="ce-list" data-ref="list"></div>' +
            '    <div class="ce-side-foot">' +
            (cfg.note ? '<p class="ce-note">' + esc(cfg.note) + '</p>' : '') +
            '      <p class="ce-status" data-ref="status"></p>' +
            (cfg.onAiDetect ? '<button type="button" class="ce-btn ce-btn-ai" data-ref="ai"><span class="material-symbols-outlined">auto_awesome</span>AI Auto Detect</button>' : '') +
            '      <button type="button" class="ce-btn ce-btn-primary" data-ref="save"><span class="material-symbols-outlined">save</span>' + esc(cfg.saveLabel || 'Save Layout') + '</button>' +
            '    </div>' +
            '  </aside>' +
            '</div>';
        const $ = function (r) { return host.querySelector('[data-ref="' + r + '"]'); };
        const canvas = $('canvas'), list = $('list'), props = $('props'), status = $('status');

        let pageEl = null;

        function model() {
            return {
                paper: cfg.paper, bg_image: cfg.bg_image, bg_opacity: cfg.bg_opacity,
                fields: state.positions.map(function (p) { return Object.assign({}, p, { value: valueOf(p.field_key) }); }),
            };
        }
        function scale() { return pageEl ? parseFloat(pageEl.dataset.scale || '1') : 1; }

        function draw() {
            pageEl = CertRender.into(canvas, model(), { width: Math.max(280, canvas.clientWidth - 24), noResize: true });
            pageEl.querySelectorAll('.cert-field').forEach(function (el) {
                el.classList.add('ce-field');
                if (el.dataset.key === state.selected) el.classList.add('ce-selected');
                el.addEventListener('pointerdown', startDrag);
            });
            pageEl.addEventListener('pointerdown', function (e) { if (e.target === pageEl || e.target.tagName === 'IMG') select(null); });
        }

        function find(key) { return state.positions.find(function (p) { return p.field_key === key; }); }

        function drawList() {
            const q = ($('search').value || '').trim().toLowerCase();
            let html = '';
            (cfg.groups || []).forEach(function (g) {
                const items = g.items.filter(function (i) { return !q || i.label.toLowerCase().indexOf(q) >= 0 || i.key.indexOf(q) >= 0; });
                if (!items.length) return;
                html += '<p class="ce-group">' + esc(g.title) + '</p>';
                items.forEach(function (i) {
                    const on = !!find(i.key);
                    html += '<button type="button" class="ce-item' + (on ? ' on' : '') + (state.selected === i.key ? ' sel' : '') + '" data-key="' + esc(i.key) + '">' +
                        '<span class="material-symbols-outlined">' + (on ? 'check_box' : 'check_box_outline_blank') + '</span>' +
                        '<span class="ce-item-label">' + esc(i.label) + '</span></button>';
                });
            });
            list.innerHTML = html || '<p class="ce-empty">No field matches your search.</p>';
            list.querySelectorAll('.ce-item').forEach(function (b) {
                b.addEventListener('click', function () {
                    const key = b.dataset.key;
                    if (!find(key)) addField(key); else select(key);
                });
            });
        }

        function addField(key) {
            // New fields start near the middle, cascading so they don't stack.
            const n = state.positions.length;
            state.positions.push({
                field_key: key, field_label: labelOf[key] || key,
                pos_x: 50, pos_y: clamp(30 + (n % 10) * 5, 5, 95), width: null,
                font_size: 16, font_weight: 'normal', text_align: 'center', text_color: '#000000', uppercase: 0,
            });
            state.dirty = true;
            select(key);
        }

        function select(key) {
            state.selected = key;
            draw(); drawList(); drawProps();
        }

        function drawProps() {
            const p = state.selected && find(state.selected);
            if (!p) { props.innerHTML = '<span class="ce-hint">Click a field on the page or in the list to edit it. Drag it onto the correct line.</span>'; return; }
            props.innerHTML =
                '<span class="ce-chip">' + esc(labelOf[p.field_key] || p.field_label || p.field_key) + '</span>' +
                '<label class="ce-prop" title="Font size (px)"><span class="material-symbols-outlined">format_size</span><input type="number" min="6" max="96" data-p="font_size" value="' + p.font_size + '"></label>' +
                '<button type="button" class="ce-tog' + (p.font_weight === 'bold' ? ' on' : '') + '" data-t="bold" title="Bold"><span class="material-symbols-outlined">format_bold</span></button>' +
                '<button type="button" class="ce-tog' + (Number(p.uppercase) ? ' on' : '') + '" data-t="upper" title="UPPERCASE"><span class="material-symbols-outlined">match_case</span></button>' +
                ['left', 'center', 'right'].map(function (a) {
                    return '<button type="button" class="ce-tog' + (p.text_align === a ? ' on' : '') + '" data-a="' + a + '" title="Align ' + a + '"><span class="material-symbols-outlined">format_align_' + a + '</span></button>';
                }).join('') +
                '<label class="ce-prop" title="Width (% of page, empty = auto)"><span class="material-symbols-outlined">width</span><input type="number" min="2" max="100" step="1" placeholder="auto" data-p="width" value="' + (p.width || '') + '"></label>' +
                '<label class="ce-prop" title="Text color"><input type="color" data-p="text_color" value="' + esc(p.text_color || '#000000') + '"></label>' +
                '<button type="button" class="ce-tog ce-del" data-t="remove" title="Remove from page"><span class="material-symbols-outlined">delete</span></button>';
            props.querySelectorAll('[data-p]').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    const k = inp.dataset.p;
                    if (k === 'width') p.width = inp.value === '' ? null : clamp(parseFloat(inp.value) || 0, 2, 100);
                    else if (k === 'font_size') p.font_size = clamp(parseInt(inp.value, 10) || 14, 6, 96);
                    else p[k] = inp.value;
                    state.dirty = true; redrawSelected();
                });
            });
            props.querySelectorAll('[data-a]').forEach(function (b) {
                b.addEventListener('click', function () { p.text_align = b.dataset.a; state.dirty = true; draw(); drawProps(); });
            });
            props.querySelectorAll('[data-t]').forEach(function (b) {
                b.addEventListener('click', function () {
                    const t = b.dataset.t;
                    if (t === 'bold') p.font_weight = p.font_weight === 'bold' ? 'normal' : 'bold';
                    if (t === 'upper') p.uppercase = Number(p.uppercase) ? 0 : 1;
                    if (t === 'remove') {
                        state.positions = state.positions.filter(function (x) { return x !== p; });
                        state.selected = null; state.dirty = true; draw(); drawList(); drawProps(); return;
                    }
                    state.dirty = true; draw(); drawProps();
                });
            });
        }

        function redrawSelected() {
            const p = find(state.selected);
            const el = pageEl && pageEl.querySelector('.cert-field[data-key="' + CSS.escape(state.selected) + '"]');
            if (p && el) CertRender.applyFieldStyle(el, p);
        }

        function startDrag(e) {
            const el = e.currentTarget;
            const key = el.dataset.key;
            if (state.selected !== key) { select(key); }
            const p = find(key);
            if (!p) return;
            e.preventDefault();
            const target = pageEl.querySelector('.cert-field[data-key="' + CSS.escape(key) + '"]');
            const rect = pageEl.getBoundingClientRect();
            const startX = e.clientX, startY = e.clientY, ox = p.pos_x, oy = p.pos_y;
            function move(ev) {
                p.pos_x = round2(clamp(ox + (ev.clientX - startX) / rect.width * 100, 0, 100));
                p.pos_y = round2(clamp(oy + (ev.clientY - startY) / rect.height * 100, 0, 100));
                state.dirty = true;
                CertRender.applyFieldStyle(target, p);
            }
            function up() { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); }
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        }

        // Arrow keys nudge the selected field (Shift = bigger steps).
        function onKey(e) {
            if (!state.selected || !host.isConnected || /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) return;
            const p = find(state.selected); if (!p) return;
            const step = e.shiftKey ? 1 : 0.2;
            const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
            if (d) { e.preventDefault(); p.pos_x = round2(clamp(p.pos_x + d[0], 0, 100)); p.pos_y = round2(clamp(p.pos_y + d[1], 0, 100)); state.dirty = true; redrawSelected(); }
            if (e.key === 'Delete') { state.positions = state.positions.filter(function (x) { return x !== p; }); state.selected = null; state.dirty = true; draw(); drawList(); drawProps(); }
        }
        document.addEventListener('keydown', onKey);

        $('search').addEventListener('input', drawList);
        $('save').addEventListener('click', function () {
            const btn = $('save');
            btn.disabled = true; status.textContent = 'Saving…';
            Promise.resolve(cfg.onSave(state.positions.map(function (p) { return Object.assign({}, p); })))
                .then(function (ok) { if (ok !== false) { state.dirty = false; status.textContent = 'Saved.'; } else status.textContent = ''; })
                .catch(function (err) { status.textContent = (err && err.message) || 'Could not save.'; })
                .finally(function () { btn.disabled = false; });
        });
        if (cfg.onAiDetect) {
            $('ai').addEventListener('click', function () {
                const btn = $('ai');
                btn.disabled = true; status.textContent = 'AI is reading the template…';
                Promise.resolve(cfg.onAiDetect(state.positions.map(function (p) { return p.field_key; })))
                    .then(function (res) {
                        if (!res || !res.length) { status.textContent = 'AI could not place any field. You can still place them manually.'; return; }
                        res.forEach(function (r) {
                            const cur = find(r.field_key);
                            if (cur) Object.assign(cur, { pos_x: r.pos_x, pos_y: r.pos_y, width: r.width, text_align: r.text_align });
                            else state.positions.push(Object.assign({ field_label: labelOf[r.field_key] || r.field_key, font_size: 16, font_weight: 'normal', text_color: '#000000', uppercase: 0 }, r));
                        });
                        state.dirty = true; draw(); drawList(); drawProps();
                        status.textContent = 'AI placed ' + res.length + ' field(s). Check them, fix any mistakes, then click Save — nothing is saved yet.';
                    })
                    .catch(function (err) { status.textContent = (err && err.message) || 'AI Auto Detect is unavailable right now. You can still place fields manually.'; })
                    .finally(function () { btn.disabled = false; });
            });
        }

        draw(); drawList(); drawProps();
        const ro = new ResizeObserver(function () { draw(); });
        ro.observe(canvas);

        return {
            isDirty: function () { return state.dirty; },
            positions: function () { return state.positions.slice(); },
            destroy: function () { document.removeEventListener('keydown', onKey); ro.disconnect(); host.innerHTML = ''; },
        };
    }

    global.CertEditor = { mount: mount };
})(window);
