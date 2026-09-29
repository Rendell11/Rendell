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

    /** Number of pages of a model (a PDF / Word template keeps all of its pages). */
    function pageCount(model) { return model.pages && model.pages.length ? model.pages.length : 1; }

    /** Returns page `idx` (0-based) at full physical size (not scaled). Fields sit on their page_no (default 1). */
    function page(model, opts, idx) {
        opts = opts || {}; idx = idx || 0;
        const p = model.paper || { w: 794, h: 1123 };
        const pg = document.createElement('div');
        pg.className = 'cert-page';
        pg.dataset.page = idx + 1;
        pg.style.cssText = 'position:relative;overflow:hidden;background:#fff;font-family:"Times New Roman",Times,serif;' +
            'width:' + p.w + 'px;height:' + p.h + 'px;';
        const bg = model.pages && model.pages.length ? (model.pages[idx] || {}).bg_image : model.bg_image;
        if (bg) {
            const img = document.createElement('img');
            img.src = bg;
            img.alt = '';
            img.draggable = false;
            img.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:fill;pointer-events:none;user-select:none;' +
                'opacity:' + (model.bg_opacity == null ? 1 : model.bg_opacity) + ';';
            pg.appendChild(img);
        }
        (idx === 0 ? (model.legacy_blocks || []) : []).forEach(function (b) {
            const d = document.createElement('div');
            d.style.cssText = 'position:absolute;white-space:pre-wrap;word-break:break-word;line-height:1.5;' +
                'left:' + b.x + '%;top:' + b.y + '%;width:' + b.width + '%;font-size:' + b.font_size + 'px;' +
                'font-weight:' + b.font_weight + ';text-align:' + b.text_align + ';color:' + b.color + ';';
            d.textContent = b.text;
            pg.appendChild(d);
        });
        (model.fields || []).filter(function (f) { return (Number(f.page_no) || 1) === idx + 1; }).forEach(function (f) {
            const d = document.createElement('div');
            d.className = 'cert-field';
            d.dataset.key = f.field_key;
            applyFieldStyle(d, f);
            d.textContent = f.value != null && f.value !== '' ? f.value : (opts.placeholder ? (opts.placeholder(f) || '') : '');
            pg.appendChild(d);
        });
        return pg;
    }

    /** Every page of the model (full size). */
    function pages(model, opts) { const out = []; for (let i = 0; i < pageCount(model); i++) out.push(page(model, opts, i)); return out; }

    /** Marks fixed-width single-line fields whose text does not fit their blank (data-overflow). */
    function fitText(root) {
        (root || document).querySelectorAll('.cert-field').forEach(function (el) {
            el.removeAttribute('data-overflow');
            if (!el.style.width || el.style.width === 'auto') return;
            if (el.scrollWidth > el.clientWidth + 1) el.dataset.overflow = '1';
        });
    }

    /**
     * Render into `host`, scaled down to fit its width. opts.page (1-based) = only that page;
     * otherwise every page, one under the other. Returns the (first) page element.
     */
    function into(host, model, opts) {
        opts = opts || {};
        host.innerHTML = '';
        const p = model.paper || { w: 794, h: 1123 };
        const list = opts.page ? [page(model, opts, Math.min(pageCount(model), opts.page) - 1)] : pages(model, opts);
        const wraps = list.map(function (pg, i) {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'position:relative;margin:' + (i ? '16px' : '0') + ' auto 0;';
            wrap.appendChild(pg);
            host.appendChild(wrap);
            return wrap;
        });
        function fit() {
            const avail = opts.width || host.clientWidth || p.w;
            const s = Math.min(1, avail / p.w);
            list.forEach(function (pg, i) {
                pg.style.transformOrigin = 'top left';
                pg.style.transform = 'scale(' + s + ')';
                wraps[i].style.width = (p.w * s) + 'px';
                wraps[i].style.height = (p.h * s) + 'px';
                pg.dataset.scale = s;
            });
        }
        fit();
        list.forEach(function (pg) { fitText(pg); });
        if (!opts.noResize) {
            const ro = new ResizeObserver(fit);
            ro.observe(host);
        }
        return list[0];
    }

    global.CertRender = { page: page, pages: pages, pageCount: pageCount, into: into, fitText: fitText, applyFieldStyle: applyFieldStyle };
})(window);
