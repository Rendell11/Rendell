/* ==========================================================================
   DYH ERM — Secretary interface (linked to assigned doctors)
   Large, unmistakable queue controls for busy clinic hours.
   ========================================================================== */
(function () {
  'use strict';
  const { icon, esc, attr, avatar, statusBadge, priorityBadge, toast, field, fmtTime, fmtDate } = UI;
  const db = () => Store.db;
  const ROLE = 'secretary';
  const SS = { doctorId: null, date: UI.today(), apptFilter: 'all', draftFor: null };
  const user = () => db().accounts.find((a) => a.id === db().session.staff[ROLE]);

  const SOURCE = { kiosk: ['monitor', 'Kiosk'], app: ['smartphone', 'Patient app'], appointment: ['calendar', 'Appointment'], 'front desk': ['clipboard', 'Front desk'] };
  const sourceBadge = (s) => { const x = SOURCE[s] || ['info', s]; return `<span class="badge badge-neutral">${icon(x[0])}${esc(t(x[1]))}</span>`; };

  /* Shared queue widgets (also used by the doctor app) */
  function nowServingPanel(doctorId, opts) {
    opts = opts || {};
    const s = Q.serving(doctorId); const w = Q.waitingOrdered(doctorId);
    const p = s && Q.patient(s.patientId);
    return `<section class="now-serving" aria-live="polite">
      <div class="ns-label">${esc(t('Now serving'))}</div>
      ${s ? `<div class="row-between" style="align-items:flex-end;margin-top:6px"><div><div class="ns-no">${esc(s.no)}</div><div class="ns-name">${esc(Q.fullName(p))}</div>
          <div class="row" style="margin-top:8px">${priorityBadge(Q.priorityOf(p))}<span class="small" style="color:#C9D8EA">${esc(t('Called at {time}', { time: UI.fmtClock(s.calledAt) }))}</span></div></div>
          ${opts.openConsult ? `<a class="btn btn-lg" style="background:#fff;color:var(--navy)" href="#/doctor/consult/${s.patientId}">${icon('clipboard')}${esc(t('Open consultation'))}</a>` : ''}</div>`
        : `<div class="ns-empty" style="margin-top:8px">${w.length ? esc(t('No one is in consultation. Press “Call next” to start.')) : esc(t('The queue is empty.'))}</div>`}
      <div class="q-controls">
        ${s ? `<button class="btn btn-on-navy" data-act="sq.next" data-doc="${doctorId}">${icon('checkCircle', 'icon-lg')}${esc(t('Done / Next'))}</button>
               <button class="btn btn-outline-on-navy" data-act="sq.skip" data-id="${s.id}">${icon('skip', 'icon-lg')}${esc(t('Skip (not present)'))}</button>`
        : `<button class="btn btn-on-navy" data-act="sq.next" data-doc="${doctorId}" ${w.length ? '' : 'disabled'} style="grid-column:1/-1">${icon('play', 'icon-lg')}${esc(t('Call next'))}${w[0] ? ` · ${esc(w[0].no)}` : ''}</button>`}
      </div>
      ${s && w[0] ? `<div class="small" style="color:#C9D8EA;margin-top:10px">${esc(t('Next up'))}: <b style="color:#fff">${esc(w[0].no)} · ${esc(Q.shortName(Q.patient(w[0].patientId)))}</b></div>` : ''}
    </section>`;
  }
  function waitingList(doctorId, opts) {
    opts = opts || {};
    const w = Q.waitingOrdered(doctorId);
    if (!w.length) return UI.emptyState('users', t('No patients waiting'), t('New walk-ins and arrived appointments will appear here automatically.'));
    return `<div class="card" style="padding:0;overflow:hidden">${w.map((q, i) => {
      const p = Q.patient(q.patientId); const pr = Q.priorityOf(p);
      const draft = db().drafts.find((d) => d.queueId === q.id);
      return `<div class="q-row ${pr ? 'is-priority' : ''}"><span class="q-no">${esc(q.no)}</span>
        <div class="grow"><div class="strong">${esc(Q.fullName(p))}</div><div class="row small" style="gap:6px;margin-top:2px">${pr ? priorityBadge(pr) : ''}${sourceBadge(q.source)}${q.front ? `<span class="badge badge-warning">${icon('undo')}${esc(t('Called back'))}</span>` : ''}${draft ? `<span class="badge badge-info">${icon('edit')}${esc(t('Draft ready'))}</span>` : ''}<span class="muted">${esc(t('Waiting {n} min', { n: UI.minsAgo(q.createdAt) }))}</span></div></div>
        <span class="small muted hide-md">#${i + 1}</span>
        <div class="row" style="gap:6px">
          ${opts.doctor ? `<a class="btn btn-secondary btn-sm" href="#/doctor/patient/${p.id}">${icon('file')}${esc(t('Record'))}</a>` : `<button class="btn btn-secondary btn-sm" data-act="s.draftFor" data-id="${q.id}">${icon('edit')}${esc(t('Draft'))}</button>`}
          <button class="btn btn-primary btn-sm" data-act="sq.start" data-id="${q.id}">${icon('play')}${esc(t('Start'))}</button></div></div>`;
    }).join('')}</div>`;
  }
  function skippedList(doctorId) {
    const sk = Q.todaysQueue(doctorId).filter((q) => q.status === 'skipped');
    if (!sk.length) return `<p class="small muted">${esc(t('No skipped patients.'))}</p>`;
    return `<div class="card" style="padding:0">${sk.map((q) => `<div class="q-row"><span class="q-no" style="color:var(--gray-600)">${esc(q.no)}</span><div class="grow"><b>${esc(Q.shortName(Q.patient(q.patientId)))}</b><div class="xs muted">${esc(t('Skipped at {time}', { time: UI.fmtClock(q.skippedAt || q.calledAt || Date.now()) }))}</div></div><button class="btn btn-warning btn-sm" data-act="sq.callback" data-id="${q.id}">${icon('undo')}${esc(t('Call back'))}</button></div>`).join('')}</div>`;
  }
  function queueStats(doctorId) {
    const all = Q.todaysQueue(doctorId);
    const done = all.filter((q) => q.status === 'done');
    const avg = done.length ? Math.round(done.reduce((s, q) => s + (q.calledAt - q.createdAt), 0) / done.length / 60000) : 0;
    return `<div class="grid-4">
      <div class="stat"><div class="stat-label">${icon('clock')}${esc(t('Waiting'))}</div><div class="stat-value">${all.filter((q) => q.status === 'waiting').length}</div></div>
      <div class="stat"><div class="stat-label">${icon('checkCircle')}${esc(t('Seen today'))}</div><div class="stat-value">${done.length}</div></div>
      <div class="stat"><div class="stat-label">${icon('skip')}${esc(t('Skipped'))}</div><div class="stat-value">${all.filter((q) => q.status === 'skipped').length}</div></div>
      <div class="stat"><div class="stat-label">${icon('activity')}${esc(t('Avg. wait'))}</div><div class="stat-value">${avg}<span class="small muted"> ${esc(t('min'))}</span></div></div></div>`;
  }
  const actorName = () => { const u = db().accounts.find((a) => a.id === (db().session.staff.secretary || db().session.staff.doctor)); return u ? u.name : 'Staff'; };
  UI.on('sq.next', (el) => {
    const s = Q.serving(el.dataset.doc);
    const next = MockAPI.callNext(el.dataset.doc, actorName());
    toast(next ? t('{a}Now calling {no} — {name}.', { a: s ? t('{no} marked done. ', { no: s.no }) : '', no: next.no, name: Q.shortName(Q.patient(next.patientId)) }) : t('{no} marked done. No more patients waiting.', { no: s ? s.no : '' }), 'success');
    App.refresh();
  });
  UI.on('sq.skip', async (el) => {
    const q = db().queue.find((x) => x.id === el.dataset.id);
    const ok = await UI.confirm({ title: t('Skip {no}?', { no: q.no }), message: t('Use this when the patient did not respond after being called. They will get an SMS and can be called back later.'), confirmText: t('Skip patient'), danger: true, icon: 'skip' });
    if (!ok) return; MockAPI.skip(q.id, actorName()); toast(t('{no} skipped. You can call them back anytime.', { no: q.no }), 'warning'); App.refresh();
  });
  UI.on('sq.callback', (el) => { const q = db().queue.find((x) => x.id === el.dataset.id); MockAPI.callBack(q.id, actorName()); toast(t('{no} moved to the front of the waiting list.', { no: q.no }), 'success'); App.refresh(); });
  UI.on('sq.start', async (el) => {
    const q = db().queue.find((x) => x.id === el.dataset.id); const first = Q.waitingOrdered(q.doctorId)[0];
    const s = Q.serving(q.doctorId);
    if ((first && first.id !== q.id) || s) {
      const ok = await UI.confirm({ title: t('Start consultation for {no}?', { no: q.no }), message: (first && first.id !== q.id ? t('This patient is not next in line ({next} is next). ', { next: first.no }) : '') + (s ? t('{cur} is still in consultation and will return to the front of the waiting list.', { cur: s.no }) : ''), confirmText: t('Start consultation'), icon: 'play' });
      if (!ok) return;
    }
    MockAPI.startConsult(q.id, actorName()); toast(t('Consultation started for {no}.', { no: q.no }), 'success'); App.refresh();
  });

  /* ---------- Shell ---------- */
  function shell(page, title, content, live, topActions) {
    const u = user();
    const docs = u.doctorIds.map(Q.doctor);
    if (!SS.doctorId || !u.doctorIds.includes(SS.doctorId)) SS.doctorId = u.doctorIds[0];
    const waiting = Q.waitingOrdered(SS.doctorId).length;
    const pendingDrafts = db().drafts.filter((d) => d.doctorId === SS.doctorId && d.status === 'pending').length;
    App.mount(App.staffShell({
      role: ROLE, roleLabel: t('Secretary Workspace'), user: { name: u.name, sub: t('Secretary') }, active: '#/secretary/' + page, title,
      nav: [{ group: 'Clinic', items: [
        { href: '#/secretary/queue', icon: 'list', label: 'Live queue', count: waiting || '' },
        { href: '#/secretary/appointments', icon: 'calendar', label: 'Appointments' },
        { href: '#/secretary/notify', icon: 'megaphone', label: 'Announcements' },
        { href: '#/secretary/drafts', icon: 'edit', label: 'Consultation drafts', count: pendingDrafts || '' },
      ] }],
      topActions: `<label class="sr-only" for="s-doc">${esc(t('Doctor'))}</label><select id="s-doc" class="select" style="width:auto;min-width:220px" data-change="s.doc">${docs.map((d) => `<option value="${d.id}" ${d.id === SS.doctorId ? 'selected' : ''}>${esc(d.name)}</option>`).join('')}</select>${topActions || ''}`,
      content,
    }), () => route([page]), live);
  }
  UI.on('s.doc', (el) => { SS.doctorId = el.value; App.refresh(); });

  /* ---------- Live queue ---------- */
  function queuePage() {
    const d = Q.doctor(SS.doctorId); const s = Q.walkInStatus(d);
    shell('queue', t('Live queue'), `<div class="stack-lg">
      <div class="page-hd"><div><h2 style="margin:0">${esc(d.name)} · ${esc(t('Room'))} ${esc(d.room)}</h2><p>${esc(Q.specName(d.spec))} · ${esc(Q.scheduleText(d))}</p></div>
        <div class="row">${statusBadge(s.state, 'badge-lg')}
          <label class="switch"><input type="checkbox" ${d.walkIn ? 'checked' : ''} data-change="s.walkIn"><span class="track"></span><span class="small strong">${esc(t('Accept walk-ins'))}</span></label>
          <button class="btn btn-primary" data-act="s.addWalkin" ${Q.isClinicDay(d, UI.today()) ? '' : 'disabled title="' + attr(t('No clinic today')) + '"'}>${icon('userPlus')}${esc(t('Add walk-in'))}</button></div></div>
      ${queueStats(d.id)}
      <div class="grid" style="grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:20px" id="s-qgrid">
        <div class="stack">${nowServingPanel(d.id)}
          <section><h3>${esc(t('Skipped — not present'))}</h3>${skippedList(d.id)}</section>
          <div class="card-info small row-nowrap" style="align-items:flex-start">${icon('info')}<span>${esc(t('Priority patients (Senior, PWD, Pregnant) are placed first, as set by the hospital queue rules. Patients get an SMS when they are 3 away and when they are next.'))}</span></div></div>
        <section><div class="row-between" style="margin-bottom:8px"><h3 style="margin:0">${esc(t('Waiting list'))}</h3><span class="small muted">${icon('refresh')} ${esc(t('Updates automatically'))}</span></div>${waitingList(d.id)}</section>
      </div></div><style>@media(max-width:1100px){#s-qgrid{grid-template-columns:1fr!important}}@media(max-width:700px){.hide-md{display:none}}</style>`, true);
  }
  UI.on('s.walkIn', async (el) => {
    const d = Q.doctor(SS.doctorId);
    const ok = await UI.confirm({ title: el.checked ? t('Start accepting walk-ins?') : t('Stop accepting walk-ins?'), message: el.checked ? t('The kiosk and patient app will allow new walk-ins for {doc}.', { doc: d.name }) : t('The kiosk and patient app will stop giving new numbers for {doc}. Patients already in the queue are not affected.', { doc: d.name }), confirmText: t('Confirm') });
    if (!ok) { el.checked = !el.checked; return; }
    d.walkIn = el.checked; MockAPI.log(user().name, ROLE, el.checked ? 'Opened walk-ins' : 'Closed walk-ins', d.name); Store.save(); App.refresh();
    toast(el.checked ? t('Walk-ins are now open.') : t('Walk-ins are now closed.'), 'success');
  });

  /* Add walk-in modal: find existing patient or quick-register */
  UI.on('s.addWalkin', () => {
    const st = { q: '', sel: null, mode: 'search' };
    const results = () => {
      const q = st.q.toLowerCase();
      if (q.length < 2) return `<p class="small muted">${esc(t('Type at least 2 letters of the name, or the patient code.'))}</p>`;
      const list = db().patients.filter((p) => Q.fullName(p).toLowerCase().includes(q) || p.code.toLowerCase().includes(q)).slice(0, 8);
      if (!list.length) return `<div class="alert alert-info">${icon('info')}<div>${esc(t('No match found.'))} <button class="btn btn-ghost btn-sm" data-act="wk.new">${esc(t('Register a new patient'))}</button></div></div>`;
      return `<div class="card" style="padding:0">${list.map((p) => `<button class="q-row card-click" style="border:0;border-bottom:1px solid var(--gray-100);border-radius:0;${st.sel === p.id ? 'background:var(--light-blue)' : ''}" data-act="wk.sel" data-id="${p.id}">${avatar(p, 36)}<div class="grow"><b>${esc(Q.fullName(p))}</b><div class="xs muted">${esc(p.code)} · ${esc(t('Age'))} ${UI.ageFrom(p.bday)}</div></div>${priorityBadge(Q.priorityOf(p))}${st.sel === p.id ? icon('checkCircle') : ''}</button>`).join('')}</div>`;
    };
    const body = () => st.mode === 'search'
      ? `<div class="stack"><div class="input-icon">${icon('search')}<input class="input" placeholder="${attr(t('Search name or patient code'))}" data-input="wk.q" value="${attr(st.q)}" aria-label="${attr(t('Search patient'))}"></div><div id="wk-res">${results()}</div></div>`
      : `<form class="form-grid" id="wk-form" novalidate>${field({ name: 'first', label: t('First name'), required: true })}${field({ name: 'last', label: t('Last name'), required: true })}${field({ name: 'bday', label: t('Birthday'), type: 'date', required: true, attrs: `max="${UI.today()}"` })}${field({ name: 'sex', label: t('Sex'), type: 'select', required: true, options: [['', t('Select…')], ['F', t('Female')], ['M', t('Male')]] })}${field({ name: 'mobile', label: t('Mobile number'), type: 'tel', hint: t('Optional') })}${field({ name: 'priority', label: t('Priority'), type: 'select', options: [['', t('None / Senior (automatic by age)')], ['pwd', 'PWD'], ['pregnant', t('Pregnant')]] })}</form><button class="btn btn-ghost btn-sm" style="margin-top:8px" data-act="wk.back">${icon('chevronLeft')}${esc(t('Back to search'))}</button>`;
    const m = UI.openModal({ title: t('Add walk-in to {doc}', { doc: Q.doctor(SS.doctorId).name }), size: 'lg', body: `<div id="wk-body">${body()}</div>`, footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-primary" data-act="wk.add">${icon('plus')}${esc(t('Add to queue'))}</button>` });
    const upd = () => { m.querySelector('#wk-body').innerHTML = body(); const i = m.querySelector('input'); if (i) { i.focus(); i.setSelectionRange && i.type !== 'date' && i.setSelectionRange(i.value.length, i.value.length); } };
    UI.on('wk.q', (el) => { st.q = el.value; st.sel = null; m.querySelector('#wk-res').innerHTML = results(); });
    UI.on('wk.sel', (el) => { st.sel = el.dataset.id; m.querySelector('#wk-res').innerHTML = results(); });
    UI.on('wk.new', () => { st.mode = 'new'; upd(); });
    UI.on('wk.back', () => { st.mode = 'search'; upd(); });
    UI.on('wk.add', async (el) => {
      let pid = st.sel;
      if (st.mode === 'new') {
        const f = m.querySelector('#wk-form'); const d = UI.formData(f); const e = {};
        if (!d.first) e.first = t('Required.'); if (!d.last) e.last = t('Required.'); if (!d.bday) e.bday = t('Required.'); if (!d.sex) e.sex = t('Required.');
        if (d.mobile && !UI.validMobile(d.mobile)) e.mobile = t('Please enter an 11-digit mobile number starting with 09.');
        if (!UI.setErrors(f, e)) return;
        const p = MockAPI.registerPatient({ first: d.first, middle: '', last: d.last, bday: d.bday, sex: d.sex, mobile: d.mobile, priority: d.priority || null, address: { region: '', province: '', city: '', brgy: '', street: '' }, emergency: { name: '', mobile: '' } }, user().name);
        pid = p.id;
      }
      if (!pid) { toast(t('Please select a patient first.'), 'error'); return; }
      await UI.busy(el, () => UI.wait(400), t('Adding…'));
      const r = MockAPI.addToQueue({ doctorId: SS.doctorId, patientId: pid, source: 'front desk', by: user().name });
      if (r.error === 'already') { toast(t('This patient is already in the queue as {no}.', { no: r.entry.no }), 'warning'); return; }
      UI.closeModal(); toast(t('Added to queue as {no}.', { no: r.entry.no }), 'success'); App.refresh();
    });
  });

  /* ---------- Appointments ---------- */
  function apptPage() {
    const d = Q.doctor(SS.doctorId); const date = SS.date;
    let list = db().appointments.filter((a) => a.doctorId === d.id && a.date === date).sort((a, b) => a.time.localeCompare(b.time));
    const counts = { all: list.length, booked: list.filter((a) => ['booked', 'confirmed'].includes(a.status)).length, arrived: list.filter((a) => a.status === 'arrived').length, cancelled: list.filter((a) => a.status === 'cancelled').length };
    if (SS.apptFilter === 'booked') list = list.filter((a) => ['booked', 'confirmed'].includes(a.status));
    else if (SS.apptFilter !== 'all') list = list.filter((a) => a.status === SS.apptFilter);
    const isToday = date === UI.today();
    const clinic = Q.isClinicDay(d, date);
    shell('appointments', t('Appointments'), `<div class="stack">
      <div class="toolbar"><div class="row" style="gap:6px"><button class="btn btn-secondary btn-icon" data-act="s.day" data-n="-1" aria-label="${attr(t('Previous day'))}">${icon('chevronLeft')}</button>
        <input type="date" class="input" style="width:auto" value="${date}" data-change="s.date" aria-label="${attr(t('Date'))}">
        <button class="btn btn-secondary btn-icon" data-act="s.day" data-n="1" aria-label="${attr(t('Next day'))}">${icon('chevronRight')}</button>
        <button class="btn btn-ghost" data-act="s.day" data-n="0" ${isToday ? 'disabled' : ''}>${esc(t('Today'))}</button></div>
        <div class="grow"></div><h2 style="margin:0">${esc(UI.fmtDay(date))}</h2></div>
      ${!clinic ? `<div class="alert alert-warning">${icon('calendar')}<div>${esc(t('{doc} has no clinic on this day.', { doc: d.name }))}</div></div>` : ''}
      <div class="tabs" role="tablist">${[['all', 'All'], ['booked', 'Expected'], ['arrived', 'Arrived'], ['cancelled', 'Cancelled']].map(([k, l]) => `<button role="tab" aria-selected="${SS.apptFilter === k}" data-act="s.apptFilter" data-f="${k}">${esc(t(l))} (${counts[k]})</button>`).join('')}</div>
      ${list.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Time'))}</th><th>${esc(t('Patient'))}</th><th>${esc(t('Type'))}</th><th>${esc(t('Reason'))}</th><th>${esc(t('Status'))}</th><th class="right">${esc(t('Actions'))}</th></tr></thead><tbody>
        ${list.map((a) => { const p = Q.patient(a.patientId); const active = ['booked', 'confirmed'].includes(a.status); return `<tr><td class="strong nowrap">${esc(fmtTime(a.time))}</td><td><b>${esc(Q.fullName(p))}</b><div class="xs muted">${esc(p.code)} ${Q.priorityOf(p) ? '· ' + esc(t({ senior: 'Senior Citizen', pwd: 'PWD', pregnant: 'Pregnant' }[Q.priorityOf(p)])) : ''}</div></td><td>${a.type === 'follow-up' ? `<span class="badge badge-navy">${esc(t('Follow-up'))}</span>` : `<span class="badge badge-neutral">${esc(t('Appointment'))}</span>`}</td><td class="small">${esc(a.reason || '—')}</td><td>${statusBadge(a.status)}</td>
          <td class="actions">${active && isToday ? `<button class="btn btn-success btn-sm" data-act="s.arrived" data-id="${a.id}">${icon('userCheck')}${esc(t('Arrived'))}</button>` : ''}${active ? `<button class="btn btn-secondary btn-sm" data-act="s.resched" data-id="${a.id}">${esc(t('Reschedule'))}</button><button class="btn btn-danger-outline btn-sm" data-act="s.cancel" data-id="${a.id}">${esc(t('Cancel'))}</button>` : ''}</td></tr>`; }).join('')}</tbody></table></div>`
        : UI.emptyState('calendar', t('No appointments'), t('There are no appointments for this filter and date.'))}
    </div>`, true);
  }
  UI.on('s.day', (el) => { const n = Number(el.dataset.n); SS.date = n === 0 ? UI.today() : UI.addDays(SS.date, n); App.refresh(); });
  UI.on('s.date', (el) => { if (el.value) { SS.date = el.value; App.refresh(); } });
  UI.on('s.apptFilter', (el) => { SS.apptFilter = el.dataset.f; App.refresh(); });
  UI.on('s.arrived', async (el) => {
    const a = db().appointments.find((x) => x.id === el.dataset.id); const p = Q.patient(a.patientId);
    const ok = await UI.confirm({ title: t('Mark {name} as arrived?', { name: Q.shortName(p) }), message: t('The patient will be added to today’s queue and will receive a queue number by SMS.'), confirmText: t('Mark arrived'), icon: 'userCheck' });
    if (!ok) return;
    const r = MockAPI.markArrived(a.id, user().name);
    toast(r.entry ? t('{name} is now in the queue as {no}.', { name: Q.shortName(p), no: r.entry.no }) : t('Marked as arrived.'), 'success'); App.refresh();
  });
  UI.on('s.resched', (el) => Patient.rescheduleModal(el.dataset.id, ROLE, user().name));
  UI.on('s.cancel', async (el) => {
    const a = db().appointments.find((x) => x.id === el.dataset.id); const p = Q.patient(a.patientId);
    const ok = await UI.confirm({ title: t('Cancel appointment?'), message: t('{name} · {date} {time}. The patient will be notified by SMS.', { name: Q.shortName(p), date: fmtDate(a.date), time: fmtTime(a.time) }), confirmText: t('Cancel appointment'), cancelText: t('Keep'), danger: true, icon: 'alert' });
    if (!ok) return; MockAPI.cancelAppt(a.id, user().name, ROLE); toast(t('Appointment cancelled. Patient notified.'), 'success'); App.refresh();
  });

  /* ---------- Announcements to queued patients ---------- */
  const TEMPLATES = ['{doc} is running about 30 minutes late. Thank you for your patience.', '{doc} has arrived and consultations have started.', '{doc} will end clinic hours early today. Please approach the secretary at Room {room}.', 'Please prepare your previous laboratory results before entering Room {room}.'];
  function notifyPage() {
    const d = Q.doctor(SS.doctorId);
    const recipients = Q.todaysQueue(d.id).filter((q) => ['waiting', 'skipped'].includes(q.status));
    const sent = db().audit.filter((l) => l.action === 'Sent doctor announcement' && l.target.startsWith(d.name)).slice(0, 8);
    shell('notify', t('Announcements'), `<div class="grid" style="grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:20px" id="n-grid">
      <section class="card stack"><h2>${esc(t('Send an update to waiting patients'))}</h2>
        <p class="muted small" style="margin:0">${esc(t('Goes to the Patient App and by SMS to everyone currently waiting for {doc}.', { doc: d.name }))}</p>
        <div class="field"><label>${esc(t('Quick messages'))}</label><div class="chips">${TEMPLATES.map((tp, i) => `<button class="chip" data-act="s.tpl" data-i="${i}">${esc(t(tp, { doc: d.name.replace('Dr. ', 'Dr. '), room: d.room }).slice(0, 48))}…</button>`).join('')}</div></div>
        <form data-submit="s.send" class="stack" novalidate>${field({ name: 'msg', label: t('Message'), type: 'textarea', required: true, placeholder: t('Type a short, clear message for patients'), attrs: 'maxlength="300" data-input="s.count"' })}
          <div class="row-between"><span class="small muted" id="n-count">0 / 300</span><span class="badge badge-info">${icon('users')}${esc(t('{n} recipient(s)', { n: recipients.length }))}</span></div>
          <button class="btn btn-primary btn-lg" type="submit" ${recipients.length ? '' : 'disabled'}>${icon('send')}${esc(t('Send to {n} patient(s)', { n: recipients.length }))}</button>
          ${recipients.length ? '' : `<p class="small muted" style="margin:0">${esc(t('No patients are waiting right now.'))}</p>`}</form></section>
      <section class="card"><h2>${esc(t('Recently sent'))}</h2>${sent.length ? `<ul class="list small">${sent.map((l) => `<li><div class="strong">${esc(l.target.split(' — ')[1] || '')}</div><div class="xs muted">${esc(UI.fmtDateTime(l.at))} · ${esc(l.user)}</div></li>`).join('')}</ul>` : `<p class="small muted">${esc(t('Nothing sent yet today.'))}</p>`}</section>
    </div><style>@media(max-width:1000px){#n-grid{grid-template-columns:1fr!important}}</style>`);
  }
  UI.on('s.tpl', (el) => { const d = Q.doctor(SS.doctorId); const ta = document.querySelector('[name=msg]'); ta.value = t(TEMPLATES[Number(el.dataset.i)], { doc: d.name, room: d.room }); ta.focus(); document.getElementById('n-count').textContent = `${ta.value.length} / 300`; });
  UI.on('s.count', (el) => { document.getElementById('n-count').textContent = `${el.value.length} / 300`; });
  UI.on('s.send', async (form) => {
    const msg = form.msg.value.trim();
    if (msg.length < 5) return UI.setErrors(form, { msg: t('Please write a message (at least 5 characters).') });
    const d = Q.doctor(SS.doctorId); const rec = Q.todaysQueue(d.id).filter((q) => ['waiting', 'skipped'].includes(q.status));
    const ok = await UI.confirm({ title: t('Send this message?'), message: t('It will be sent to {n} patient(s) by SMS and in the Patient App. SMS credits will be used.', { n: rec.length }), confirmText: t('Send'), icon: 'send', details: `<div class="card-flat small">${esc(msg)}</div>` });
    if (!ok) return;
    await UI.busy(form.querySelector('button[type=submit]'), () => UI.wait(700), t('Sending…'));
    rec.forEach((q) => MockAPI.notify(q.patientId, t('Update from {doc}', { doc: d.name }), msg, true));
    MockAPI.log(user().name, ROLE, 'Sent doctor announcement', `${d.name} — ${msg}`); Store.save();
    toast(t('Message sent to {n} patient(s).', { n: rec.length }), 'success'); App.refresh();
  });

  /* ---------- Consultation drafts (for doctor review & signature) ---------- */
  UI.on('s.draftFor', (el) => { SS.draftFor = el.dataset.id; Router.go('/secretary/drafts'); });
  function draftsPage() {
    const d = Q.doctor(SS.doctorId);
    const candidates = Q.todaysQueue(d.id).filter((q) => ['waiting', 'serving'].includes(q.status));
    if (SS.draftFor && !candidates.some((q) => q.id === SS.draftFor)) SS.draftFor = null;
    const q = SS.draftFor && db().queue.find((x) => x.id === SS.draftFor);
    const p = q && Q.patient(q.patientId);
    const existing = q && db().drafts.find((x) => x.queueId === q.id);
    const v = (existing && existing.vitals) || {};
    const drafts = db().drafts.filter((x) => x.doctorId === d.id).sort((a, b) => b.at - a.at);
    shell('drafts', t('Consultation drafts'), `<div class="stack-lg">
      <div class="alert alert-info">${icon('info')}<div>${esc(t('Drafts help the doctor save time. They are NOT part of the medical record until the doctor reviews, edits and signs them.'))}</div></div>
      <div class="grid" style="grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:20px" id="d-grid">
        <section class="card"><h2>${esc(t('Choose a waiting patient'))}</h2>${candidates.length ? `<div class="stack-sm">${candidates.map((x) => { const pp = Q.patient(x.patientId); const has = db().drafts.some((dd) => dd.queueId === x.id); return `<button class="card card-click row-nowrap" style="padding:12px;${SS.draftFor === x.id ? 'border-color:var(--navy);box-shadow:0 0 0 1px var(--navy)' : ''}" data-act="s.pickDraft" data-id="${x.id}"><span class="q-no">${esc(x.no)}</span><div class="grow"><b>${esc(Q.shortName(pp))}</b><div class="xs muted">${statusBadge(x.status)}</div></div>${has ? `<span class="badge badge-info">${icon('edit')}${esc(t('Draft'))}</span>` : ''}</button>`; }).join('')}</div>` : UI.emptyState('users', t('No patients waiting'), '')}</section>
        <section class="card">${q ? `<h2>${esc(t('Draft for {no} · {name}', { no: q.no, name: Q.shortName(p) }))}</h2>
          <form data-submit="s.saveDraft" class="stack" novalidate>
            ${field({ name: 'complaint', label: t('Chief complaint (in the patient’s words)'), type: 'textarea', value: (existing && existing.complaint) || '', required: true })}
            <div class="form-grid">${[['bp', 'Blood pressure', 'e.g. 120/80'], ['hr', 'Heart rate (bpm)', ''], ['temp', 'Temperature (°C)', ''], ['rr', 'Respiratory rate', ''], ['wt', 'Weight (kg)', ''], ['ht', 'Height (cm)', ''], ['o2', 'Oxygen saturation (%)', '']].map(([k, l, ph]) => field({ name: k, label: t(l), value: v[k] || '', placeholder: ph, inputmode: k === 'bp' ? 'text' : 'decimal' })).join('')}</div>
            ${field({ name: 'notes', label: t('Notes for the doctor'), type: 'textarea', value: (existing && existing.notes) || '', placeholder: t('e.g. Brought previous lab results; requests medical certificate') })}
            <div class="row" style="justify-content:flex-end"><button class="btn btn-primary btn-lg" type="submit">${icon('send')}${esc(existing ? t('Update draft') : t('Send draft to doctor'))}</button></div></form>`
        : UI.emptyState('edit', t('Select a patient'), t('Choose a waiting patient on the left to record vitals and the chief complaint.'))}</section></div>
      <section><h2>${esc(t('Today’s drafts'))}</h2>${drafts.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Patient'))}</th><th>${esc(t('Complaint'))}</th><th>${esc(t('Prepared by'))}</th><th>${esc(t('Status'))}</th></tr></thead><tbody>${drafts.map((x) => `<tr><td><b>${esc(Q.shortName(Q.patient(x.patientId)))}</b></td><td class="small">${esc(x.complaint)}</td><td class="small">${esc(x.by)} · ${esc(UI.fmtClock(x.at))}</td><td>${x.status === 'pending' ? `<span class="badge badge-warning">${icon('clock')}${esc(t('Awaiting doctor review'))}</span>` : `<span class="badge badge-success">${icon('check')}${esc(t('Used by doctor'))}</span>`}</td></tr>`).join('')}</tbody></table></div>` : `<p class="muted small">${esc(t('No drafts yet.'))}</p>`}</section>
    </div><style>@media(max-width:1000px){#d-grid{grid-template-columns:1fr!important}}</style>`);
  }
  UI.on('s.pickDraft', (el) => { SS.draftFor = el.dataset.id; App.refresh(); });
  UI.on('s.saveDraft', async (form) => {
    const f = UI.formData(form);
    if (!f.complaint) return UI.setErrors(form, { complaint: t('Please enter the chief complaint.') });
    if (f.bp && !/^\d{2,3}\/\d{2,3}$/.test(f.bp)) return UI.setErrors(form, { bp: t('Use the format 120/80.') });
    const q = db().queue.find((x) => x.id === SS.draftFor);
    const ex = db().drafts.find((x) => x.queueId === q.id);
    const data = { complaint: f.complaint, vitals: { bp: f.bp, hr: f.hr, temp: f.temp, rr: f.rr, wt: f.wt, ht: f.ht, o2: f.o2 }, notes: f.notes, by: user().name, at: Date.now(), status: 'pending' };
    if (ex) Object.assign(ex, data); else db().drafts.push({ id: UI.uid('dr'), queueId: q.id, patientId: q.patientId, doctorId: q.doctorId, ...data });
    MockAPI.log(user().name, ROLE, 'Prepared consultation draft', `Queue ${q.no}`); Store.save();
    await UI.busy(form.querySelector('button[type=submit]'), () => UI.wait(400), t('Saving…'));
    toast(t('Draft sent to the doctor for review.'), 'success'); SS.draftFor = null; App.refresh();
  });

  /* ---------- Routes ---------- */
  function route(parts) {
    if (!user()) {
      return App.staffLogin({ role: ROLE, title: t('Secretary Workspace'), username: 'egonzales', onLogin: () => { db().session.staff[ROLE] = 'u4'; MockAPI.log('Ella Mae Gonzales', ROLE, 'Signed in', 'Secretary workspace'); Store.save(true); route(parts); } });
    }
    const u = user();
    if (!SS.doctorId || !u.doctorIds.includes(SS.doctorId)) SS.doctorId = u.doctorIds[0];
    switch (parts[0]) {
      case 'appointments': return apptPage();
      case 'notify': return notifyPage();
      case 'drafts': return draftsPage();
      default: return queuePage();
    }
  }
  Router.add('secretary', route);
  window.StaffQueue = { nowServingPanel, waitingList, skippedList, queueStats, sourceBadge };
})();
