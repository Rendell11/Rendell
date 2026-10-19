/* ==========================================================================
   DYH ERM — System Admin dashboard
   Hospital operations only. Admins cannot open or edit clinical records.
   ========================================================================== */
(function () {
  'use strict';
  const { icon, esc, attr, avatar, statusBadge, priorityBadge, toast, field, fmtDate, fmtTime } = UI;
  const db = () => Store.db;
  const ROLE = 'admin';
  const user = () => db().accounts.find((a) => a.id === db().session.staff[ROLE]);
  const AS = { acc: { q: '', role: '', status: '' }, pt: { q: '', status: '', prio: '', page: 1 }, audit: { q: '', role: '' }, sms: { status: '' }, fb: { src: '', rating: '' }, qm: { spec: '' }, report: null };
  const log = (action, target) => MockAPI.log(user().name, ROLE, action, target);
  const ROLE_LABEL = { doctor: 'Doctor', secretary: 'Secretary', frontdesk: 'Front desk', admin: 'Administrator' };

  function shell(page, title, content, live, topActions) {
    const u = user();
    const pendingV = db().verifications.filter((v) => v.status === 'pending').length + db().duplicates.filter((x) => x.status === 'open').length;
    App.mount(App.staffShell({
      role: ROLE, roleLabel: t('System Administration'), user: { name: u.name, sub: t('Administrator') }, active: '#/admin/' + page, title, topActions,
      nav: [
        { group: 'Operations', items: [
          { href: '#/admin/overview', icon: 'grid', label: 'Overview' },
          { href: '#/admin/queues', icon: 'list', label: 'Queue monitor' },
          { href: '#/admin/announcements', icon: 'megaphone', label: 'Announcements & ads' },
        ] },
        { group: 'Records', items: [
          { href: '#/admin/patients', icon: 'users', label: 'Patient master list' },
          { href: '#/admin/doctors', icon: 'stethoscope', label: 'Doctor master list' },
          { href: '#/admin/verification', icon: 'userCheck', label: 'Verification & duplicates', count: pendingV || '' },
        ] },
        { group: 'Administration', items: [
          { href: '#/admin/accounts', icon: 'key', label: 'Staff accounts' },
          { href: '#/admin/clinics', icon: 'building', label: 'Clinics & specialties' },
          { href: '#/admin/settings', icon: 'settings', label: 'System settings' },
        ] },
        { group: 'Insights', items: [
          { href: '#/admin/reports', icon: 'file', label: 'Reports' },
          { href: '#/admin/analytics', icon: 'sparkles', label: 'AI analytics' },
          { href: '#/admin/feedback', icon: 'star', label: 'Ratings & feedback' },
          { href: '#/admin/sms', icon: 'message', label: 'SMS monitoring' },
          { href: '#/admin/audit', icon: 'shield', label: 'Audit logs' },
        ] },
      ], content,
    }), () => Router.render(), live);
  }

  /* ---------- Overview ---------- */
  function overview() {
    const docs = db().doctors;
    const q = db().queue.filter((x) => x.date === UI.today());
    const waiting = q.filter((x) => x.status === 'waiting');
    const done = q.filter((x) => x.status === 'done');
    const avgWait = done.length ? Math.round(done.reduce((s, x) => s + (x.calledAt - x.createdAt), 0) / done.length / 60000) : 0;
    const appts = db().appointments.filter((a) => a.date === UI.today() && a.status !== 'cancelled');
    const newPts = db().patients.filter((p) => new Date(p.createdAt).toDateString() === new Date().toDateString()).length;
    const hours = Array.from({ length: 11 }, (_, i) => 7 + i).map((h) => ({ l: (h > 12 ? h - 12 : h) + (h >= 12 ? 'p' : 'a'), v: q.filter((x) => new Date(x.createdAt).getHours() === h).length }));
    const s = db().settings;
    const alerts = [];
    if (db().sms.credits < s.smsLowCredit) alerts.push(['error', 'message', t('SMS credits are low ({n} left).', { n: db().sms.credits }), '#/admin/sms']);
    const pv = db().verifications.filter((v) => v.status === 'pending').length; if (pv) alerts.push(['warning', 'userCheck', t('{n} patient account(s) waiting for verification.', { n: pv }), '#/admin/verification']);
    const fs = db().sms.log.filter((x) => x.status === 'failed').length; if (fs) alerts.push(['warning', 'alert', t('{n} SMS message(s) failed to deliver.', { n: fs }), '#/admin/sms']);
    const dup = db().duplicates.filter((x) => x.status === 'open').length; if (dup) alerts.push(['info', 'merge', t('{n} possible duplicate patient record(s) to review.', { n: dup }), '#/admin/verification']);
    shell('overview', t('Overview'), `<div class="stack-lg">
      <div class="page-hd"><div><h2 style="margin:0">${esc(UI.fmtDay(UI.today()))}</h2><p>${esc(t('Live operational snapshot'))} · <span class="demo-tag">${esc(t('sample data'))}</span></p></div><span class="small muted">${icon('refresh')} ${esc(t('Updates automatically'))}</span></div>
      <div class="grid-4">
        <div class="stat"><div class="stat-label">${icon('users')}${esc(t('Waiting now'))}</div><div class="stat-value">${waiting.length}</div><div class="stat-sub">${esc(t('across {n} clinics', { n: new Set(waiting.map((x) => x.doctorId)).size }))}</div></div>
        <div class="stat"><div class="stat-label">${icon('checkCircle')}${esc(t('Seen today'))}</div><div class="stat-value">${done.length}</div></div>
        <div class="stat"><div class="stat-label">${icon('clock')}${esc(t('Avg. wait'))}</div><div class="stat-value">${avgWait}<span class="small muted"> ${esc(t('min'))}</span></div></div>
        <div class="stat"><div class="stat-label">${icon('calendar')}${esc(t('Appointments today'))}</div><div class="stat-value">${appts.length}</div><div class="stat-sub">${esc(t('{n} new registrations', { n: newPts }))}</div></div>
      </div>
      ${alerts.length ? `<div class="stack-sm">${alerts.map(([k, ic, m, href]) => `<a class="alert alert-${k}" href="${href}" style="text-decoration:none">${icon(ic)}<div class="grow">${esc(m)}</div>${icon('chevronRight')}</a>`).join('')}</div>` : ''}
      <div class="grid" style="grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:20px" id="ov-grid">
        <section class="card"><div class="card-hd"><h3>${esc(t('Queue arrivals by hour (today)'))}</h3></div>${UI.barChart(hours, { label: t('Queue arrivals by hour'), height: 200 })}</section>
        <section class="card"><div class="card-hd"><h3>${esc(t('Arrivals by source'))}</h3></div>${UI.hbars([['kiosk', 'Kiosk'], ['app', 'Patient app'], ['appointment', 'Appointment'], ['front desk', 'Front desk']].map(([k, l]) => ({ l: t(l), v: q.filter((x) => x.source === k).length })))}</section>
      </div>
      <section class="card"><div class="card-hd"><h3>${esc(t('Clinics today'))}</h3><a class="small strong" href="#/admin/queues">${esc(t('Open queue monitor'))}</a></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Doctor'))}</th><th>${esc(t('Room'))}</th><th>${esc(t('Status'))}</th><th>${esc(t('Now serving'))}</th><th>${esc(t('Waiting'))}</th><th>${esc(t('Seen'))}</th></tr></thead><tbody>
        ${docs.filter((d) => Q.isClinicDay(d, UI.today())).map((d) => { const st = Q.walkInStatus(d); return `<tr><td><b>${esc(d.name)}</b><div class="xs muted">${esc(Q.specName(d.spec))}</div></td><td>${esc(d.room)}</td><td>${statusBadge(st.state)}</td><td class="q-no" style="font-size:1em">${esc(st.servingNo)}</td><td>${st.waiting}</td><td>${Q.todaysQueue(d.id).filter((x) => x.status === 'done').length}</td></tr>`; }).join('')}</tbody></table></div></section>
    </div><style>@media(max-width:1000px){#ov-grid{grid-template-columns:1fr!important}}</style>`, true);
  }

  /* ---------- Queue monitor ---------- */
  function queues() {
    const docs = db().doctors.filter((d) => Q.isClinicDay(d, UI.today()) && (!AS.qm.spec || d.spec === AS.qm.spec));
    shell('queues', t('Queue monitor'), `<div class="stack">
      <div class="toolbar"><select class="select" data-change="a.qmSpec" aria-label="${attr(t('Filter by clinic'))}"><option value="">${esc(t('All clinics'))}</option>${Q.SPECIALTIES.map((s) => `<option value="${s.id}" ${AS.qm.spec === s.id ? 'selected' : ''}>${esc(I18N.pick(s))}</option>`).join('')}</select><div class="grow"></div><span class="small muted">${icon('refresh')} ${esc(t('Live — updates when kiosks and clinics change'))}</span></div>
      ${docs.length ? `<div class="grid-auto">${docs.map((d) => {
        const st = Q.walkInStatus(d); const w = Q.waitingOrdered(d.id); const longest = w.reduce((m, x) => Math.max(m, UI.minsAgo(x.createdAt)), 0);
        return `<section class="card stack-sm"><div class="row-nowrap">${avatar(d, 44)}<div class="grow"><b>${esc(d.name)}</b><div class="xs muted">${esc(Q.specName(d.spec))} · ${esc(t('Room'))} ${esc(d.room)}</div></div></div>${statusBadge(st.state)}
          <div class="k-qstats" style="font-size:1rem"><div><b>${esc(st.servingNo)}</b><span>${esc(t('Now serving'))}</span></div><div><b>${w.length}</b><span>${esc(t('Waiting'))}</span></div><div><b>${longest}m</b><span>${esc(t('Longest wait'))}</span></div></div>
          ${longest > 60 ? `<div class="alert alert-warning small" style="padding:8px 12px">${icon('clock')}<div>${esc(t('Long wait — consider notifying patients.'))}</div></div>` : ''}
          <div class="small">${w.slice(0, 4).map((x) => `<span class="badge ${Q.priorityOf(Q.patient(x.patientId)) ? 'badge-priority' : 'badge-neutral'}" style="margin:2px">${esc(x.no)}</span>`).join('')}${w.length > 4 ? ` <span class="muted">+${w.length - 4}</span>` : ''}</div></section>`;
      }).join('')}</div>` : UI.emptyState('list', t('No clinics running'), t('No doctors in this clinic have a schedule today.'))}
    </div>`, true);
  }
  UI.on('a.qmSpec', (el) => { AS.qm.spec = el.value; App.refresh(); });

  /* ---------- Staff accounts ---------- */
  function accounts() {
    const f = AS.acc; const q = f.q.toLowerCase();
    const list = db().accounts.filter((a) => (!f.role || a.role === f.role) && (!f.status || a.status === f.status) && (!q || a.name.toLowerCase().includes(q) || a.username.includes(q) || a.email.includes(q)));
    shell('accounts', t('Staff accounts'), `<div class="stack">
      <div class="toolbar"><div class="input-icon">${icon('search')}<input class="input" type="search" placeholder="${attr(t('Search name, username or email'))}" value="${attr(f.q)}" data-input="a.accQ" aria-label="${attr(t('Search accounts'))}"></div>
        <select class="select" data-change="a.accRole" aria-label="${attr(t('Role'))}"><option value="">${esc(t('All roles'))}</option>${Object.entries(ROLE_LABEL).map(([k, l]) => `<option value="${k}" ${f.role === k ? 'selected' : ''}>${esc(t(l))}</option>`).join('')}</select>
        <select class="select" data-change="a.accStatus" aria-label="${attr(t('Status'))}"><option value="">${esc(t('All statuses'))}</option><option value="active" ${f.status === 'active' ? 'selected' : ''}>${esc(t('Active'))}</option><option value="inactive" ${f.status === 'inactive' ? 'selected' : ''}>${esc(t('Inactive'))}</option></select>
        <div class="grow"></div><button class="btn btn-primary" data-act="a.accNew">${icon('userPlus')}${esc(t('Add account'))}</button></div>
      <div id="acc-table">${accTable(list)}</div></div>`);
  }
  function accTable(list) {
    if (!list.length) return UI.emptyState('users', t('No accounts match'), t('Try clearing the filters.'));
    return `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Name'))}</th><th>${esc(t('Role'))}</th><th>${esc(t('Assignment'))}</th><th>${esc(t('Status'))}</th><th>${esc(t('Last sign-in'))}</th><th class="right">${esc(t('Actions'))}</th></tr></thead><tbody>
      ${list.map((a) => `<tr><td><div class="row-nowrap">${avatar(a, 36)}<div><b>${esc(a.name)}</b><div class="xs muted">${esc(a.username)} · ${esc(a.email)}</div></div></div></td><td><span class="badge badge-info">${esc(t(ROLE_LABEL[a.role]))}</span></td>
        <td class="small">${a.role === 'secretary' ? esc((a.doctorIds || []).map((id) => Q.doctor(id).name).join(', ')) : a.role === 'doctor' ? esc(Q.specName(Q.doctor(a.doctorId).spec)) : '—'}</td><td>${statusBadge(a.status)}</td><td class="small">${esc(UI.fmtDateTime(a.lastLogin))}</td>
        <td class="actions"><button class="btn btn-secondary btn-sm" data-act="a.accEdit" data-id="${a.id}">${icon('edit')}${esc(t('Edit'))}</button><button class="btn btn-ghost btn-sm" data-act="a.accReset" data-id="${a.id}">${icon('key')}${esc(t('Reset password'))}</button>${a.id === user().id ? '' : `<button class="btn ${a.status === 'active' ? 'btn-danger-outline' : 'btn-success'} btn-sm" data-act="a.accToggle" data-id="${a.id}">${esc(a.status === 'active' ? t('Deactivate') : t('Reactivate'))}</button>`}</td></tr>`).join('')}</tbody></table></div>`;
  }
  const accUpd = () => { const f = AS.acc; const q = f.q.toLowerCase(); document.getElementById('acc-table').innerHTML = accTable(db().accounts.filter((a) => (!f.role || a.role === f.role) && (!f.status || a.status === f.status) && (!q || a.name.toLowerCase().includes(q) || a.username.includes(q) || a.email.includes(q)))); };
  UI.on('a.accQ', (el) => { AS.acc.q = el.value; accUpd(); });
  UI.on('a.accRole', (el) => { AS.acc.role = el.value; accUpd(); });
  UI.on('a.accStatus', (el) => { AS.acc.status = el.value; accUpd(); });
  function accModal(a) {
    const isNew = !a; a = a || { name: '', role: 'secretary', username: '', email: '', doctorIds: [], doctorId: '' };
    const body = (role) => `<form id="acc-form" class="form-grid" novalidate>
      ${field({ name: 'name', label: t('Full name'), value: a.name, required: true, span2: true })}
      ${field({ name: 'role', label: t('Role'), type: 'select', value: role, options: Object.entries(ROLE_LABEL).map(([k, l]) => [k, t(l)]), attrs: 'data-change="a.accRoleSel"' })}
      ${field({ name: 'username', label: t('Username'), value: a.username, required: true, hint: t('Lowercase letters and numbers') })}
      ${field({ name: 'email', label: t('Email'), type: 'email', value: a.email, required: true, span2: true })}
      ${role === 'secretary' ? `<div class="field span-2"><label>${esc(t('Assigned doctors'))}<span class="req">*</span></label><div class="grid-2">${db().doctors.map((d) => `<label class="check small"><input type="checkbox" name="doc_${d.id}" ${(a.doctorIds || []).includes(d.id) ? 'checked' : ''}><span>${esc(d.name)}</span></label>`).join('')}</div><input type="hidden" name="docs"></div>` : ''}
      ${role === 'doctor' ? field({ name: 'doctorId', label: t('Doctor profile'), type: 'select', value: a.doctorId, span2: true, options: [['', t('Select…')], ...db().doctors.map((d) => [d.id, d.name])] }) : ''}
      ${role === 'admin' ? `<div class="alert alert-warning span-2 small">${icon('shield')}<div>${esc(t('Administrator accounts can manage hospital operations but cannot view or edit clinical medical records.'))}</div></div>` : ''}
    </form>`;
    const m = UI.openModal({ title: isNew ? t('Add staff account') : t('Edit account'), size: 'lg', body: `<div id="acc-body">${body(a.role)}</div>`, footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-primary" data-act="a.accSave">${esc(isNew ? t('Create account') : t('Save changes'))}</button>` });
    UI.on('a.accRoleSel', (el) => { const f = UI.formData(m.querySelector('#acc-form')); Object.assign(a, { name: f.name, username: f.username, email: f.email }); m.querySelector('#acc-body').innerHTML = body(el.value); });
    UI.on('a.accSave', async (btn) => {
      const form = m.querySelector('#acc-form'); const f = UI.formData(form); const e = {};
      if (!f.name) e.name = t('Please enter the full name.');
      if (!/^[a-z0-9]{3,20}$/.test(f.username)) e.username = t('Use 3–20 lowercase letters or numbers.');
      else if (db().accounts.some((x) => x.username === f.username && x.id !== a.id)) e.username = t('This username is already taken.');
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.email)) e.email = t('Please enter a valid email address.');
      const docIds = db().doctors.filter((d) => f['doc_' + d.id]).map((d) => d.id);
      if (f.role === 'secretary' && !docIds.length) e.docs = t('Assign at least one doctor.');
      if (f.role === 'doctor' && !f.doctorId) e.doctorId = t('Choose the doctor profile.');
      if (!UI.setErrors(form, e)) return;
      await UI.busy(btn, () => UI.wait(500), t('Saving…'));
      const data = { name: f.name, role: f.role, username: f.username, email: f.email, doctorIds: f.role === 'secretary' ? docIds : undefined, doctorId: f.role === 'doctor' ? f.doctorId : undefined };
      if (isNew) { db().accounts.push({ id: UI.uid('u'), status: 'active', lastLogin: Date.now(), ...data }); log('Created staff account', f.username); }
      else { Object.assign(db().accounts.find((x) => x.id === a.id), data); log('Updated staff account', f.username); }
      Store.save(); UI.closeModal(); toast(isNew ? t('Account created. A temporary password was emailed (simulated).') : t('Account updated.'), 'success'); App.refresh();
    });
  }
  UI.on('a.accNew', () => accModal());
  UI.on('a.accEdit', (el) => accModal({ ...db().accounts.find((x) => x.id === el.dataset.id) }));
  UI.on('a.accToggle', async (el) => {
    const a = db().accounts.find((x) => x.id === el.dataset.id); const off = a.status === 'active';
    const ok = await UI.confirm({ title: off ? t('Deactivate {name}?', { name: a.name }) : t('Reactivate {name}?', { name: a.name }), message: off ? t('They will be signed out and can no longer sign in. Their past activity stays in the audit log.') : t('They will be able to sign in again.'), confirmText: off ? t('Deactivate') : t('Reactivate'), danger: off, icon: off ? 'ban' : 'checkCircle' });
    if (!ok) return; a.status = off ? 'inactive' : 'active'; log(off ? 'Deactivated account' : 'Reactivated account', a.username); Store.save(); toast(off ? t('Account deactivated.') : t('Account reactivated.'), 'success'); App.refresh();
  });
  UI.on('a.accReset', async (el) => {
    const a = db().accounts.find((x) => x.id === el.dataset.id);
    const ok = await UI.confirm({ title: t('Reset password?'), message: t('A temporary password will be sent to {email}. They must change it at next sign-in.', { email: a.email }), confirmText: t('Send reset'), icon: 'key' });
    if (!ok) return; log('Reset password', a.username); Store.save(); toast(t('Password reset email sent (simulated).'), 'success');
  });

  /* ---------- Clinics & specialties ---------- */
  function clinics() {
    shell('clinics', t('Clinics & specialties'), `<div class="stack"><div class="toolbar"><div class="grow"></div><button class="btn btn-primary" data-act="a.clinicNew">${icon('plus')}${esc(t('Add clinic'))}</button></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Clinic'))}</th><th>${esc(t('Rooms'))}</th><th>${esc(t('Doctors'))}</th><th>${esc(t('Status'))}</th><th class="right">${esc(t('Actions'))}</th></tr></thead><tbody>
      ${db().clinics.map((c) => `<tr><td><b>${esc(c.name)}</b>${Q.spec(c.spec) ? `<div class="xs muted">${esc(I18N.pick(Q.spec(c.spec).desc))}</div>` : ''}</td><td class="small">${esc(c.location)}</td><td>${db().doctors.filter((d) => d.spec === c.spec).length}</td><td>${statusBadge(c.status)}</td><td class="actions"><button class="btn btn-secondary btn-sm" data-act="a.clinicEdit" data-id="${c.id}">${icon('edit')}${esc(t('Edit'))}</button><button class="btn ${c.status === 'active' ? 'btn-danger-outline' : 'btn-success'} btn-sm" data-act="a.clinicToggle" data-id="${c.id}">${esc(c.status === 'active' ? t('Deactivate') : t('Reactivate'))}</button></td></tr>`).join('')}</tbody></table></div></div>`);
  }
  function clinicModal(c) {
    const isNew = !c; c = c || { name: '', location: '', spec: '' };
    UI.openModal({ title: isNew ? t('Add clinic') : t('Edit clinic'), body: `<form id="cl-form" class="stack" novalidate>${field({ name: 'name', label: t('Clinic / specialty name'), value: c.name, required: true })}${field({ name: 'location', label: t('Rooms / location'), value: c.location, required: true, placeholder: 'Room 201, 2nd Floor' })}</form>`, footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-primary" data-act="a.clinicSave">${esc(t('Save'))}</button>` });
    UI.on('a.clinicSave', () => {
      const form = UI.topModal().querySelector('#cl-form'); const f = UI.formData(form); const e = {};
      if (!f.name) e.name = t('Please enter a name.'); if (!f.location) e.location = t('Please enter the room or location.');
      if (!UI.setErrors(form, e)) return;
      if (isNew) db().clinics.push({ id: UI.uid('c'), name: f.name, location: f.location, spec: '', status: 'active' }); else Object.assign(db().clinics.find((x) => x.id === c.id), f);
      log(isNew ? 'Added clinic' : 'Updated clinic', f.name); Store.save(); UI.closeModal(); toast(t('Clinic saved.'), 'success'); App.refresh();
    });
  }
  UI.on('a.clinicNew', () => clinicModal());
  UI.on('a.clinicEdit', (el) => clinicModal(db().clinics.find((x) => x.id === el.dataset.id)));
  UI.on('a.clinicToggle', async (el) => {
    const c = db().clinics.find((x) => x.id === el.dataset.id); const off = c.status === 'active';
    const ok = await UI.confirm({ title: off ? t('Deactivate {name}?', { name: c.name }) : t('Reactivate {name}?', { name: c.name }), message: off ? t('The clinic will be hidden from booking and the kiosk. Existing appointments are kept.') : t('The clinic will be visible again.'), confirmText: off ? t('Deactivate') : t('Reactivate'), danger: off });
    if (!ok) return; c.status = off ? 'inactive' : 'active'; log(off ? 'Deactivated clinic' : 'Reactivated clinic', c.name); Store.save(); App.refresh();
  });

  /* ---------- Announcements & ads ---------- */
  const AUD = { all: 'Patient app & kiosk', app: 'Patient app only', kiosk: 'Kiosk only' };
  function announcements() {
    const list = db().announcements.slice().sort((a, b) => (b.active - a.active) || b.start.localeCompare(a.start));
    shell('announcements', t('Announcements & ads'), `<div class="stack"><div class="toolbar"><p class="muted small" style="margin:0">${esc(t('Shown on the Patient App home screen and the kiosk welcome slides.'))}</p><div class="grow"></div><button class="btn btn-primary" data-act="a.annNew">${icon('plus')}${esc(t('New announcement'))}</button></div>
      <div class="grid-2">${list.map((a) => { const expired = a.end < UI.today(); return `<article class="card stack-sm"><div class="row-between"><div class="row"><span class="badge ${a.kind === 'ad' ? 'badge-navy' : 'badge-info'}">${esc(a.kind === 'ad' ? t('Advertisement') : t('Announcement'))}</span><span class="badge badge-neutral">${icon(a.audience === 'kiosk' ? 'monitor' : a.audience === 'app' ? 'smartphone' : 'layers')}${esc(t(AUD[a.audience]))}</span>${expired ? `<span class="badge badge-error">${esc(t('Expired'))}</span>` : ''}</div>
        <label class="switch"><input type="checkbox" ${a.active ? 'checked' : ''} data-change="a.annToggle" data-id="${a.id}"><span class="track"></span><span class="sr-only">${esc(t('Active'))}</span></label></div>
        <div class="row-nowrap" style="align-items:flex-start"><span class="role-ic" style="width:44px;height:44px">${icon(a.icon || 'megaphone')}</span><div><b style="color:var(--navy)">${esc(a.title.en)}</b><div class="small">${esc(a.body.en)}</div>${a.title.fil ? `<div class="xs muted" style="margin-top:4px">FIL: ${esc(a.title.fil)}</div>` : ''}</div></div>
        <div class="row-between small"><span class="muted">${esc(fmtDate(a.start))} – ${esc(fmtDate(a.end))}</span><div class="row"><button class="btn btn-secondary btn-sm" data-act="a.annEdit" data-id="${a.id}">${icon('edit')}${esc(t('Edit'))}</button><button class="btn btn-ghost btn-sm" data-act="a.annDel" data-id="${a.id}" aria-label="${attr(t('Delete'))}">${icon('trash')}</button></div></div></article>`; }).join('')}</div></div>`);
  }
  function annModal(a) {
    const isNew = !a; a = a || { kind: 'announcement', audience: 'all', title: { en: '', fil: '' }, body: { en: '', fil: '' }, start: UI.today(), end: UI.addDays(UI.today(), 30), icon: 'megaphone', active: true };
    UI.openModal({ title: isNew ? t('New announcement') : t('Edit announcement'), size: 'lg', body: `<form id="ann-form" class="form-grid" novalidate>
      ${field({ name: 'kind', label: t('Type'), type: 'select', value: a.kind, options: [['announcement', t('Announcement')], ['ad', t('Advertisement / promo')]] })}
      ${field({ name: 'audience', label: t('Show on'), type: 'select', value: a.audience, options: Object.entries(AUD).map(([k, l]) => [k, t(l)]) })}
      ${field({ name: 'titleEn', label: t('Title (English)'), value: a.title.en, required: true })}${field({ name: 'titleFil', label: t('Title (Filipino)'), value: a.title.fil, hint: t('Recommended') })}
      ${field({ name: 'bodyEn', label: t('Message (English)'), type: 'textarea', value: a.body.en, required: true })}${field({ name: 'bodyFil', label: t('Message (Filipino)'), type: 'textarea', value: a.body.fil })}
      ${field({ name: 'start', label: t('Start date'), type: 'date', value: a.start, required: true })}${field({ name: 'end', label: t('End date'), type: 'date', value: a.end, required: true })}
      ${field({ name: 'icon', label: t('Icon'), type: 'select', value: a.icon, options: [['megaphone', t('Megaphone')], ['heartPulse', t('Heart')], ['calendar', t('Calendar')], ['accessibility', t('Accessibility')], ['clipboard', t('Clipboard')], ['pill', t('Medicine')]] })}</form>`,
    footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-primary" data-act="a.annSave">${esc(isNew ? t('Publish') : t('Save'))}</button>` });
    UI.on('a.annSave', () => {
      const form = UI.topModal().querySelector('#ann-form'); const f = UI.formData(form); const e = {};
      if (!f.titleEn) e.titleEn = t('Please enter a title.'); if (!f.bodyEn) e.bodyEn = t('Please enter a message.');
      if (!f.start) e.start = t('Required.'); if (!f.end) e.end = t('Required.'); else if (f.end < f.start) e.end = t('End date must be after the start date.');
      if (!UI.setErrors(form, e)) return;
      const data = { kind: f.kind, audience: f.audience, title: { en: f.titleEn, fil: f.titleFil }, body: { en: f.bodyEn, fil: f.bodyFil }, start: f.start, end: f.end, icon: f.icon };
      if (isNew) db().announcements.unshift({ id: UI.uid('n'), active: true, ...data }); else Object.assign(db().announcements.find((x) => x.id === a.id), data);
      log(isNew ? 'Published announcement' : 'Updated announcement', f.titleEn); Store.save(); UI.closeModal(); toast(isNew ? t('Announcement published.') : t('Announcement updated.'), 'success'); App.refresh();
    });
  }
  UI.on('a.annNew', () => annModal());
  UI.on('a.annEdit', (el) => annModal(db().announcements.find((x) => x.id === el.dataset.id)));
  UI.on('a.annToggle', (el) => { const a = db().announcements.find((x) => x.id === el.dataset.id); a.active = el.checked; log(el.checked ? 'Activated announcement' : 'Paused announcement', a.title.en); Store.save(); toast(el.checked ? t('Announcement is now showing.') : t('Announcement hidden.'), 'success'); });
  UI.on('a.annDel', async (el) => { const a = db().announcements.find((x) => x.id === el.dataset.id); const ok = await UI.confirm({ title: t('Delete announcement?'), message: a.title.en, confirmText: t('Delete'), danger: true, icon: 'trash' }); if (!ok) return; db().announcements = db().announcements.filter((x) => x.id !== a.id); log('Deleted announcement', a.title.en); Store.save(); App.refresh(); toast(t('Deleted.'), 'success'); });

  /* ---------- Patient master list (demographics only) ---------- */
  const PER = 12;
  function ptFiltered() { const f = AS.pt; const q = f.q.toLowerCase(); return db().patients.filter((p) => (!q || Q.fullName(p).toLowerCase().includes(q) || p.code.toLowerCase().includes(q) || (p.mobile || '').includes(q)) && (!f.status || (f.status === 'verified' ? p.verified : !p.verified)) && (!f.prio || Q.priorityOf(p) === f.prio)).sort((a, b) => b.createdAt - a.createdAt); }
  function ptTable() {
    const all = ptFiltered(); const pages = Math.max(1, Math.ceil(all.length / PER)); AS.pt.page = Math.min(AS.pt.page, pages);
    const list = all.slice((AS.pt.page - 1) * PER, AS.pt.page * PER);
    if (!list.length) return UI.emptyState('users', t('No patients match'), t('Try clearing the filters.'));
    return `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Patient code'))}</th><th>${esc(t('Name'))}</th><th>${esc(t('Age / Sex'))}</th><th>${esc(t('Mobile'))}</th><th>${esc(t('Priority'))}</th><th>${esc(t('Registered'))}</th><th>${esc(t('Status'))}</th><th class="right"></th></tr></thead><tbody>
      ${list.map((p) => `<tr><td class="mono small">${esc(p.code)}</td><td><b>${esc(Q.fullName(p))}</b></td><td>${UI.ageFrom(p.bday)} · ${p.sex}</td><td class="small">${esc(UI.maskMobile(p.mobile) || '—')}</td><td>${priorityBadge(Q.priorityOf(p)) || '—'}</td><td class="small">${esc(fmtDate(UI.iso(new Date(p.createdAt)), { month: 'short', day: 'numeric', year: 'numeric' }))}</td><td>${p.verified ? statusBadge('approved').replace(t('Approved'), t('Verified')) : statusBadge('pending')}</td><td class="actions"><button class="btn btn-secondary btn-sm" data-act="a.ptView" data-id="${p.id}">${esc(t('View'))}</button></td></tr>`).join('')}</tbody></table></div>
      <div class="row-between" style="margin-top:12px"><span class="small muted">${esc(t('Showing {a}–{b} of {n}', { a: (AS.pt.page - 1) * PER + 1, b: (AS.pt.page - 1) * PER + list.length, n: all.length }))}</span><div class="row"><button class="btn btn-secondary btn-sm" data-act="a.ptPage" data-n="-1" ${AS.pt.page <= 1 ? 'disabled' : ''}>${icon('chevronLeft')}${esc(t('Previous'))}</button><span class="small strong">${AS.pt.page} / ${pages}</span><button class="btn btn-secondary btn-sm" data-act="a.ptPage" data-n="1" ${AS.pt.page >= pages ? 'disabled' : ''}>${esc(t('Next'))}${icon('chevronRight')}</button></div></div>`;
  }
  function patients() {
    const f = AS.pt;
    shell('patients', t('Patient master list'), `<div class="stack">
      <div class="alert alert-info small">${icon('lock')}<div>${esc(t('Demographic and registration data only. Clinical records can only be opened by authorized doctors.'))}</div></div>
      <div class="toolbar"><div class="input-icon">${icon('search')}<input class="input" type="search" placeholder="${attr(t('Search name, code or mobile'))}" value="${attr(f.q)}" data-input="a.ptQ" aria-label="${attr(t('Search patients'))}"></div>
        <select class="select" data-change="a.ptStatus" aria-label="${attr(t('Status'))}"><option value="">${esc(t('All statuses'))}</option><option value="verified" ${f.status === 'verified' ? 'selected' : ''}>${esc(t('Verified'))}</option><option value="pending" ${f.status === 'pending' ? 'selected' : ''}>${esc(t('Pending verification'))}</option></select>
        <select class="select" data-change="a.ptPrio" aria-label="${attr(t('Priority'))}"><option value="">${esc(t('All patients'))}</option>${[['senior', 'Senior Citizen'], ['pwd', 'PWD'], ['pregnant', 'Pregnant']].map(([k, l]) => `<option value="${k}" ${f.prio === k ? 'selected' : ''}>${esc(t(l))}</option>`).join('')}</select>
        <div class="grow"></div><button class="btn btn-secondary" data-act="a.ptExport">${icon('download')}${esc(t('Export CSV'))}</button></div>
      <div id="pt-table">${ptTable()}</div></div>`);
  }
  const ptUpd = () => { document.getElementById('pt-table').innerHTML = ptTable(); };
  UI.on('a.ptQ', (el) => { AS.pt.q = el.value; AS.pt.page = 1; ptUpd(); });
  UI.on('a.ptStatus', (el) => { AS.pt.status = el.value; AS.pt.page = 1; ptUpd(); });
  UI.on('a.ptPrio', (el) => { AS.pt.prio = el.value; AS.pt.page = 1; ptUpd(); });
  UI.on('a.ptPage', (el) => { AS.pt.page += Number(el.dataset.n); ptUpd(); });
  UI.on('a.ptExport', () => { UI.downloadCSV('dyh-patient-master-list-sample.csv', [['Code', 'Last name', 'First name', 'Middle name', 'Birthday', 'Sex', 'City', 'Verified'], ...ptFiltered().map((p) => [p.code, p.last, p.first, p.middle, p.bday, p.sex, p.address.city, p.verified ? 'Yes' : 'No'])]); log('Exported patient master list', ptFiltered().length + ' rows'); Store.save(true); toast(t('CSV downloaded.'), 'success'); });
  UI.on('a.ptView', (el) => {
    const p = Q.patient(el.dataset.id);
    UI.openModal({ title: Q.fullName(p), body: `<div class="stack"><div class="row-nowrap">${avatar(p, 56)}<div><div class="mono">${esc(p.code)}</div>${priorityBadge(Q.priorityOf(p))}</div></div>
      <dl class="grid-2" style="margin:0">${[[t('Birthday'), `${fmtDate(p.bday)} (${UI.ageFrom(p.bday)})`], [t('Sex'), p.sex === 'F' ? t('Female') : t('Male')], [t('Mobile'), UI.maskMobile(p.mobile) || '—'], [t('Address'), [p.address.street, p.address.brgy, p.address.city, p.address.province].filter(Boolean).join(', ') || '—'], [t('Emergency contact'), p.emergency.name || '—'], [t('App account'), p.hasAccount ? t('Yes') : t('No')]].map(([k, v]) => `<div><dt class="small muted strong">${esc(k)}</dt><dd style="margin:0">${esc(v)}</dd></div>`).join('')}</dl>
      <div class="alert alert-info small">${icon('lock')}<div>${esc(t('Clinical records are not accessible from the admin console.'))}</div></div></div>`, footer: `<button class="btn btn-primary" data-act="modal.close">${esc(t('Close'))}</button>` });
  });

  /* ---------- Doctor master list ---------- */
  function doctors() {
    shell('doctors', t('Doctor master list'), `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Doctor'))}</th><th>${esc(t('Specialty'))}</th><th>${esc(t('Room'))}</th><th>${esc(t('Clinic schedule'))}</th><th>${esc(t('Today'))}</th><th>${esc(t('Profile'))}</th><th class="right"></th></tr></thead><tbody>
      ${db().doctors.map((d) => `<tr><td><div class="row-nowrap">${avatar(d, 36)}<b>${esc(d.name)}</b></div></td><td class="small">${esc(Q.specName(d.spec))}</td><td>${esc(d.room)}</td><td class="small">${esc(Q.scheduleText(d))}</td><td>${statusBadge(Q.walkInStatus(d).state)}</td><td>${statusBadge(d.status)}</td><td class="actions"><button class="btn btn-secondary btn-sm" data-act="a.docEdit" data-id="${d.id}">${icon('edit')}${esc(t('Edit'))}</button></td></tr>`).join('')}</tbody></table></div>`);
  }
  UI.on('a.docEdit', (el) => {
    const d = Q.doctor(el.dataset.id);
    UI.openModal({ title: d.name, body: `<form id="doc-form" class="form-grid" novalidate>${field({ name: 'room', label: t('Room'), value: d.room, required: true })}${field({ name: 'floor', label: t('Floor / building'), value: d.floor, required: true })}${field({ name: 'status', label: t('Profile status'), type: 'select', value: d.status, options: [['active', t('Active — visible to patients')], ['inactive', t('Inactive — hidden')]], span2: true })}</form><p class="small muted">${esc(t('Clinic days and hours are managed by the doctor or their secretary.'))}</p>`, footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-primary" data-act="a.docSave" data-id="${d.id}">${esc(t('Save'))}</button>` });
  });
  UI.on('a.docSave', (el) => { const form = UI.topModal().querySelector('#doc-form'); const f = UI.formData(form); const e = {}; if (!f.room) e.room = t('Required.'); if (!f.floor) e.floor = t('Required.'); if (!UI.setErrors(form, e)) return; Object.assign(Q.doctor(el.dataset.id), f); log('Updated doctor profile', Q.doctor(el.dataset.id).name); Store.save(); UI.closeModal(); toast(t('Doctor profile updated.'), 'success'); App.refresh(); });

  /* ---------- Verification & duplicates ---------- */
  function verification() {
    const pend = db().verifications.filter((v) => v.status === 'pending');
    const dups = db().duplicates.filter((x) => x.status === 'open');
    const cmp = (a, b, k, l) => { const va = k === 'name' ? Q.fullName(a) : a[k], vb = k === 'name' ? Q.fullName(b) : b[k]; const same = String(va).toLowerCase() === String(vb).toLowerCase(); return `<tr><th style="text-transform:none;letter-spacing:0">${esc(l)}</th><td>${esc(va || '—')}</td><td>${esc(vb || '—')}</td><td>${same ? `<span class="badge badge-warning">${icon('check')}${esc(t('Same'))}</span>` : ''}</td></tr>`; };
    shell('verification', t('Verification & duplicates'), `<div class="stack-lg">
      <section><h2>${esc(t('Patient accounts awaiting verification'))}</h2>${pend.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Patient'))}</th><th>${esc(t('Request'))}</th><th>${esc(t('Details'))}</th><th>${esc(t('Submitted'))}</th><th class="right">${esc(t('Actions'))}</th></tr></thead><tbody>
        ${pend.map((v) => { const p = Q.patient(v.patientId); return `<tr><td><b>${esc(Q.fullName(p))}</b><div class="xs muted">${esc(p.code)} · ${esc(fmtDate(p.bday))} · ${esc(UI.maskMobile(p.mobile))}</div></td><td>${v.type === 'link' ? `<span class="badge badge-info">${icon('merge')}${esc(t('Link to record {code}', { code: v.requestedCode }))}</span>` : `<span class="badge badge-neutral">${icon('userPlus')}${esc(t('New registration'))}</span>`}</td><td class="small">${esc(v.note)}</td><td class="small">${esc(UI.fmtDateTime(v.at))}</td><td class="actions"><button class="btn btn-success btn-sm" data-act="a.vApprove" data-id="${v.id}">${icon('check')}${esc(t('Approve'))}</button><button class="btn btn-danger-outline btn-sm" data-act="a.vReject" data-id="${v.id}">${esc(t('Reject'))}</button></td></tr>`; }).join('')}</tbody></table></div>` : UI.emptyState('userCheck', t('All caught up'), t('No accounts are waiting for verification.'))}</section>
      <section><h2>${esc(t('Possible duplicate records'))}</h2>${dups.length ? `<div class="stack">${dups.map((x) => { const a = Q.patient(x.a), b = Q.patient(x.b); return `<article class="card stack-sm"><div class="row-between"><div class="row"><span class="badge badge-warning">${icon('merge')}${esc(t('{n}% match', { n: x.score }))}</span><span class="small muted">${esc(x.reasons.join(' · '))}</span></div></div>
        <div class="table-wrap"><table class="table"><thead><tr><th></th><th>${esc(t('Record A'))} · ${esc(a.code)}</th><th>${esc(t('Record B'))} · ${esc(b.code)}</th><th></th></tr></thead><tbody>${cmp(a, b, 'name', t('Name'))}${cmp(a, b, 'bday', t('Birthday'))}${cmp(a, b, 'sex', t('Sex'))}${cmp(a, b, 'mobile', t('Mobile'))}</tbody></table></div>
        <div class="row" style="justify-content:flex-end"><button class="btn btn-secondary btn-sm" data-act="a.dupNo" data-id="${x.id}">${esc(t('Not a duplicate'))}</button><button class="btn btn-primary btn-sm" data-act="a.dupMerge" data-id="${x.id}">${icon('merge')}${esc(t('Merge records'))}</button></div></article>`; }).join('')}</div>` : UI.emptyState('merge', t('No duplicates to review'), '')}</section>
    </div>`);
  }
  UI.on('a.vApprove', async (el) => { const v = db().verifications.find((x) => x.id === el.dataset.id); const p = Q.patient(v.patientId); const ok = await UI.confirm({ title: t('Approve {name}?', { name: Q.fullName(p) }), message: t('Confirm that the patient’s identity was checked against a valid ID.'), confirmText: t('Approve'), icon: 'userCheck' }); if (!ok) return; v.status = 'approved'; p.verified = true; MockAPI.notify(p.id, t('Account verified'), t('Your DYH account is now verified.'), true); log('Verified patient account', p.code); Store.save(); toast(t('Account verified. Patient notified by SMS.'), 'success'); App.refresh(); });
  UI.on('a.vReject', (el) => {
    const v = db().verifications.find((x) => x.id === el.dataset.id); const p = Q.patient(v.patientId);
    UI.openModal({ title: t('Reject verification'), size: 'sm', body: `<form id="rj" novalidate>${field({ name: 'reason', label: t('Reason (sent to patient)'), type: 'select', required: true, options: [['', t('Select…')], ['Details do not match hospital record', t('Details do not match hospital record')], ['Please visit the front desk with a valid ID', t('Please visit the front desk with a valid ID')], ['Duplicate account', t('Duplicate account')]] })}</form>`, footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-danger" data-act="a.vRejectGo">${esc(t('Reject'))}</button>` });
    UI.on('a.vRejectGo', () => { const f = UI.topModal().querySelector('#rj'); const r = f.reason.value; if (!r) return UI.setErrors(f, { reason: t('Please choose a reason.') }); v.status = 'rejected'; MockAPI.notify(p.id, t('Verification not approved'), t(r), true); log('Rejected patient verification', p.code); Store.save(); UI.closeModal(); toast(t('Request rejected. Patient notified.'), 'success'); App.refresh(); });
  });
  UI.on('a.dupNo', (el) => { const x = db().duplicates.find((d) => d.id === el.dataset.id); x.status = 'dismissed'; log('Marked records as not duplicate', `${Q.patient(x.a).code} / ${Q.patient(x.b).code}`); Store.save(); toast(t('Marked as not a duplicate.'), 'success'); App.refresh(); });
  UI.on('a.dupMerge', (el) => {
    const x = db().duplicates.find((d) => d.id === el.dataset.id); const a = Q.patient(x.a), b = Q.patient(x.b);
    UI.openModal({ title: t('Merge records'), body: `<div class="stack"><p>${esc(t('Choose the record to keep. Appointments, queue history and medical records from the other record will be moved to it. This cannot be undone.'))}</p>
      ${[a, b].map((p, i) => `<label class="check card" style="padding:12px"><input type="radio" name="keep" value="${p.id}" ${i === 0 ? 'checked' : ''}><span><b>${esc(Q.fullName(p))}</b> · ${esc(p.code)}<br><span class="small muted">${esc(t('Registered {d}', { d: fmtDate(UI.iso(new Date(p.createdAt))) }))}</span></span></label>`).join('')}
      <label class="check"><input type="checkbox" id="mg-ok"><span>${esc(t('I verified with Medical Records that these are the same person.'))}</span></label><div id="mg-err"></div></div>`,
    footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-danger" data-act="a.mergeGo" data-id="${x.id}">${icon('merge')}${esc(t('Merge permanently'))}</button>` });
  });
  UI.on('a.mergeGo', async (el) => {
    const m = UI.topModal(); if (!m.querySelector('#mg-ok').checked) { m.querySelector('#mg-err').innerHTML = `<div class="field-error">${icon('alert')}<span>${esc(t('Please confirm you verified the records.'))}</span></div>`; return; }
    const x = db().duplicates.find((d) => d.id === el.dataset.id); const keep = m.querySelector('[name=keep]:checked').value; const drop = keep === x.a ? x.b : x.a;
    await UI.busy(el, () => UI.wait(900), t('Merging…'));
    ['queue', 'appointments', 'records', 'notifications'].forEach((k) => db()[k].forEach((r) => { if (r.patientId === drop) r.patientId = keep; }));
    db().verifications.forEach((v) => { if (v.patientId === drop) v.status = 'approved'; });
    const dp = Q.patient(drop); dp.mergedInto = keep; db().patients = db().patients.filter((p) => p.id !== drop);
    x.status = 'merged'; log('Merged duplicate patient records', `${dp.code} → ${Q.patient(keep).code}`); Store.save(); UI.closeModal(); toast(t('Records merged.'), 'success'); App.refresh();
  });

  /* ---------- Reports ---------- */
  const REPORTS = { census: 'Daily patient census', queue: 'Queue & waiting time', appts: 'Appointments summary', productivity: 'Doctor productivity', demo: 'Patient demographics', sms: 'SMS usage' };
  function buildReport(type, from, to, spec) {
    const docs = db().doctors.filter((d) => !spec || d.spec === spec);
    const days = []; for (let d = from; d <= to && days.length < 62; d = UI.addDays(d, 1)) days.push(d);
    const seed = (s) => { let h = 0; for (const c of s) h = (h * 31 + c.charCodeAt(0)) >>> 0; return h; };
    if (type === 'census') return { head: ['Date', 'Walk-in', 'Appointment', 'Total'], rows: days.map((d) => { const w = 40 + (seed(d) % 35), a = 20 + (seed(d + 'a') % 20); return [d, w, a, w + a]; }) };
    if (type === 'queue') return { head: ['Doctor', 'Specialty', 'Patients', 'Avg wait (min)', 'Longest wait (min)'], rows: docs.map((d) => [d.name, Q.spec(d.spec).en, 20 + seed(d.id) % 60, 15 + seed(d.id + 'w') % 30, 50 + seed(d.id + 'l') % 60]) };
    if (type === 'appts') return { head: ['Doctor', 'Booked', 'Completed', 'Cancelled', 'No-show'], rows: docs.map((d) => { const b = 30 + seed(d.id) % 40; return [d.name, b, Math.round(b * 0.78), Math.round(b * 0.12), Math.round(b * 0.1)]; }) };
    if (type === 'productivity') return { head: ['Doctor', 'Clinic days', 'Patients seen', 'Avg / day', 'Records signed'], rows: docs.map((d) => { const cd = days.filter((x) => d.days.includes(UI.parseISO(x).getDay())).length; const ps = cd * (12 + seed(d.id) % 10); return [d.name, cd, ps, cd ? (ps / cd).toFixed(1) : 0, ps]; }) };
    if (type === 'demo') { const pts = db().patients; return { head: ['Group', 'Patients', 'Share'], rows: [['0–17', 0, 17], ['18–39', 18, 39], ['40–59', 40, 59], ['60+ (Senior)', 60, 200]].map(([l, a, b]) => { const n = pts.filter((p) => { const x = UI.ageFrom(p.bday); return x >= a && x <= b; }).length; return [l, n, Math.round((n / pts.length) * 100) + '%']; }) }; }
    return { head: ['Date', 'Sent', 'Delivered', 'Failed', 'Credits used'], rows: days.map((d) => { const s = 80 + seed(d) % 60, f = seed(d + 'f') % 6; return [d, s, s - f, f, s]; }) };
  }
  function reports() {
    const r = AS.report;
    shell('reports', t('Reports'), `<div class="stack-lg">
      <section class="card"><form data-submit="a.genReport" class="form-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));align-items:end" novalidate id="rp-form">
        ${field({ name: 'type', label: t('Report'), type: 'select', value: r ? r.type : 'census', options: Object.entries(REPORTS).map(([k, l]) => [k, t(l)]) })}
        ${field({ name: 'from', label: t('From'), type: 'date', value: r ? r.from : UI.addDays(UI.today(), -6), required: true })}
        ${field({ name: 'to', label: t('To'), type: 'date', value: r ? r.to : UI.today(), required: true, attrs: `max="${UI.today()}"` })}
        ${field({ name: 'spec', label: t('Clinic'), type: 'select', value: r ? r.spec : '', options: [['', t('All clinics')], ...Q.SPECIALTIES.map((s) => [s.id, I18N.pick(s)])] })}
        <div class="span-2 row"><button class="btn btn-primary" type="submit">${icon('chart')}${esc(t('Generate report'))}</button></div></form></section>
      <div id="rp-out">${r && r.data ? reportOut(r) : UI.emptyState('file', t('No report generated yet'), t('Choose a report and date range, then press Generate.'))}</div></div>
      <style>@media(max-width:900px){#rp-form{grid-template-columns:1fr 1fr!important}}</style>`);
  }
  function reportOut(r) {
    return `<section class="card"><div class="card-hd"><div><h3 style="margin:0">${esc(t(REPORTS[r.type]))}</h3><div class="small muted">${esc(fmtDate(r.from))} – ${esc(fmtDate(r.to))} · <span class="demo-tag">${esc(t('simulated figures'))}</span></div></div><div class="row"><button class="btn btn-secondary btn-sm" data-act="a.rpPrint">${icon('printer')}${esc(t('Print'))}</button><button class="btn btn-primary btn-sm" data-act="a.rpCsv">${icon('download')}${esc(t('Export CSV'))}</button></div></div>
      <div class="table-wrap"><table class="table"><thead><tr>${r.data.head.map((h) => `<th>${esc(t(h))}</th>`).join('')}</tr></thead><tbody>${r.data.rows.map((row) => `<tr>${row.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div></section>`;
  }
  UI.on('a.genReport', async (form) => {
    const f = UI.formData(form);
    if (!f.from || !f.to) { const e = {}; if (!f.from) e.from = t('Required.'); if (!f.to) e.to = t('Required.'); return UI.setErrors(form, e); }
    if (f.to < f.from) return UI.setErrors(form, { to: t('“To” date must be on or after the “From” date.') });
    document.getElementById('rp-out').innerHTML = UI.loading(t('Generating report…'));
    await UI.busy(form.querySelector('button[type=submit]'), () => UI.wait(900), t('Generating…'));
    AS.report = { ...f, data: buildReport(f.type, f.from, f.to, f.spec) };
    log('Generated report', REPORTS[f.type]); Store.save(true);
    document.getElementById('rp-out').innerHTML = reportOut(AS.report);
  });
  UI.on('a.rpCsv', () => { const r = AS.report; UI.downloadCSV(`dyh-${r.type}-${r.from}-to-${r.to}-sample.csv`, [r.data.head, ...r.data.rows]); toast(t('CSV downloaded.'), 'success'); });
  UI.on('a.rpPrint', () => window.print());

  /* ---------- AI analytics (simulated) ---------- */
  function analytics() {
    const fc = Array.from({ length: 14 }, (_, i) => { const d = UI.addDays(UI.today(), i + 1); const dow = UI.parseISO(d).getDay(); return { l: UI.fmtDate(d, { month: 'short', day: 'numeric' }), v: dow === 0 ? 18 : [0, 168, 142, 150, 138, 131, 96][dow] + (i % 3) * 4 }; });
    const peak = [{ l: '7–8 AM', v: 38 }, { l: '8–9 AM', v: 64 }, { l: '9–10 AM', v: 71 }, { l: '10–11 AM', v: 52 }, { l: '1–2 PM', v: 44 }, { l: '2–3 PM', v: 29 }];
    shell('analytics', t('AI analytics'), `<div class="stack-lg">
      <div class="alert alert-warning">${icon('sparkles')}<div><div class="alert-title">${esc(t('AI-generated insights — for planning only'))}</div>${esc(t('These insights are generated from sample data in this demo. Always verify with actual reports before making operational decisions. No patient-identifying data is used.'))}</div></div>
      <div class="toolbar"><div class="grow"></div><button class="btn btn-secondary" data-act="a.aiRefresh">${icon('refresh')}${esc(t('Regenerate insights'))}</button></div>
      <div class="grid-3" id="ai-cards">${[
        ['clock', 'Peak hours', 'Most walk-ins arrive between 8:00 and 10:00 AM. Consider assigning an extra front-desk staff member to the kiosk area during this window.'],
        ['users', 'Demand by clinic', 'Internal Medicine and Family Medicine account for 46% of visits this month. Family Medicine walk-in slots ran out before 11 AM on 3 of the last 5 clinic days.'],
        ['ban', 'No-show risk', 'Appointments booked more than 14 days ahead have a 2.3× higher no-show rate. Adding a second reminder 2 hours before may help.'],
        ['senior', 'Priority patients', '41% of queue entries are Senior Citizens. Average wait for priority patients is 18 minutes versus 34 minutes for regular patients.'],
        ['star', 'Satisfaction', 'Ratings dip on Mondays (average 3.6). Comments most often mention long waiting times and limited seating.'],
        ['message', 'SMS usage', 'At the current rate, SMS credits will last about 21 days. Plan a top-up before the end of the month.'],
      ].map(([ic, h, b]) => `<section class="card stack-sm"><div class="row-nowrap"><span class="role-ic" style="width:44px;height:44px">${icon(ic)}</span><h3 style="margin:0">${esc(t(h))}</h3></div><p class="small" style="margin:0">${esc(t(b))}</p></section>`).join('')}</div>
      <div class="grid-2"><section class="card"><h3>${esc(t('Forecast: expected outpatient visits (next 14 days)'))}</h3>${UI.lineChart(fc, { label: t('Forecast of outpatient visits') })}</section>
        <section class="card"><h3>${esc(t('Average walk-in arrivals by hour'))}</h3>${UI.hbars(peak)}</section></div>
    </div>`);
  }
  UI.on('a.aiRefresh', async (el) => { const c = document.getElementById('ai-cards'); const html = c.innerHTML; c.innerHTML = `<div class="card" style="grid-column:1/-1">${UI.loading(t('Analyzing operations data…'))}</div>`; await UI.busy(el, () => UI.wait(1300), t('Analyzing…')); c.innerHTML = html; toast(t('Insights updated.'), 'success'); });

  /* ---------- Feedback ---------- */
  function feedback() {
    const all = db().feedback; const f = AS.fb;
    const list = all.filter((x) => (!f.src || x.source === f.src) && (!f.rating || String(x.rating) === f.rating));
    const avg = all.length ? (all.reduce((s, x) => s + x.rating, 0) / all.length).toFixed(1) : '—';
    shell('feedback', t('Ratings & feedback'), `<div class="stack-lg">
      <div class="grid" style="grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:20px" id="fb-grid">
        <section class="card center"><div class="stat-label" style="justify-content:center">${esc(t('Average rating'))}</div><div style="font-size:3.5em;font-weight:800;color:var(--navy);line-height:1.1">${avg}</div><div style="color:var(--warning);font-size:1.4em" aria-hidden="true">${'★'.repeat(Math.round(avg))}${'☆'.repeat(5 - Math.round(avg))}</div><div class="small muted">${esc(t('{n} ratings', { n: all.length }))}</div></section>
        <section class="card"><h3>${esc(t('Rating distribution'))}</h3>${UI.hbars([5, 4, 3, 2, 1].map((n) => ({ l: `${n} ★`, v: all.filter((x) => x.rating === n).length })))}</section></div>
      <div class="toolbar"><select class="select" data-change="a.fbSrc" aria-label="${attr(t('Source'))}"><option value="">${esc(t('All sources'))}</option><option value="kiosk" ${f.src === 'kiosk' ? 'selected' : ''}>${esc(t('Kiosk'))}</option><option value="app" ${f.src === 'app' ? 'selected' : ''}>${esc(t('Patient app'))}</option></select>
        <select class="select" data-change="a.fbRating" aria-label="${attr(t('Rating'))}"><option value="">${esc(t('All ratings'))}</option>${[5, 4, 3, 2, 1].map((n) => `<option value="${n}" ${f.rating === String(n) ? 'selected' : ''}>${n} ★</option>`).join('')}</select><span class="small muted">${esc(t('{n} result(s)', { n: list.length }))}</span></div>
      ${list.length ? `<div class="card" style="padding:0">${list.slice(0, 30).map((x) => `<div class="q-row"><span class="badge ${x.rating >= 4 ? 'badge-success' : x.rating === 3 ? 'badge-warning' : 'badge-error'}" aria-label="${attr(x.rating + ' stars')}">${'★'.repeat(x.rating)}</span><div class="grow">${x.comment ? esc(x.comment) : `<span class="muted">${esc(t('No comment'))}</span>`}<div class="xs muted">${esc(UI.fmtDateTime(x.at))} · ${esc(t(x.source === 'kiosk' ? 'Kiosk' : 'Patient app'))}</div></div>${x.reviewed ? `<span class="badge badge-neutral">${icon('check')}${esc(t('Reviewed'))}</span>` : `<button class="btn btn-ghost btn-sm" data-act="a.fbRev" data-id="${x.id}">${esc(t('Mark reviewed'))}</button>`}</div>`).join('')}</div>` : UI.emptyState('star', t('No feedback matches'), '')}
    </div><style>@media(max-width:900px){#fb-grid{grid-template-columns:1fr!important}}</style>`);
  }
  UI.on('a.fbSrc', (el) => { AS.fb.src = el.value; App.refresh(); });
  UI.on('a.fbRating', (el) => { AS.fb.rating = el.value; App.refresh(); });
  UI.on('a.fbRev', (el) => { db().feedback.find((x) => x.id === el.dataset.id).reviewed = true; Store.save(); App.refresh(); });

  /* ---------- Audit logs ---------- */
  function auditList() { const f = AS.audit; const q = f.q.toLowerCase(); return db().audit.filter((l) => (!f.role || l.role === f.role) && (!q || (l.user + l.action + l.target).toLowerCase().includes(q))); }
  function auditTable() { const list = auditList().slice(0, 100); return list.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Time'))}</th><th>${esc(t('User'))}</th><th>${esc(t('Role'))}</th><th>${esc(t('Action'))}</th><th>${esc(t('Target'))}</th></tr></thead><tbody>${list.map((l) => `<tr><td class="small nowrap">${esc(UI.fmtDateTime(l.at))}</td><td><b>${esc(l.user)}</b></td><td><span class="badge badge-neutral">${esc(l.role)}</span></td><td>${esc(l.action)}</td><td class="small">${esc(l.target)}</td></tr>`).join('')}</tbody></table></div>` : UI.emptyState('shield', t('No log entries match'), ''); }
  function audit() {
    const f = AS.audit;
    shell('audit', t('Audit logs'), `<div class="stack"><div class="alert alert-info small">${icon('shield')}<div>${esc(t('Audit logs are read-only and cannot be edited or deleted. Every view of a medical record is recorded.'))}</div></div>
      <div class="toolbar"><div class="input-icon">${icon('search')}<input class="input" type="search" placeholder="${attr(t('Search user, action or target'))}" value="${attr(f.q)}" data-input="a.auQ" aria-label="${attr(t('Search logs'))}"></div>
        <select class="select" data-change="a.auRole" aria-label="${attr(t('Role'))}"><option value="">${esc(t('All roles'))}</option>${['admin', 'doctor', 'secretary', 'frontdesk', 'patient', 'kiosk', 'system'].map((r) => `<option value="${r}" ${f.role === r ? 'selected' : ''}>${r}</option>`).join('')}</select><div class="grow"></div>
        <button class="btn btn-secondary" data-act="a.auCsv">${icon('download')}${esc(t('Export CSV'))}</button></div><div id="au-table">${auditTable()}</div></div>`, true);
  }
  UI.on('a.auQ', (el) => { AS.audit.q = el.value; document.getElementById('au-table').innerHTML = auditTable(); });
  UI.on('a.auRole', (el) => { AS.audit.role = el.value; document.getElementById('au-table').innerHTML = auditTable(); });
  UI.on('a.auCsv', () => { UI.downloadCSV('dyh-audit-log-sample.csv', [['Time', 'User', 'Role', 'Action', 'Target'], ...auditList().map((l) => [new Date(l.at).toISOString(), l.user, l.role, l.action, l.target])]); toast(t('CSV downloaded.'), 'success'); });

  /* ---------- SMS monitoring ---------- */
  function sms() {
    const S = db().sms; const f = AS.sms;
    const list = S.log.filter((x) => !f.status || x.status === f.status);
    const del = S.log.filter((x) => x.status === 'delivered').length, fail = S.log.filter((x) => x.status === 'failed').length, que = S.log.filter((x) => x.status === 'queued').length;
    const low = S.credits < db().settings.smsLowCredit;
    shell('sms', t('SMS monitoring'), `<div class="stack-lg">
      <div class="grid-4"><div class="stat" ${low ? 'style="border-color:var(--error)"' : ''}><div class="stat-label">${icon('message')}${esc(t('Credits remaining'))}</div><div class="stat-value">${S.credits.toLocaleString()}</div><div class="stat-sub">${low ? `<span class="badge badge-error">${icon('alert')}${esc(t('Low'))}</span>` : esc(t('Alert below {n}', { n: db().settings.smsLowCredit }))}</div></div>
        <div class="stat"><div class="stat-label">${icon('checkCircle')}${esc(t('Delivered'))}</div><div class="stat-value">${S.log.length ? Math.round((del / S.log.length) * 100) : 0}%</div><div class="stat-sub">${del} ${esc(t('messages'))}</div></div>
        <div class="stat"><div class="stat-label">${icon('xCircle')}${esc(t('Failed'))}</div><div class="stat-value">${fail}</div></div>
        <div class="stat"><div class="stat-label">${icon('clock')}${esc(t('Queued'))}</div><div class="stat-value">${que}</div></div></div>
      <div class="toolbar"><select class="select" data-change="a.smsStatus" aria-label="${attr(t('Status'))}"><option value="">${esc(t('All statuses'))}</option>${['delivered', 'failed', 'queued'].map((s) => `<option value="${s}" ${f.status === s ? 'selected' : ''}>${esc(t(s[0].toUpperCase() + s.slice(1)))}</option>`).join('')}</select><div class="grow"></div>
        <button class="btn btn-secondary" data-act="a.smsRetry" ${fail ? '' : 'disabled'}>${icon('refresh')}${esc(t('Retry failed ({n})', { n: fail }))}</button><button class="btn btn-primary" data-act="a.smsTopup">${icon('plus')}${esc(t('Request credit top-up'))}</button></div>
      ${list.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Time'))}</th><th>${esc(t('Recipient'))}</th><th>${esc(t('Type'))}</th><th>${esc(t('Message'))}</th><th>${esc(t('Status'))}</th></tr></thead><tbody>${list.slice(0, 60).map((x) => `<tr><td class="small nowrap">${esc(UI.fmtDateTime(x.at))}</td><td class="mono small">${esc(UI.maskMobile(x.to))}</td><td><span class="badge badge-neutral">${esc(x.type)}</span></td><td class="small">${esc(x.msg.length > 70 ? x.msg.slice(0, 70) + '…' : x.msg)}</td><td>${statusBadge(x.status)}</td></tr>`).join('')}</tbody></table></div>` : UI.emptyState('message', t('No messages'), '')}
      <p class="xs muted">${esc(t('Demo: messages are simulated. No real SMS is sent.'))}</p></div>`, true);
  }
  UI.on('a.smsStatus', (el) => { AS.sms.status = el.value; App.refresh(); });
  UI.on('a.smsRetry', async (el) => { await UI.busy(el, () => UI.wait(1000), t('Retrying…')); let n = 0; db().sms.log.forEach((x) => { if (x.status === 'failed') { x.status = 'delivered'; n++; db().sms.credits--; } }); log('Retried failed SMS', n + ' messages'); Store.save(); toast(t('{n} message(s) resent successfully.', { n }), 'success'); App.refresh(); });
  UI.on('a.smsTopup', async () => { const ok = await UI.confirm({ title: t('Request SMS top-up?'), message: t('A purchase request for 5,000 credits will be sent to Accounting for approval.'), confirmText: t('Send request'), icon: 'message' }); if (!ok) return; log('Requested SMS credit top-up', '5,000 credits'); Store.save(true); toast(t('Top-up request sent to Accounting (simulated).'), 'success'); });

  /* ---------- Settings ---------- */
  function settings() {
    const s = db().settings;
    const num = (name, label, hint, min, max) => field({ name, label, type: 'number', value: s[name], hint, attrs: `min="${min}" max="${max}" required` });
    const sw = (name, label) => `<label class="switch"><input type="checkbox" name="${name}" ${s[name] ? 'checked' : ''}><span class="track"></span><span>${esc(label)}</span></label>`;
    shell('settings', t('System settings'), `<form data-submit="a.saveSettings" class="stack-lg" novalidate id="set-form">
      <section class="card stack"><h2>${icon('calendar')} ${esc(t('Booking rules'))}</h2><div class="form-grid">
        ${num('bookingWindowDays', t('How far ahead patients can book (days)'), t('1–90 days'), 1, 90)}
        ${num('cancelCutoffHours', t('Cancel / reschedule cut-off (hours before visit)'), t('0–48 hours'), 0, 48)}
        ${num('maxActiveAppts', t('Max upcoming appointments per patient'), t('1–10'), 1, 10)}
        <div class="field"><label>${esc(t('Same-day booking'))}</label>${sw('sameDayBooking', t('Allow booking for today'))}</div></div></section>
      <section class="card stack"><h2>${icon('list')} ${esc(t('Queue settings'))}</h2><div class="form-grid">
        ${field({ name: 'queueMode', label: t('Priority handling'), type: 'select', value: s.queueMode, options: [['priorityFirst', t('Priority patients first (Senior, PWD, Pregnant)')], ['none', t('Strict arrival order')]] })}
        ${num('approachingAt', t('“Turn approaching” alert when this many are ahead'), t('1–10'), 1, 10)}
        ${num('avgConsultMins', t('Average consultation time used for estimates (min)'), t('5–60'), 5, 60)}</div></section>
      <section class="card stack"><h2>${icon('monitor')} ${esc(t('Kiosk'))}</h2><div class="form-grid">
        ${num('kioskTimeout', t('Inactivity timeout (seconds)'), t('30–600. Kiosk returns to the welcome screen and clears data.'), 30, 600)}
        ${num('kioskWarn', t('Show warning this many seconds before reset'), t('5–60'), 5, 60)}
        <div class="field"><label>${esc(t('Feedback'))}</label>${sw('feedbackOnKiosk', t('Show “Rate your experience” on welcome screen'))}</div></div></section>
      <section class="card stack"><h2>${icon('message')} ${esc(t('Reminders & SMS'))}</h2><div class="form-grid">
        ${num('reminderHours', t('Send appointment reminder (hours before)'), t('1–72'), 1, 72)}
        ${num('smsLowCredit', t('Low SMS credit alert threshold'), '', 0, 100000)}
        <div class="field"><label>${esc(t('SMS'))}</label>${sw('smsEnabled', t('Send SMS notifications'))}</div></div></section>
      <section class="card stack"><h2>${icon('shield')} ${esc(t('Roles & data access'))}</h2><p class="small muted" style="margin:0">${esc(t('Fixed by hospital security policy. Changes require approval from the Data Protection Officer.'))}</p>
        <div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Capability'))}</th><th>${esc(t('Doctor'))}</th><th>${esc(t('Secretary'))}</th><th>${esc(t('Front desk'))}</th><th>${esc(t('Admin'))}</th></tr></thead><tbody>
        ${[['View clinical records', 1, 0, 0, 0], ['Create & sign consultation records', 1, 0, 0, 0], ['Prepare draft notes / vitals', 1, 1, 0, 0], ['Manage queue', 1, 1, 1, 0], ['Manage appointments', 1, 1, 1, 0], ['Register patients', 0, 1, 1, 0], ['Manage accounts & settings', 0, 0, 0, 1], ['View audit logs', 0, 0, 0, 1]].map(([c, ...v]) => `<tr><td>${esc(t(c))}</td>${v.map((x) => `<td>${x ? `<span class="badge badge-success">${icon('check')}${esc(t('Yes'))}</span>` : `<span class="badge badge-neutral">${icon('x')}${esc(t('No'))}</span>`}</td>`).join('')}</tr>`).join('')}</tbody></table></div></section>
      <div class="row" style="justify-content:flex-end;position:sticky;bottom:0;background:var(--gray-50);padding:12px 0"><button type="button" class="btn btn-secondary" data-act="a.setReset">${esc(t('Undo changes'))}</button><button class="btn btn-primary btn-lg" type="submit">${icon('check')}${esc(t('Save settings'))}</button></div>
    </form>`);
  }
  UI.on('a.setReset', () => { App.refresh(); toast(t('Changes discarded.'), 'success'); });
  UI.on('a.saveSettings', async (form) => {
    const f = UI.formData(form); const e = {};
    form.querySelectorAll('input[type=number]').forEach((i) => { const v = Number(i.value); if (i.value === '' || isNaN(v) || v < Number(i.min) || v > Number(i.max)) e[i.name] = t('Enter a number from {a} to {b}.', { a: i.min, b: i.max }); });
    if (!e.kioskWarn && !e.kioskTimeout && Number(f.kioskWarn) >= Number(f.kioskTimeout)) e.kioskWarn = t('The warning must come before the timeout.');
    if (!UI.setErrors(form, e)) { toast(t('Please fix the highlighted fields.'), 'error'); return; }
    const ok = await UI.confirm({ title: t('Save system settings?'), message: t('Changes apply immediately to the patient app, kiosks and clinics.'), confirmText: t('Save settings'), icon: 'settings' });
    if (!ok) return;
    const s = db().settings; const changed = [];
    Object.keys(f).forEach((k) => { if (!(k in s)) return; const nv = typeof s[k] === 'number' ? Number(f[k]) : f[k]; if (nv !== s[k]) { changed.push(k); s[k] = nv; } });
    log('Updated system settings', changed.join(', ') || 'no changes'); Store.save();
    toast(changed.length ? t('Settings saved ({n} changed).', { n: changed.length }) : t('No changes to save.'), 'success');
  });

  function route(parts) {
    if (!user()) return App.staffLogin({ role: ROLE, title: t('System Administration'), username: 'cbernardo', onLogin: () => { db().session.staff[ROLE] = 'u8'; MockAPI.log('Admin — Carlo Bernardo', ROLE, 'Signed in', 'Admin console'); Store.save(true); route(parts); } });
    const pages = { queues, accounts, clinics, announcements, patients, doctors, verification, reports, analytics, feedback, audit, sms, settings };
    return (pages[parts[0]] || overview)();
  }
  Router.add('admin', route);
})();
