/**
 * analytics_report.js — shared helpers for the Announcement module analytics pages
 * (disaster_analytics.php and announcement_analytics.php).
 *
 *  - AI panel rendering (Key Findings · Trends · Recommended Actions · Other Observations)
 *  - "Include AI Analytics Overview?" Yes/No dialog shown before Print / Save as PDF
 *  - ONE report document builder used by BOTH Print and Save as PDF, so the printed
 *    page and the PDF always have the same layout
 *  - Print  → opens the report and automatically shows the browser Print Preview
 *  - PDF    → renders the same markup off-screen and paginates it on block boundaries
 *
 * Needs BRGY (barangay identity) defined by partials/analytics_head.php.
 */
const AnalyticsReport = (() => {
    'use strict';

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = (n) => (n === null || n === undefined || n === '') ? '—' : Number(n).toLocaleString('en-US');

    // Charts print on white paper — keep their text readable in light and dark mode
    if (window.Chart) {
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        Chart.defaults.color = '#64748b';
    }

    // ── AI panel (on-page) ────────────────────────────────────────────────────
    const AI_SECTIONS = [
        ['key_findings', 'Key Findings', 'search_insights'],
        ['trends', 'Trends', 'trending_up'],
        ['recommended_actions', 'Recommended Actions', 'task_alt'],
        ['other_observations', 'Other Observations', 'lightbulb'],
    ];

    function aiPanelHtml(ai) {
        if (ai.raw) {
            return `<p class="text-[13px] text-slate-600 font-medium leading-relaxed whitespace-pre-line">${esc(ai.raw)}</p>`;
        }
        const s = ai.summary || {};
        const blocks = AI_SECTIONS.map(([key, title, icon]) => {
            const items = s[key] || [];
            if (!items.length) return '';
            return `<div class="bg-white rounded-2xl border border-slate-100 p-5">
                <p class="flex items-center gap-1.5 section-title mb-3">
                    <span class="material-symbols-outlined text-indigo-400" style="font-size:15px">${icon}</span>${title}
                </p>
                <ul class="space-y-2.5">${items.map(t => `
                    <li class="flex gap-2.5 text-[13px] text-slate-600 font-medium leading-relaxed">
                        <span class="mt-2 w-1.5 h-1.5 rounded-full bg-indigo-400 shrink-0"></span><span>${esc(t)}</span>
                    </li>`).join('')}</ul>
            </div>`;
        }).join('');
        return `<p class="text-sm font-bold text-slate-800 leading-relaxed mb-5">${esc(s.headline || '')}</p>
                <div class="grid md:grid-cols-2 gap-4">${blocks}</div>`;
    }

    /**
     * Calls an AI endpoint ONLY when the user asks for it.
     * @returns {Promise<object|null>} the AI response, or null on failure
     */
    async function generateAi({ url, params, button, panel, body, meta, loadingText }) {
        const icon = button.querySelector('.material-symbols-outlined');
        const label = button.querySelector('[data-label]');
        const oldLabel = label ? label.textContent : '';

        panel.classList.remove('hidden');
        body.innerHTML = `<div class="flex items-center gap-3 py-6">
            <div class="w-5 h-5 border-2 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            <span class="text-xs font-bold text-slate-400">${esc(loadingText || 'Analysing the selected records…')}</span></div>`;
        meta.innerHTML = '&mdash;';
        button.disabled = true;
        if (icon) { icon.textContent = 'progress_activity'; icon.classList.add('animate-spin'); }
        if (label) label.textContent = 'Generating…';

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(Object.assign({ csrf_token: CSRF_TOKEN }, params)),
            });
            const data = await res.json();
            if (!data.success) {
                body.innerHTML = `<div class="flex items-start gap-2.5 py-2 text-[13px] font-semibold text-rose-600">
                    <span class="material-symbols-outlined" style="font-size:18px">error</span>
                    <span>${esc(data.error || 'Could not generate the analytics. Please try again.')}</span></div>`;
                return null;
            }
            meta.textContent = `${data.range ? data.range.label + ' · ' : ''}Generated ${data.generated}`;
            body.innerHTML = aiPanelHtml(data);
            return data;
        } catch (e) {
            body.innerHTML = `<div class="flex items-start gap-2.5 py-2 text-[13px] font-semibold text-rose-600">
                <span class="material-symbols-outlined" style="font-size:18px">error</span>
                <span>Something went wrong while connecting to the AI service.</span></div>`;
            return null;
        } finally {
            button.disabled = false;
            if (icon) { icon.textContent = 'auto_awesome'; icon.classList.remove('animate-spin'); }
            if (label) label.textContent = oldLabel;
        }
    }

    // ── "Include AI Analytics Overview?" dialog ──────────────────────────────
    function askIncludeAi(action, hasAi) {
        return new Promise(resolve => {
            const old = document.getElementById('arExportDialog');
            if (old) old.remove();

            const isPrint = action === 'print';
            const wrap = document.createElement('div');
            wrap.id = 'arExportDialog';
            wrap.className = 'fixed inset-0 z-[200] modal-backdrop flex items-center justify-center p-6';
            wrap.innerHTML = `
            <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden">
                <div class="px-7 pt-7 pb-4 flex items-start justify-between">
                    <div>
                        <p class="section-title mb-1">${isPrint ? 'Print' : 'Save as PDF'}</p>
                        <h3 class="text-base font-black text-slate-800 tracking-tight">Include AI Analytics Overview?</h3>
                    </div>
                    <button type="button" data-act="cancel" class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors">
                        <span class="material-symbols-outlined">close</span></button>
                </div>
                <div class="px-7 pb-2 space-y-3">
                    <label class="flex gap-3 p-4 rounded-2xl border border-slate-200 cursor-pointer has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50/40">
                        <input type="radio" name="arIncludeAi" value="yes" class="mt-0.5 text-indigo-600" ${hasAi ? 'checked' : ''}>
                        <span><span class="block text-sm font-black text-slate-800">Yes</span>
                        <span class="block text-xs text-slate-500 font-medium mt-0.5">Include the AI Analytics Overview plus all reports and data.</span>
                        ${hasAi ? '' : '<span class="block text-[11px] text-amber-600 font-bold mt-1">AI analytics has not been generated yet — it will be generated first.</span>'}</span>
                    </label>
                    <label class="flex gap-3 p-4 rounded-2xl border border-slate-200 cursor-pointer has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50/40">
                        <input type="radio" name="arIncludeAi" value="no" class="mt-0.5 text-indigo-600" ${hasAi ? '' : 'checked'}>
                        <span><span class="block text-sm font-black text-slate-800">No</span>
                        <span class="block text-xs text-slate-500 font-medium mt-0.5">Leave out the AI section. All reports and data are still included.</span></span>
                    </label>
                </div>
                <div class="px-7 py-5 flex gap-3">
                    <button type="button" data-act="cancel" class="btn btn-light flex-1">Cancel</button>
                    <button type="button" data-act="ok" class="btn btn-dark flex-[2]">
                        <span class="material-symbols-outlined" style="font-size:16px">${isPrint ? 'print' : 'picture_as_pdf'}</span>
                        ${isPrint ? 'Print' : 'Save PDF'}</button>
                </div>
            </div>`;
            document.body.appendChild(wrap);

            const close = (val) => { wrap.remove(); resolve(val); };
            wrap.addEventListener('click', (e) => {
                if (e.target === wrap) return close(null);
                const act = e.target.closest('[data-act]');
                if (!act) return;
                if (act.dataset.act === 'cancel') return close(null);
                const v = wrap.querySelector('input[name="arIncludeAi"]:checked');
                close(v ? v.value === 'yes' : false);
            });
        });
    }

    // ── Report document building blocks ──────────────────────────────────────
    function statGrid(items, cols = 4) {
        return `<div class="rx-stats" style="grid-template-columns:repeat(${cols},1fr)">${items.map(i => `
            <div class="rx-stat"><div class="rx-stat-val" style="color:${i.color || '#0f172a'}">${esc(i.value)}</div>
            <div class="rx-stat-label">${esc(i.label)}</div>${i.note ? `<div class="rx-stat-note">${esc(i.note)}</div>` : ''}</div>`).join('')}</div>`;
    }

    function table(headers, rows, opts = {}) {
        const align = opts.align || [];
        const th = headers.map((h, i) => `<th style="text-align:${align[i] || 'left'}">${esc(h)}</th>`).join('');
        const body = rows.length
            ? rows.map(r => `<tr>${r.map((c, i) => `<td style="text-align:${align[i] || 'left'}">${c && c.html !== undefined ? c.html : esc(c)}</td>`).join('')}</tr>`).join('')
            : `<tr><td colspan="${headers.length}" class="rx-empty">${esc(opts.empty || 'No records in this date range.')}</td></tr>`;
        return `<table class="rx-table"><thead><tr>${th}</tr></thead><tbody>${body}</tbody></table>`;
    }

    function chartImage(chart, height = 230) {
        if (!chart) return '<p class="rx-empty">Chart not available.</p>';
        try {
            return `<div class="rx-chart"><img src="${chart.toBase64Image('image/png', 1)}" style="max-height:${height}px" alt=""></div>`;
        } catch (e) {
            return '<p class="rx-empty">Chart not available.</p>';
        }
    }

    function twoCol(a, b) {
        return `<div class="rx-two"><div>${a}</div><div>${b}</div></div>`;
    }

    function aiDocSection(ai) {
        if (!ai) return '';
        let inner;
        if (ai.raw) {
            inner = `<p class="rx-ai-headline" style="white-space:pre-line;font-weight:500">${esc(ai.raw)}</p>`;
        } else {
            const s = ai.summary || {};
            inner = `<p class="rx-ai-headline">${esc(s.headline || '')}</p>
                <div class="rx-ai-grid">${AI_SECTIONS.map(([key, title]) => (s[key] || []).length ? `
                    <div class="rx-ai-box"><div class="rx-ai-title">${title}</div>
                    <ul>${s[key].map(t => `<li>${esc(t)}</li>`).join('')}</ul></div>` : '').join('')}</div>`;
        }
        return `<div class="rx-section rx-keep rx-ai">
            <div class="rx-section-label"><span class="rx-bullet"></span>AI Analytics Overview</div>
            <p class="rx-desc">An AI-generated reading of the figures in this report — key findings, trends, recommended actions
               and other observations for the same date range. Generated ${esc(ai.generated || '')}. Please verify before using it in an official decision.</p>
            ${inner}
        </div>`;
    }

    /**
     * @param {object} o
     *   docType, docTitle, subtitle, rangeLabel, filterLabel, refNo, ai (or null),
     *   sections: [{ title, desc, html, keep:true|false }]
     */
    function buildDocument(o) {
        const generated = new Date().toLocaleString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        const brgyName = (BRGY.name || 'Barangay').toUpperCase();
        const logo = BRGY.logo
            ? `<img src="${esc(BRGY.logo)}" alt="" class="rx-logo" crossorigin="anonymous">`
            : `<div class="rx-logo rx-logo-ph">${esc((BRGY.name || 'B').charAt(0).toUpperCase())}</div>`;

        const sections = (o.sections || []).map(s => `
            <div class="rx-section ${s.keep === false ? '' : 'rx-keep'}">
                <div class="rx-head">
                    <div class="rx-section-label"><span class="rx-bullet"></span>${esc(s.title)}</div>
                    ${s.desc ? `<p class="rx-desc">${esc(s.desc)}</p>` : ''}
                </div>
                ${s.html}
            </div>`).join('');

        return `
        <div class="rx-page" style="--rx-accent:${o.accent || '#4f46e5'}">
            <div class="rx-accentbar"></div>
            <div class="rx-letterhead rx-keep">
                ${logo}
                <div class="rx-lh-text">
                    <p class="rx-lh-sup">Republic of the Philippines</p>
                    ${BRGY.address ? `<p class="rx-lh-sup">${esc(BRGY.address)}</p>` : ''}
                    <h1 class="rx-lh-name">${esc(brgyName)}</h1>
                    <p class="rx-lh-sub">${esc(o.office || 'Office of the Barangay')}</p>
                </div>
            </div>
            <div class="rx-titleband rx-keep">
                <div>
                    <p class="rx-doctype">${esc(o.docTitle)}</p>
                    <p class="rx-docsub">${esc(o.subtitle || '')}</p>
                </div>
                <div class="rx-refbox">
                    <span class="rx-reflabel">Reporting Period</span>
                    <span class="rx-refno">${esc(o.rangeLabel)}</span>
                    ${o.filterLabel ? `<span class="rx-reflabel" style="margin-top:4px">${esc(o.filterLabel)}</span>` : ''}
                </div>
            </div>
            ${aiDocSection(o.ai)}
            ${sections}
            <div class="rx-keep">
            <div class="rx-signatures">
                <div class="rx-sign"><p class="rx-sign-role">Prepared by</p><div class="rx-sign-line"></div>
                    <p class="rx-sign-name">${esc(BRGY.preparedBy || 'Administrator')}</p><p class="rx-sign-title">${esc(o.preparedTitle || 'Barangay Staff')}</p></div>
                <div class="rx-sign"><p class="rx-sign-role">Noted by</p><div class="rx-sign-line"></div>
                    <p class="rx-sign-name">${esc(BRGY.captain || 'Barangay Captain')}</p><p class="rx-sign-title">Barangay Captain</p></div>
            </div>
            <div class="rx-footer">
                <span>${esc(o.docTitle)} · ${esc(BRGY.name || 'Barangay')}</span>
                <span>Generated ${esc(generated)}</span>
            </div>
            </div>
        </div>`;
    }

    function styles() {
        return `
    * { box-sizing: border-box; }
    body { margin: 0; background: #fff; }
    .rx-page { font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif; width: 794px; margin: 0 auto; background: #fff;
        padding: 40px 48px 36px; color: #334155; }
    .rx-accentbar { height: 6px; border-radius: 6px; background: var(--rx-accent); margin-bottom: 22px; }
    .rx-letterhead { display: flex; align-items: center; gap: 18px; padding-bottom: 16px; border-bottom: 2px solid #0f172a; }
    .rx-logo { width: 72px; height: 72px; object-fit: contain; border-radius: 50%; flex: 0 0 72px; }
    .rx-logo-ph { display: flex; align-items: center; justify-content: center; background: #0f172a; color: #fff; font-size: 28px; font-weight: 900; }
    .rx-lh-text { flex: 1; text-align: center; padding-right: 72px; }
    .rx-lh-sup { margin: 0; font-size: 10.5px; font-weight: 600; color: #64748b; letter-spacing: .04em; }
    .rx-lh-name { margin: 3px 0 2px; font-size: 23px; font-weight: 900; color: #0f172a; letter-spacing: .04em; line-height: 1.15; }
    .rx-lh-sub { margin: 0; font-size: 9.5px; font-weight: 700; color: var(--rx-accent); text-transform: uppercase; letter-spacing: .18em; }
    .rx-titleband { display: flex; justify-content: space-between; align-items: flex-end; gap: 18px; margin: 20px 0 18px; }
    .rx-doctype { margin: 0; font-size: 16px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: .1em; }
    .rx-docsub { margin: 5px 0 0; font-size: 12px; font-weight: 600; color: #475569; max-width: 430px; line-height: 1.55; }
    .rx-refbox { text-align: right; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 9px 14px; background: #f8fafc; white-space: nowrap; }
    .rx-reflabel { display: block; font-size: 8px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .16em; }
    .rx-refno { display: block; font-size: 12.5px; font-weight: 900; color: #0f172a; letter-spacing: .03em; margin-top: 2px; }
    .rx-section { margin: 0 0 18px; padding-top: 2px; }
    .rx-section-label { display: flex; align-items: center; gap: 8px; font-size: 10px; font-weight: 900; color: #0f172a;
        text-transform: uppercase; letter-spacing: .16em; margin-bottom: 5px; }
    .rx-bullet { width: 14px; height: 3px; border-radius: 3px; background: var(--rx-accent); display: inline-block; }
    .rx-desc { margin: 0 0 10px; font-size: 10.5px; line-height: 1.6; color: #64748b; font-weight: 500; font-style: italic; }
    .rx-stats { display: grid; gap: 8px; }
    .rx-stat { border: 1px solid #e2e8f0; border-radius: 12px; padding: 11px 8px; text-align: center; background: #fff; }
    .rx-stat-val { font-size: 20px; font-weight: 900; line-height: 1.1; }
    .rx-stat-label { font-size: 7.5px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .12em; margin-top: 5px; }
    .rx-stat-note { font-size: 8.5px; font-weight: 600; color: #64748b; margin-top: 3px; }
    .rx-chart { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; text-align: center; background: #fff; }
    .rx-chart img { max-width: 100%; }
    .rx-two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .rx-table { width: 100%; border-collapse: collapse; font-size: 10px; }
    .rx-table th { background: #f1f5f9; color: #475569; font-size: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; padding: 7px 8px; border-bottom: 1.5px solid #cbd5e1; }
    .rx-table td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; color: #334155; font-weight: 500; vertical-align: top; }
    .rx-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .rx-empty { text-align: center; color: #94a3b8; font-style: italic; padding: 14px !important; font-size: 10.5px; }
    .rx-ai { background: #f5f7ff; border: 1px solid #e0e7ff; border-radius: 14px; padding: 14px 16px; }
    .rx-ai-headline { margin: 0 0 12px; font-size: 12px; font-weight: 800; color: #1e293b; line-height: 1.6; }
    .rx-ai-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .rx-ai-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; }
    .rx-ai-title { font-size: 8.5px; font-weight: 900; color: #4f46e5; text-transform: uppercase; letter-spacing: .14em; margin-bottom: 6px; }
    .rx-ai-box ul { margin: 0; padding-left: 15px; }
    .rx-ai-box li { font-size: 10.5px; line-height: 1.55; color: #475569; margin-bottom: 4px; }
    .rx-signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; margin-top: 30px; }
    .rx-sign { text-align: center; }
    .rx-sign-role { margin: 0 0 38px; font-size: 9px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .14em; }
    .rx-sign-line { border-top: 1.5px solid #0f172a; margin: 0 8px; }
    .rx-sign-name { margin: 7px 0 2px; font-size: 11.5px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: .04em; }
    .rx-sign-title { margin: 0; font-size: 9px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .12em; }
    .rx-footer { display: flex; justify-content: space-between; margin-top: 28px; padding-top: 10px; border-top: 1px solid #e2e8f0;
        font-size: 8.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .1em; }
    @page { size: A4; margin: 12mm 0; }
    @page :first { margin-top: 0; }
    @media print {
        .rx-page { width: 100%; padding: 30px 40px 26px; }
        .rx-keep, .rx-table tr { break-inside: avoid; page-break-inside: avoid; }
        .rx-head { break-after: avoid; page-break-after: avoid; }
        .rx-table thead { display: table-header-group; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }`;
    }

    // ── Print (automatic Print Preview) ───────────────────────────────────────
    function print(docHtml, title) {
        const w = window.open('', '_blank', 'height=900,width=1000');
        if (!w) { alert('Please allow pop-ups for this site to print the report.'); return; }
        w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
            <style>${styles()}</style></head><body>${docHtml}</body></html>`);
        w.document.close();
        w.focus();

        const go = () => { try { w.focus(); w.print(); } catch (e) { } };
        const imgs = Array.from(w.document.images);
        const waitImgs = Promise.all(imgs.map(img => img.complete ? null : new Promise(r => { img.onload = img.onerror = r; })));
        const waitFonts = (w.document.fonts && w.document.fonts.ready) ? w.document.fonts.ready : Promise.resolve();
        Promise.race([Promise.all([waitImgs, waitFonts]), new Promise(r => setTimeout(r, 2500))])
            .then(() => setTimeout(go, 250));
    }

    // ── Save as PDF (same markup, paginated on block boundaries) ─────────────
    async function pdf(docHtml, filename) {
        if (typeof window.jspdf === 'undefined' || typeof window.html2canvas === 'undefined') {
            alert('PDF library failed to load. Check your connection and try again.');
            return;
        }
        const { jsPDF } = window.jspdf;
        const holder = document.createElement('div');
        holder.style.cssText = 'position:fixed;left:-10000px;top:0;width:794px;background:#fff;z-index:-1;';
        holder.innerHTML = `<style>${styles()}</style>${docHtml}`;
        document.body.appendChild(holder);

        try {
            if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (e) { } }
            await Promise.all(Array.from(holder.querySelectorAll('img')).map(img =>
                img.complete ? null : new Promise(r => { img.onload = img.onerror = r; })));

            const page = holder.querySelector('.rx-page');
            const pageRect = page.getBoundingClientRect();
            const cssW = page.offsetWidth;
            const totalCss = page.offsetHeight;

            // Safe places to cut: the bottom of every block and every table row
            const cuts = Array.from(page.querySelectorAll('.rx-keep, .rx-table tbody tr'))
                .map(el => el.getBoundingClientRect().bottom - pageRect.top)
                .filter(v => v > 0).sort((a, b) => a - b);

            const canvas = await html2canvas(page, { scale: 2, backgroundColor: '#ffffff', useCORS: true, logging: false });
            const k = canvas.width / cssW;

            const doc = new jsPDF({ unit: 'pt', format: 'a4' });
            const pageW = doc.internal.pageSize.getWidth();
            const pageH = doc.internal.pageSize.getHeight();
            const ptPerCss = pageW / cssW;
            const margin = 34; // ≈ 12 mm, same as the @page margin used by Print

            // Stop just below the last block so trailing padding never makes a blank page
            const pad = 6; // keep text descenders on the page above the cut
            const contentEnd = cuts.length ? Math.min(totalCss, cuts[cuts.length - 1] + pad) : totalCss;

            let start = 0, pageNo = 0;
            while (start < contentEnd - 1) {
                const topPt = pageNo === 0 ? 0 : margin;
                const usable = (pageH - topPt - margin) / ptPerCss;
                let end = start + usable;
                if (end >= contentEnd) {
                    end = contentEnd;
                } else {
                    const fit = cuts.filter(c => c > start + 40 && c + pad <= end);
                    if (fit.length) end = fit[fit.length - 1] + pad;
                }

                const sy = Math.round(start * k);
                const sh = Math.max(1, Math.round((end - start) * k));
                const slice = document.createElement('canvas');
                slice.width = canvas.width;
                slice.height = sh;
                const ctx = slice.getContext('2d');
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, slice.width, slice.height);
                ctx.drawImage(canvas, 0, sy, canvas.width, sh, 0, 0, canvas.width, sh);

                if (pageNo > 0) doc.addPage();
                doc.addImage(slice.toDataURL('image/jpeg', 0.95), 'JPEG', 0, topPt, pageW, sh / k * ptPerCss, undefined, 'FAST');
                start = end;
                pageNo++;
            }
            doc.save(filename);
        } catch (err) {
            console.error('Analytics PDF error:', err);
            alert('Failed to generate the PDF. Please try again.');
        } finally {
            holder.remove();
        }
    }

    return { esc, num, aiPanelHtml, generateAi, askIncludeAi, statGrid, table, chartImage, twoCol, buildDocument, print, pdf };
})();
