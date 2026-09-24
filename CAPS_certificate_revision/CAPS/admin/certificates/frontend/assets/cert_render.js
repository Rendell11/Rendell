/**
 * cert_render.js — draws a certificate page from a render model.
 * The SAME function is used by the preview modal, the layout editor and the
 * print page, so preview = print.
 *
 * model = {
 *   paper: { w, h, css },            // CSS px at 96 dpi
 *   bg_image, bg_opacity,
 *   fields: [{ field_key, value, pos_x, pos_y, width, font_size, font_weight, text_align, text_color, uppercase }],
 *   legacy_blocks: [...]             // read-only blocks from the removed "Custom Layout" option
 * }
 * pos_x / pos_y are % of the page: x is the left edge / center / right edge (per text_align),
 * y is the vertical middle of the text line.
 */
(function (global) {
    'use strict';

    function applyFieldStyle(el, f) {
        const align = f.text_align || 'center';
        const tx = align === 'left' ? '0' : (align === 'right' ? '-100%' : '-50%');
        el.style.position = 'absolute';
        el.style.left = f.pos_x + '%';
        el.style.top = f.pos_y + '%';
        el.style.transform = 'translate(' + tx + ', -50%)';
        el.style.fontSize = (f.font_size || 14) + 'px';
        el.style.fontWeight = f.font_weight === 'bold' ? '700' : '400';
        el.style.textAlign = align;
        el.style.color = f.text_color || '#000';
        el.style.textTransform = Number(f.uppercase) ? 'uppercase' : 'none';
        el.style.lineHeight = '1.25';
        if (f.width) { el.style.width = f.width + '%'; el.style.whiteSpace = 'normal'; }
        else { el.style.width = 'auto'; el.style.whiteSpace = 'nowrap'; }
    }

    /** Returns a page element at full physical size (not scaled). */
    function page(model, opts) {
        opts = opts || {};
        const p = model.paper || { w: 794, h: 1123 };
        const pg = document.createElement('div');
        pg.className = 'cert-page';
        pg.style.cssText = 'position:relative;overflow:hidden;background:#fff;font-family:"Times New Roman",Times,serif;' +
            'width:' + p.w + 'px;height:' + p.h + 'px;';
        if (model.bg_image) {
            const img = document.createElement('img');
            img.src = model.bg_image;
            img.alt = '';
            img.draggable = false;
            img.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:fill;pointer-events:none;user-select:none;' +
                'opacity:' + (model.bg_opacity == null ? 1 : model.bg_opacity) + ';';
            pg.appendChild(img);
        }
        (model.legacy_blocks || []).forEach(function (b) {
            const d = document.createElement('div');
            d.style.cssText = 'position:absolute;white-space:pre-wrap;word-break:break-word;line-height:1.5;' +
                'left:' + b.x + '%;top:' + b.y + '%;width:' + b.width + '%;font-size:' + b.font_size + 'px;' +
                'font-weight:' + b.font_weight + ';text-align:' + b.text_align + ';color:' + b.color + ';';
            d.textContent = b.text;
            pg.appendChild(d);
        });
        (model.fields || []).forEach(function (f) {
            const d = document.createElement('div');
            d.className = 'cert-field';
            d.dataset.key = f.field_key;
            applyFieldStyle(d, f);
            d.textContent = f.value != null && f.value !== '' ? f.value : (opts.placeholder ? (opts.placeholder(f) || '') : '');
            pg.appendChild(d);
        });
        return pg;
    }

    /** Render into `host`, scaled down to fit its width. Returns the page element. */
    function into(host, model, opts) {
        opts = opts || {};
        host.innerHTML = '';
        const p = model.paper || { w: 794, h: 1123 };
        const pg = page(model, opts);
        const wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;margin:0 auto;';
        wrap.appendChild(pg);
        host.appendChild(wrap);
        function fit() {
            const avail = opts.width || host.clientWidth || p.w;
            const s = Math.min(1, avail / p.w);
            pg.style.transformOrigin = 'top left';
            pg.style.transform = 'scale(' + s + ')';
            wrap.style.width = (p.w * s) + 'px';
            wrap.style.height = (p.h * s) + 'px';
            pg.dataset.scale = s;
        }
        fit();
        if (!opts.noResize) {
            const ro = new ResizeObserver(fit);
            ro.observe(host);
        }
        return pg;
    }

    global.CertRender = { page: page, into: into, applyFieldStyle: applyFieldStyle };
})(window);
