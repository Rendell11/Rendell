/* ==========================================================================
   DYH ERM — Doctor application
   Clinical documentation stays under the doctor's control: AI/transcription
   output is always an editable draft and nothing is final until Save & Sign.
   ========================================================================== */
(function () {
  'use strict';
  const { icon, esc, attr, avatar, statusBadge, priorityBadge, toast, field, fmtTime, fmtDate, fmtDateShort } = UI;
  const db = () => Store.db;
  const ROLE = 'doctor';
  const user = () => db().accounts.find((a) => a.id === db().session.staff[ROLE]);
  const me = () => Q.doctor(user().doctorId);
  const DS = { queueTab: 'waiting', patientQ: '', forms: {}, reportDays: 7 };
  const LABS = ['CBC', 'Urinalysis', 'Fasting blood sugar', 'HbA1c', 'Lipid profile', 'Serum creatinine', 'SGPT / ALT', 'Serum electrolytes', '12-lead ECG', 'Chest X-ray PA', 'Ultrasound — whole abdomen', 'Thyroid panel (TSH, FT4)'];

  function templates() { const d = me(); if (!db().templates[d.id]) db().templates[d.id] = { dx: [], rx: [] }; return db().templates[d.id]; }

  /* ---------- Shell ---------- */
  function shell(page, title, content, live, topActions) {
    const u = user(); const d = me();
    const waiting = Q.waitingOrdered(d.id).length;
    App.mount(App.staffShell({
      role: ROLE, roleLabel: t('Doctor Workspace'), user: { name: u.name, sub: Q.specName(d.spec) + ' · ' + t('Room') + ' ' + d.room }, active: '#/doctor/' + page, title,
      nav: [
        { group: 'Today', items: [
          { href: '#/doctor/dashboard', icon: 'home', label: 'Dashboard' },
          { href: '#/doctor/queue', icon: 'list', label: 'Patient queue', count: waiting || '' },
        ] },
        { group: 'Patients', items: [
          { href: '#/doctor/patients', icon: 'users', label: 'My patients' },
          { href: '#/doctor/templates', icon: 'bookmark', label: 'Favorites & templates' },
        ] },
        { group: 'Clinic', items: [
          { href: '#/doctor/schedule', icon: 'calendar', label: 'Clinic schedule' },
          { href: '#/doctor/reports', icon: 'chart', label: 'Reports' },
        ] },
      ],
      topActions, content,
    }), () => Router.render(), live);
  }

  /* ---------- Dashboard ---------- */
  function dashboard() {
    const d = me(); const all = Q.todaysQueue(d.id);
    const appts = db().appointments.filter((a) => a.doctorId === d.id && a.date === UI.today() && a.status !== 'cancelled').sort((a, b) => a.time.localeCompare(b.time));
    const drafts = db().drafts.filter((x) => x.doctorId === d.id && x.status === 'pending');
    const fu = db().appointments.filter((a) => a.doctorId === d.id && a.type === 'follow-up' && a.date > UI.today() && a.status !== 'cancelled').length;
    const w = Q.waitingOrdered(d.id).slice(0, 5);
    const h = new Date().getHours();
    shell('dashboard', t('Dashboard'), `<div class="stack-lg">
      <div class="page-hd"><div><h2 style="margin:0">${esc(h < 12 ? t('Good morning') : h < 18 ? t('Good afternoon') : t('Good evening'))}, ${esc(d.name.split(' ').slice(0, 2).join(' '))}</h2><p>${esc(UI.fmtDay(UI.today()))} · ${Q.isClinicDay(d, UI.today()) ? esc(t('Clinic today {a}–{b}, Room {room}', { a: fmtTime(d.start), b: fmtTime(d.end), room: d.room })) : esc(t('No clinic scheduled today'))}</p></div>
        <a class="btn btn-secondary" href="#/doctor/schedule">${icon('calendar')}${esc(t('Manage schedule'))}</a></div>
      <div class="grid-4">
        <div class="stat"><div class="stat-label">${icon('clock')}${esc(t('Waiting now'))}</div><div class="stat-value">${all.filter((q) => q.status === 'waiting').length}</div><div class="stat-sub">${esc(t('{n} priority', { n: Q.waitingOrdered(d.id).filter((q) => Q.priorityOf(Q.patient(q.patientId))).length }))}</div></div>
        <div class="stat"><div class="stat-label">${icon('checkCircle')}${esc(t('Seen today'))}</div><div class="stat-value">${all.filter((q) => q.status === 'done').length}</div></div>
        <div class="stat"><div class="stat-label">${icon('calendar')}${esc(t('Appointments today'))}</div><div class="stat-value">${appts.length}</div><div class="stat-sub">${esc(t('{n} arrived', { n: appts.filter((a) => a.status === 'arrived').length }))}</div></div>
        <div class="stat"><div class="stat-label">${icon('bookmark')}${esc(t('Upcoming follow-ups'))}</div><div class="stat-value">${fu}</div></div>
      </div>
      <div class="grid" style="grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:20px" id="dash-grid">
        <div class="stack">${StaffQueue.nowServingPanel(d.id, { openConsult: true })}
          ${drafts.length ? `<section class="card"><div class="card-hd"><h3>${esc(t('Secretary drafts to review'))}</h3><span class="badge badge-warning">${drafts.length}</span></div><ul class="list small">${drafts.map((x) => `<li class="row-between"><span><b>${esc(Q.shortName(Q.patient(x.patientId)))}</b> — ${esc(x.complaint)}</span><a class="btn btn-secondary btn-sm" href="#/doctor/consult/${x.patientId}">${esc(t('Open'))}</a></li>`).join('')}</ul></section>` : ''}</div>
        <div class="stack">
          <section><div class="row-between" style="margin-bottom:8px"><h3 style="margin:0">${esc(t('Next in queue'))}</h3><a class="small strong" href="#/doctor/queue">${esc(t('Full queue'))}</a></div>${w.length ? StaffQueue.waitingList(d.id, { doctor: true }) : UI.emptyState('users', t('No patients waiting'), '')}</section>
          <section class="card"><div class="card-hd"><h3>${esc(t('Today’s appointments'))}</h3></div>${appts.length ? `<ul class="list">${appts.map((a) => `<li class="row-between"><div class="row-nowrap"><b class="nowrap" style="min-width:76px">${esc(fmtTime(a.time))}</b><div><b>${esc(Q.shortName(Q.patient(a.patientId)))}</b><div class="xs muted">${esc(a.reason || '')}</div></div></div>${statusBadge(a.status)}</li>`).join('')}</ul>` : `<p class="muted small">${esc(t('No appointments today.'))}</p>`}</section>
        </div></div>
    </div><style>@media(max-width:1100px){#dash-grid{grid-template-columns:1fr!important}}</style>`, true);
  }

  /* ---------- Queue ---------- */
  function queuePage() {
    const d = me(); const all = Q.todaysQueue(d.id);
    const tabs = [['waiting', 'Waiting'], ['serving', 'In consultation'], ['done', 'Done'], ['skipped', 'Skipped'], ['all', 'All']];
    let list = DS.queueTab === 'all' ? all.slice().sort((a, b) => a.seq - b.seq) : DS.queueTab === 'waiting' ? Q.waitingOrdered(d.id) : all.filter((q) => q.status === DS.queueTab);
    shell('queue', t('Patient queue'), `<div class="stack">
      ${StaffQueue.queueStats(d.id)}
      <div class="tabs" role="tablist">${tabs.map(([k, l]) => `<button role="tab" aria-selected="${DS.queueTab === k}" data-act="d.qtab" data-k="${k}">${esc(t(l))} (${k === 'all' ? all.length : all.filter((q) => q.status === k).length})</button>`).join('')}</div>
      ${list.length ? `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('No.'))}</th><th>${esc(t('Patient'))}</th><th>${esc(t('Priority'))}</th><th>${esc(t('Source'))}</th><th>${esc(t('Status'))}</th><th>${esc(t('Arrived'))}</th><th class="right">${esc(t('Actions'))}</th></tr></thead><tbody>
        ${list.map((q) => { const p = Q.patient(q.patientId); return `<tr><td class="q-no">${esc(q.no)}</td><td><b>${esc(Q.fullName(p))}</b><div class="xs muted">${esc(p.code)} · ${UI.ageFrom(p.bday)} ${esc(t('yrs'))} · ${p.sex}</div></td><td>${priorityBadge(Q.priorityOf(p)) || '<span class="muted small">—</span>'}</td><td>${StaffQueue.sourceBadge(q.source)}</td><td>${statusBadge(q.status)}</td><td class="small nowrap">${esc(UI.fmtClock(q.createdAt))}</td>
          <td class="actions">${q.status === 'waiting' ? `<button class="btn btn-primary btn-sm" data-act="sq.start" data-id="${q.id}">${icon('play')}${esc(t('Call in'))}</button>` : ''}${q.status === 'skipped' ? `<button class="btn btn-warning btn-sm" data-act="sq.callback" data-id="${q.id}">${esc(t('Call back'))}</button>` : ''}<a class="btn btn-secondary btn-sm" href="#/doctor/consult/${p.id}">${icon('clipboard')}${esc(q.status === 'done' ? t('Notes') : t('Consult'))}</a></td></tr>`; }).join('')}
      </tbody></table></div>` : UI.emptyState('list', t('Nothing here'), t('No patients with this status today.'))}
    </div>`, true);
  }
  UI.on('d.qtab', (el) => { DS.queueTab = el.dataset.k; App.refresh(); });

  /* ---------- Consultation form ---------- */
  function formFor(pid) {
    if (DS.forms[pid]) return DS.forms[pid];
    const draft = db().drafts.find((x) => x.patientId === pid && x.doctorId === me().id && x.status === 'pending');
    const saved = db().records.find((r) => r.patientId === pid && r.doctorId === me().id && r.status === 'draft');
    const f = saved ? JSON.parse(JSON.stringify(saved)) : { id: UI.uid('r'), patientId: pid, doctorId: me().id, date: UI.today(), status: 'draft', complaint: draft ? draft.complaint : '', vitals: draft ? { ...draft.vitals } : {}, findings: '', diagnosis: [], rx: [], labs: [], notes: draft && draft.notes ? 'Secretary note: ' + draft.notes : '', fromDraft: draft ? draft.id : null };
    f.fu = f.fu || { on: false, date: '', time: '' };
    f.ai = null; f.otherLab = '';
    DS.forms[pid] = f; return f;
  }
  function consult(pid) {
    const p = Q.patient(pid);
    if (!p) return shell('queue', t('Consultation'), UI.emptyState('user', t('Patient not found'), ''));
    const f = formFor(pid); const d = me();
    const q = Q.todaysQueue(d.id).find((x) => x.patientId === pid && x.status !== 'cancelled');
    const history = db().records.filter((r) => r.patientId === pid && r.status === 'signed').sort((a, b) => b.date.localeCompare(a.date));
    const draft = f.fromDraft && db().drafts.find((x) => x.id === f.fromDraft);
    const tpl = templates();
    const v = f.vitals || {};
    const fuDates = Q.nextClinicDays(d, UI.addDays(UI.today(), 1), 10);
    const fuSlots = f.fu.date ? Q.slotsFor(d.id, f.fu.date).filter((s) => !s.taken) : [];
    if (!DS.logged) DS.logged = new Set();
    if (!DS.logged.has(pid)) { DS.logged.add(pid); MockAPI.log(user().name, ROLE, 'Opened patient for consultation', 'Patient ' + p.code); Store.save(true); }
    shell('queue', t('Consultation'), `<div class="stack-lg">
      <section class="card row-between" style="align-items:flex-start">
        <div class="row-nowrap" style="align-items:flex-start">${avatar(p, 64)}<div><h2 style="margin:0">${esc(Q.fullName(p))}</h2>
          <div class="small muted">${esc(p.code)} · ${UI.ageFrom(p.bday)} ${esc(t('years'))} · ${p.sex === 'F' ? esc(t('Female')) : esc(t('Male'))} · ${esc(t('Born'))} ${esc(fmtDate(p.bday))}</div>
          <div class="row" style="margin-top:8px">${q ? `<span class="badge badge-navy">${esc(q.no)}</span>${statusBadge(q.status)}` : ''}${priorityBadge(Q.priorityOf(p))}${p.allergies ? `<span class="badge badge-error">${icon('alert')}${esc(t('Allergy'))}: ${esc(p.allergies)}</span>` : `<span class="badge badge-neutral">${esc(t('No known allergies recorded'))}</span>`}</div></div></div>
        <div class="row"><button class="btn btn-secondary" data-act="d.history" data-id="${pid}">${icon('file')}${esc(t('History'))} (${history.length})</button>${q && q.status === 'serving' ? `<button class="btn btn-soft" data-act="sq.next" data-doc="${d.id}">${icon('checkCircle')}${esc(t('Done / Next'))}</button>` : ''}</div>
      </section>
      ${draft ? `<div class="alert alert-warning">${icon('edit')}<div><div class="alert-title">${esc(t('Pre-filled from secretary draft'))}</div>${esc(t('{by} recorded the complaint and vital signs at {time}. Please verify before signing.', { by: draft.by, time: UI.fmtClock(draft.at) }))}</div></div>` : ''}
      ${f.status === 'signed' ? `<div class="alert alert-success">${icon('signature')}<div>${esc(t('This record was signed on {when}. Signed records cannot be edited; add an addendum in a new consultation.', { when: UI.fmtDateTime(f.signedAt) }))}</div></div>` : ''}
      <div class="grid" style="grid-template-columns:minmax(0,8fr) minmax(0,4fr);gap:20px" id="c-grid">
      <form class="stack" id="cform" novalidate data-submit="d.noop">
        <section class="card stack"><h3>${esc(t('Chief complaint'))}</h3>${field({ name: 'complaint', label: t('Reason for visit'), type: 'textarea', value: f.complaint, required: true, attrs: 'data-input="d.cf" rows="2"' })}
          <div><div class="label" style="margin-bottom:6px">${esc(t('Vital signs'))}</div><div class="grid-4">${[['bp', 'BP (mmHg)'], ['hr', 'HR (bpm)'], ['temp', 'Temp (°C)'], ['rr', 'RR (/min)'], ['wt', 'Weight (kg)'], ['ht', 'Height (cm)'], ['o2', 'SpO₂ (%)']].map(([k, l]) => `<div class="field"><label for="v-${k}" class="small">${esc(t(l))}</label><input id="v-${k}" class="input" name="v.${k}" value="${attr(v[k] || '')}" data-input="d.cf"></div>`).join('')}</div></div></section>

        <section class="card stack"><div class="row-between"><h3 style="margin:0">${esc(t('Findings'))}</h3>
          <div class="row"><button type="button" class="btn btn-soft btn-sm" data-act="d.dictate">${icon('mic')}${esc(t('Dictate'))}</button>
          <label class="btn btn-soft btn-sm" style="cursor:pointer">${icon('camera')}${esc(t('Scan written notes'))}<input type="file" accept="image/*" capture="environment" class="sr-only" data-change="d.photo"></label></div></div>
          <div id="ai-zone">${aiZone(f)}</div>
          ${field({ name: 'findings', label: t('History & physical examination findings'), type: 'textarea', value: f.findings, attrs: 'data-input="d.cf" rows="5"' })}</section>

        <section class="card stack"><h3>${esc(t('Diagnosis'))}</h3>
          <div class="chips" aria-live="polite">${f.diagnosis.length ? f.diagnosis.map((x, i) => `<span class="badge badge-info badge-lg">${esc(x)}<button type="button" class="btn btn-ghost btn-sm btn-icon" style="min-height:24px;min-width:24px;padding:0" data-act="d.dxDel" data-i="${i}" aria-label="${attr(t('Remove') + ' ' + x)}">${icon('x')}</button></span>`).join('') : `<span class="muted small">${esc(t('No diagnosis added yet.'))}</span>`}</div>
          <div class="row-nowrap"><input class="input" id="dx-in" placeholder="${attr(t('Type a diagnosis and press Add'))}" list="dx-list" aria-label="${attr(t('Diagnosis'))}"><datalist id="dx-list">${tpl.dx.map((x) => `<option value="${attr(x)}">`).join('')}</datalist><button type="button" class="btn btn-secondary" data-act="d.dxAdd">${icon('plus')}${esc(t('Add'))}</button></div>
          ${tpl.dx.length ? `<div><div class="small muted strong" style="margin-bottom:6px">${icon('bookmark')} ${esc(t('Favorites'))}</div><div class="chips">${tpl.dx.map((x) => `<button type="button" class="chip" style="min-height:36px;font-size:.9em" data-act="d.dxFav" data-v="${attr(x)}">${esc(x)}</button>`).join('')}</div></div>` : ''}</section>

        <section class="card stack"><div class="row-between"><h3 style="margin:0">${esc(t('Prescriptions'))}</h3>${tpl.rx.length ? `<select class="select" style="width:auto" data-change="d.rxTpl" aria-label="${attr(t('Use a prescription template'))}"><option value="">${esc(t('Use a template…'))}</option>${tpl.rx.map((r, i) => `<option value="${i}">${esc(r.name)}</option>`).join('')}</select>` : ''}</div>
          ${p.allergies ? `<div class="alert alert-error small">${icon('alert')}<div>${esc(t('Allergy on file'))}: <b>${esc(p.allergies)}</b></div></div>` : ''}
          ${f.rx.length ? f.rx.map((r, i) => `<div class="rx-row">${[['drug', 'Medicine & strength'], ['dose', 'Dose'], ['freq', 'Frequency'], ['dur', 'Duration']].map(([k, l]) => `<div class="field"><label class="small" for="rx-${i}-${k}">${esc(t(l))}</label><input class="input" id="rx-${i}-${k}" name="rx.${i}.${k}" value="${attr(r[k] || '')}" data-input="d.cf"></div>`).join('')}<button type="button" class="btn btn-ghost btn-icon" data-act="d.rxDel" data-i="${i}" aria-label="${attr(t('Remove medicine'))}">${icon('trash')}</button></div>`).join('') : `<p class="muted small" style="margin:0">${esc(t('No medicines prescribed.'))}</p>`}
          <button type="button" class="btn btn-secondary" style="align-self:flex-start" data-act="d.rxAdd">${icon('plus')}${esc(t('Add medicine'))}</button></section>

        <section class="card stack"><h3>${esc(t('Laboratory requests'))}</h3><div class="grid-3">${LABS.map((l) => `<label class="check small"><input type="checkbox" data-change="d.lab" value="${attr(l)}" ${f.labs.includes(l) ? 'checked' : ''}><span>${esc(l)}</span></label>`).join('')}</div>
          ${f.labs.filter((l) => !LABS.includes(l)).map((l) => `<span class="badge badge-info">${esc(l)}</span>`).join(' ')}
          <div class="row-nowrap"><input class="input" id="lab-other" placeholder="${attr(t('Other test'))}" aria-label="${attr(t('Other test'))}"><button type="button" class="btn btn-secondary" data-act="d.labOther">${icon('plus')}${esc(t('Add'))}</button></div></section>

        <section class="card stack"><h3>${esc(t('Notes & instructions to patient'))}</h3>${field({ name: 'notes', label: t('Shown to the patient after signing'), type: 'textarea', value: f.notes, attrs: 'data-input="d.cf" rows="3"' })}</section>

        <section class="card stack"><label class="switch"><input type="checkbox" data-change="d.fuOn" ${f.fu.on ? 'checked' : ''}><span class="track"></span><span class="strong">${esc(t('Schedule a follow-up visit'))}</span></label>
          ${f.fu.on ? `<div class="form-grid">${field({ name: 'fuDate', label: t('Follow-up date'), type: 'select', value: f.fu.date, options: [['', t('Select…')], ...fuDates.map((x) => [x, UI.fmtDate(x, { weekday: 'short', month: 'short', day: 'numeric' })])], attrs: 'data-change="d.fuDate"' })}${field({ name: 'fuTime', label: t('Time'), type: 'select', value: f.fu.time, options: [['', f.fu.date ? t('Select…') : t('Choose a date first')], ...fuSlots.map((s) => [s.time, fmtTime(s.time)])], attrs: `data-change="d.fuTime" ${f.fu.date ? '' : 'disabled'}` })}</div><p class="small muted" style="margin:0">${esc(t('The patient will get an SMS and see it in “Visits” as a follow-up scheduled by you.'))}</p>` : ''}</section>
      </form>

      <aside class="stack">
        <section class="card" style="position:sticky;top:76px"><h3>${esc(t('Finish'))}</h3><p class="small muted">${esc(t('Review all sections. Signing makes this record final and visible to the patient.'))}</p>
          <div class="stack-sm">
            <button class="btn btn-primary btn-lg btn-block" data-act="d.sign" data-id="${pid}" ${f.status === 'signed' ? 'disabled' : ''}>${icon('signature')}${esc(t('Save & Sign'))}</button>
            <button class="btn btn-secondary btn-block" data-act="d.saveDraft" data-id="${pid}" ${f.status === 'signed' ? 'disabled' : ''}>${icon('download')}${esc(t('Save draft'))}</button>
            <button class="btn btn-ghost btn-block" data-act="d.discard" data-id="${pid}">${esc(t('Discard changes'))}</button></div>
          <div class="divider"></div>
          <h3>${esc(t('Recent history'))}</h3>${history.length ? `<ul class="list small">${history.slice(0, 4).map((r) => `<li><b>${esc(fmtDateShort(r.date))}</b> · ${esc(Q.doctor(r.doctorId).name)}<div class="muted">${esc(r.diagnosis.join('; '))}</div>${r.rx.length ? `<div class="xs">${icon('pill')} ${esc(r.rx.map((x) => x.drug).join(', '))}</div>` : ''}</li>`).join('')}</ul>` : `<p class="small muted">${esc(t('First visit — no previous records.'))}</p>`}
        </section></aside></div>
    </div><style>@media(max-width:1100px){#c-grid{grid-template-columns:1fr!important}#c-grid aside .card{position:static!important}}</style>`);
  }
  function aiZone(f) {
    if (!f.ai) return '';
    if (f.ai.state === 'recording') return `<div class="ai-draft"><div class="ai-draft-hd"><span class="rec"></span>${esc(t('Listening…'))} <span class="mono" id="rec-t">0:00</span><span class="demo-tag">${esc(t('Demo: simulated speech-to-text'))}</span></div><p class="small" style="margin:0 0 8px">${esc(t('Speak naturally. Avoid saying the patient’s full name.'))}</p><button type="button" class="btn btn-danger btn-sm" data-act="d.stopDictate">${icon('x')}${esc(t('Stop'))}</button></div>`;
    if (f.ai.state === 'working') return `<div class="ai-draft"><div class="row"><span class="spinner spinner-sm"></span><span>${esc(f.ai.kind === 'photo' ? t('Extracting text from photo…') : t('Transcribing…'))}</span></div>${f.ai.img ? `<img src="${f.ai.img}" alt="${attr(t('Uploaded notes photo'))}" style="max-height:120px;border-radius:8px;margin-top:8px">` : ''}</div>`;
    return `<div class="ai-draft"><div class="ai-draft-hd">${icon('sparkles')}${esc(f.ai.kind === 'photo' ? t('Text extracted from photo — DRAFT') : t('AI transcription & summary — DRAFT'))}<span class="demo-tag">${esc(t('Demo output'))}</span></div>
      <p class="small" style="margin:0 0 8px">${esc(t('May contain errors. Review and edit before inserting. Nothing is saved to the record until you press Save & Sign.'))}</p>
      ${f.ai.img ? `<img src="${f.ai.img}" alt="${attr(t('Uploaded notes photo'))}" style="max-height:100px;border-radius:8px;margin-bottom:8px">` : ''}
      <label class="sr-only" for="ai-text">${esc(t('Editable draft text'))}</label><textarea class="textarea" id="ai-text" rows="4" data-input="d.aiEdit">${esc(f.ai.text)}</textarea>
      <div class="row" style="margin-top:8px"><button type="button" class="btn btn-primary btn-sm" data-act="d.aiInsert" data-to="findings">${icon('check')}${esc(t('Insert into Findings'))}</button><button type="button" class="btn btn-secondary btn-sm" data-act="d.aiInsert" data-to="notes">${esc(t('Insert into Notes'))}</button><button type="button" class="btn btn-ghost btn-sm" data-act="d.aiDiscard">${icon('trash')}${esc(t('Discard'))}</button></div></div>`;
  }
  const curPid = () => location.hash.split('/')[3];
  const curForm = () => DS.forms[curPid()];
  const setAi = (f) => { const z = document.getElementById('ai-zone'); if (z) z.innerHTML = aiZone(f); };
  UI.on('d.noop', () => {});
  UI.on('d.cf', (el) => {
    const f = curForm(); const n = el.name;
    if (n.startsWith('v.')) { f.vitals[n.slice(2)] = el.value; } else if (n.startsWith('rx.')) { const [, i, k] = n.split('.'); f.rx[Number(i)][k] = el.value; } else f[n] = el.value;
    f.dirty = true;
  });
  UI.on('d.dxAdd', () => { const i = document.getElementById('dx-in'); const v = i.value.trim(); if (!v) { i.focus(); return; } const f = curForm(); if (!f.diagnosis.includes(v)) f.diagnosis.push(v); App.refresh(); setTimeout(() => document.getElementById('dx-in').focus(), 0); });
  UI.on('d.dxFav', (el) => { const f = curForm(); if (!f.diagnosis.includes(el.dataset.v)) f.diagnosis.push(el.dataset.v); App.refresh(); });
  UI.on('d.dxDel', (el) => { curForm().diagnosis.splice(Number(el.dataset.i), 1); App.refresh(); });
  UI.on('d.rxAdd', () => { curForm().rx.push({ drug: '', dose: '', freq: '', dur: '' }); App.refresh(); const ins = document.querySelectorAll('[name^="rx."][name$=".drug"]'); if (ins.length) ins[ins.length - 1].focus(); });
  UI.on('d.rxDel', (el) => { curForm().rx.splice(Number(el.dataset.i), 1); App.refresh(); });
  UI.on('d.rxTpl', (el) => { if (el.value === '') return; const tp = templates().rx[Number(el.value)]; curForm().rx.push(...tp.items.map((x) => ({ ...x }))); toast(t('Added “{name}” — please review doses.', { name: tp.name }), 'success'); App.refresh(); });
  UI.on('d.lab', (el) => { const f = curForm(); if (el.checked) { if (!f.labs.includes(el.value)) f.labs.push(el.value); } else f.labs = f.labs.filter((x) => x !== el.value); });
  UI.on('d.labOther', () => { const i = document.getElementById('lab-other'); const v = i.value.trim(); if (!v) return i.focus(); const f = curForm(); if (!f.labs.includes(v)) f.labs.push(v); App.refresh(); });
  UI.on('d.fuOn', (el) => { curForm().fu.on = el.checked; App.refresh(); });
  UI.on('d.fuDate', (el) => { const f = curForm(); f.fu.date = el.value; f.fu.time = ''; App.refresh(); });
  UI.on('d.fuTime', (el) => { curForm().fu.time = el.value; });

  /* Simulated dictation & photo text extraction — output is always an editable draft */
  let recTimer = null;
  UI.on('d.dictate', () => {
    const f = curForm(); f.ai = { state: 'recording', kind: 'voice', started: Date.now() }; setAi(f);
    clearInterval(recTimer);
    recTimer = setInterval(() => { const el = document.getElementById('rec-t'); if (!el) return clearInterval(recTimer); const s = Math.floor((Date.now() - f.ai.started) / 1000); el.textContent = `${Math.floor(s / 60)}:${UI.pad(s % 60)}`; }, 500);
  });
  UI.on('d.stopDictate', async () => {
    clearInterval(recTimer); const f = curForm(); f.ai = { state: 'working', kind: 'voice' }; setAi(f);
    await UI.wait(1400);
    f.ai = { state: 'ready', kind: 'voice', text: 'Patient reports morning headaches 3 times this week, no vomiting, no blurring of vision. Compliant with amlodipine. Home BP readings 140s to 150s over 90s.\nPE: Alert, not in distress. Heart: regular rhythm, no murmur. Lungs: clear breath sounds. Extremities: no edema.\nSummary (suggested): Hypertension, not at goal on current medication.' };
    setAi(f);
  });
  UI.on('d.photo', (el) => {
    const file = el.files && el.files[0]; if (!file) return;
    if (!file.type.startsWith('image/')) { toast(t('Please choose a photo (JPG or PNG).'), 'error'); return; }
    const reader = new FileReader();
    reader.onload = async () => {
      const f = curForm(); f.ai = { state: 'working', kind: 'photo', img: reader.result }; setAi(f);
      await UI.wait(1600);
      f.ai = { state: 'ready', kind: 'photo', img: reader.result, text: 'BP 150/90  HR 80\n(+) occasional headache AM\n(-) chest pain, (-) SOB\nPlan: ↑ amlodipine 10mg OD, lipid profile, RTC 2 wks' };
      setAi(f);
    };
    reader.readAsDataURL(file);
  });
  UI.on('d.aiEdit', (el) => { curForm().ai.text = el.value; });
  UI.on('d.aiInsert', (el) => { const f = curForm(); const to = el.dataset.to; f[to] = (f[to] ? f[to] + '\n' : '') + f.ai.text; f.ai = null; App.refresh(); toast(t('Inserted. Please review the text before signing.'), 'success'); });
  UI.on('d.aiDiscard', () => { curForm().ai = null; setAi(curForm()); });

  UI.on('d.history', (el) => {
    const p = Q.patient(el.dataset.id);
    const hist = db().records.filter((r) => r.patientId === p.id && r.status === 'signed').sort((a, b) => b.date.localeCompare(a.date));
    UI.openModal({ title: t('History — {name}', { name: Q.fullName(p) }), size: 'lg', body: hist.length ? `<ul class="timeline">${hist.map((r) => `<li><div class="row-between"><b>${esc(fmtDate(r.date))}</b><span class="small muted">${esc(Q.doctor(r.doctorId).name)}</span></div><div class="small"><b>${esc(t('Complaint'))}:</b> ${esc(r.complaint)}</div><div class="small"><b>${esc(t('Findings'))}:</b> ${esc(r.findings)}</div><div class="small"><b>${esc(t('Diagnosis'))}:</b> ${esc(r.diagnosis.join('; '))}</div>${r.rx.length ? `<div class="small"><b>${esc(t('Rx'))}:</b> ${esc(r.rx.map((x) => `${x.drug} — ${x.dose}, ${x.freq}, ${x.dur}`).join(' | '))}</div>` : ''}${r.labs.length ? `<div class="small"><b>${esc(t('Labs'))}:</b> ${esc(r.labs.join(', '))}</div>` : ''}</li>`).join('')}</ul>` : UI.emptyState('file', t('No previous records'), ''), footer: `<button class="btn btn-primary" data-act="modal.close">${esc(t('Close'))}</button>` });
  });

  function validateForm(f) {
    const e = [];
    if (!f.complaint.trim()) e.push(t('Chief complaint is required.'));
    if (!f.diagnosis.length) e.push(t('Add at least one diagnosis.'));
    f.rx.forEach((r, i) => { if (!r.drug.trim()) e.push(t('Medicine #{n} has no name. Fill it in or remove the row.', { n: i + 1 })); else if (!r.dose.trim() || !r.freq.trim()) e.push(t('Medicine #{n}: please add the dose and frequency.', { n: i + 1 })); });
    if (f.vitals.bp && !/^\d{2,3}\/\d{2,3}$/.test(f.vitals.bp)) e.push(t('Blood pressure should look like 120/80.'));
    if (f.fu.on && (!f.fu.date || !f.fu.time)) e.push(t('Choose a follow-up date and time, or turn off follow-up.'));
    if (f.ai && f.ai.state === 'ready') e.push(t('You have an unreviewed AI draft. Insert or discard it first.'));
    return e;
  }
  UI.on('d.saveDraft', async (el) => {
    const f = curForm(); const rec = { ...f, status: 'draft' }; delete rec.ai; delete rec.dirty;
    await UI.busy(el, () => UI.wait(400), t('Saving…'));
    MockAPI.saveRecord(rec, user().name); f.dirty = false;
    toast(t('Draft saved. It is not visible to the patient until you sign it.'), 'success');
  });
  UI.on('d.discard', async (el) => {
    const ok = await UI.confirm({ title: t('Discard unsaved changes?'), message: t('Anything you typed since the last save will be lost.'), confirmText: t('Discard'), danger: true });
    if (!ok) return; delete DS.forms[el.dataset.id]; App.refresh(); toast(t('Changes discarded.'), 'success');
  });
  UI.on('d.sign', (el) => {
    const pid = el.dataset.id; const f = curForm(); const p = Q.patient(pid);
    const errs = validateForm(f);
    if (errs.length) { UI.openModal({ title: t('Please complete the record'), size: 'sm', labelledIcon: `<span style="color:var(--error)">${icon('alert', 'icon-lg')}</span>`, body: `<ul style="margin:0;padding-left:20px" class="stack-sm">${errs.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>`, footer: `<button class="btn btn-primary" data-act="modal.close">${esc(t('OK, I’ll fix it'))}</button>` }); return; }
    const v = f.vitals;
    UI.openModal({ title: t('Review before signing'), size: 'lg', body: `<div class="stack">
      <div class="alert alert-info">${icon('shield')}<div>${esc(t('Once signed, this record becomes part of the patient’s official medical record and will be visible to the patient.'))}</div></div>
      <div class="card-flat stack-sm small">
        <div><b>${esc(t('Patient'))}:</b> ${esc(Q.fullName(p))} (${esc(p.code)})</div>
        <div><b>${esc(t('Complaint'))}:</b> ${esc(f.complaint)}</div>
        <div><b>${esc(t('Vitals'))}:</b> ${esc(Object.entries(v).filter(([, x]) => x).map(([k, x]) => `${k.toUpperCase()} ${x}`).join(' · ') || '—')}</div>
        <div><b>${esc(t('Findings'))}:</b> ${esc(f.findings || '—')}</div>
        <div><b>${esc(t('Diagnosis'))}:</b> ${esc(f.diagnosis.join('; '))}</div>
        <div><b>${esc(t('Prescriptions'))}:</b> ${f.rx.length ? esc(f.rx.map((x) => `${x.drug} — ${x.dose}, ${x.freq}, ${x.dur}`).join(' | ')) : '—'}</div>
        <div><b>${esc(t('Lab requests'))}:</b> ${esc(f.labs.join(', ') || '—')}</div>
        <div><b>${esc(t('Instructions'))}:</b> ${esc(f.notes || '—')}</div>
        ${f.fu.on ? `<div><b>${esc(t('Follow-up'))}:</b> ${esc(fmtDate(f.fu.date))}, ${esc(fmtTime(f.fu.time))}</div>` : ''}</div>
      <label class="check"><input type="checkbox" id="sign-ok"><span>${esc(t('I have reviewed this record, including any AI-assisted or secretary-prepared text, and confirm it is accurate.'))}</span></label><div id="sign-err"></div></div>`,
    footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Go back and edit'))}</button><button class="btn btn-primary" data-act="d.signGo" data-id="${pid}">${icon('signature')}${esc(t('Sign record'))}</button>` });
  });
  UI.on('d.signGo', async (el) => {
    const m = UI.topModal();
    if (!m.querySelector('#sign-ok').checked) { m.querySelector('#sign-err').innerHTML = `<div class="field-error">${icon('alert')}<span>${esc(t('Please tick the box to confirm you reviewed the record.'))}</span></div>`; return; }
    const pid = el.dataset.id; const f = curForm(); const d = me();
    await UI.busy(el, () => UI.wait(800), t('Signing…'));
    const rec = { ...f, status: 'signed', signedAt: Date.now(), signedBy: user().name }; delete rec.ai; delete rec.dirty; delete rec.otherLab;
    MockAPI.saveRecord(rec, user().name);
    if (f.fromDraft) { const dr = db().drafts.find((x) => x.id === f.fromDraft); if (dr) dr.status = 'used'; }
    if (f.fu.on) MockAPI.book({ patientId: pid, doctorId: d.id, date: f.fu.date, time: f.fu.time, reason: 'Follow-up: ' + f.diagnosis[0], type: 'follow-up', by: user().name, scheduledBy: d.id });
    MockAPI.notify(pid, t('Your consultation record is ready'), t('{doc} signed your consultation notes. You can view them in Records.', { doc: d.name }), false);
    Store.save(); delete DS.forms[pid];
    UI.closeModal();
    const q = Q.serving(d.id);
    UI.openModal({ title: t('Record signed'), size: 'sm', labelledIcon: `<span style="color:var(--success)">${icon('checkCircle', 'icon-lg')}</span>`, body: `<p>${esc(t('The consultation record was signed and saved.'))}${f.fu.on ? ' ' + esc(t('Follow-up booked for {date}.', { date: fmtDate(f.fu.date) })) : ''}</p>`,
      footer: `<a class="btn btn-secondary" href="#/doctor/dashboard">${esc(t('Back to dashboard'))}</a>${q && q.patientId === pid ? `<button class="btn btn-primary" data-act="d.signNext" data-doc="${d.id}">${icon('skip')}${esc(t('Done / Call next patient'))}</button>` : ''}` });
  });
  UI.on('d.signNext', () => {
    const next = MockAPI.callNext(me().id, user().name); UI.closeModal();
    if (next) { toast(t('Now calling {no}.', { no: next.no }), 'success'); Router.go('/doctor/consult/' + next.patientId); } else { toast(t('No more patients waiting.'), 'success'); Router.go('/doctor/dashboard'); }
  });

  /* ---------- Patients (authorized list only) ---------- */
  function myPatientIds() {
    const d = me(); const ids = new Set();
    db().records.forEach((r) => { if (r.doctorId === d.id) ids.add(r.patientId); });
    Q.todaysQueue(d.id).forEach((q) => ids.add(q.patientId));
    db().appointments.forEach((a) => { if (a.doctorId === d.id && a.status !== 'cancelled') ids.add(a.patientId); });
    return [...ids];
  }
  function patientsPage() {
    const qq = DS.patientQ.toLowerCase();
    const list = myPatientIds().map(Q.patient).filter((p) => p && (!qq || Q.fullName(p).toLowerCase().includes(qq) || p.code.toLowerCase().includes(qq)));
    const lastVisit = (p) => { const r = db().records.filter((x) => x.patientId === p.id && x.doctorId === me().id && x.status === 'signed').sort((a, b) => b.date.localeCompare(a.date))[0]; return r ? r.date : ''; };
    shell('patients', t('My patients'), `<div class="stack">
      <div class="alert alert-info">${icon('shield')}<div class="small">${esc(t('You can only see patients under your care (in your queue, with appointments, or with past consultations). Every record you open is logged.'))}</div></div>
      <div class="toolbar"><div class="input-icon">${icon('search')}<input class="input" type="search" placeholder="${attr(t('Search name or patient code'))}" value="${attr(DS.patientQ)}" data-input="d.pq" aria-label="${attr(t('Search patients'))}"></div><span class="small muted">${esc(t('{n} patient(s)', { n: list.length }))}</span></div>
      <div id="pt-table">${patientTable(list, lastVisit)}</div></div>`);
  }
  function patientTable(list, lastVisit) {
    if (!list.length) return UI.emptyState('users', t('No patients found'), t('Try a different name or code.'));
    return `<div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Patient'))}</th><th>${esc(t('Age / Sex'))}</th><th>${esc(t('Priority'))}</th><th>${esc(t('Last visit with you'))}</th><th class="right">${esc(t('Actions'))}</th></tr></thead><tbody>${list.map((p) => `<tr><td><div class="row-nowrap">${avatar(p, 36)}<div><b>${esc(Q.fullName(p))}</b><div class="xs muted">${esc(p.code)}</div></div></div></td><td>${UI.ageFrom(p.bday)} · ${p.sex}</td><td>${priorityBadge(Q.priorityOf(p)) || '—'}</td><td>${lastVisit(p) ? esc(fmtDate(lastVisit(p))) : '<span class="muted">—</span>'}</td><td class="actions"><a class="btn btn-secondary btn-sm" href="#/doctor/patient/${p.id}">${icon('file')}${esc(t('Open record'))}</a><a class="btn btn-ghost btn-sm" href="#/doctor/consult/${p.id}">${esc(t('New consult'))}</a></td></tr>`).join('')}</tbody></table></div>`;
  }
  UI.on('d.pq', (el) => { DS.patientQ = el.value; const qq = el.value.toLowerCase(); const list = myPatientIds().map(Q.patient).filter((p) => p && (!qq || Q.fullName(p).toLowerCase().includes(qq) || p.code.toLowerCase().includes(qq))); document.getElementById('pt-table').innerHTML = patientTable(list, (p) => { const r = db().records.filter((x) => x.patientId === p.id && x.doctorId === me().id && x.status === 'signed').sort((a, b) => b.date.localeCompare(a.date))[0]; return r ? r.date : ''; }); });

  function patientRecord(pid) {
    const p = Q.patient(pid);
    if (!p || !myPatientIds().includes(pid)) return shell('patients', t('Patient record'), `<div class="alert alert-error">${icon('lock')}<div><div class="alert-title">${esc(t('Access not authorized'))}</div>${esc(t('This patient is not under your care. Ask the patient for a share code or contact Medical Records.'))}</div></div>`);
    if (!DS.logged) DS.logged = new Set();
    if (!DS.logged.has('v' + pid)) { DS.logged.add('v' + pid); MockAPI.log(user().name, ROLE, 'Viewed medical record', 'Patient ' + p.code); Store.save(true); }
    const recs = db().records.filter((r) => r.patientId === pid && r.status === 'signed').sort((a, b) => b.date.localeCompare(a.date));
    const appts = db().appointments.filter((a) => a.patientId === pid && a.date >= UI.today() && ['booked', 'confirmed'].includes(a.status));
    shell('patients', t('Patient record'), `<div class="stack-lg">
      <a href="#/doctor/patients" class="btn btn-ghost" style="padding-left:0;align-self:flex-start">${icon('chevronLeft')}${esc(t('Back to my patients'))}</a>
      <section class="card row-between"><div class="row-nowrap">${avatar(p, 64)}<div><h2 style="margin:0">${esc(Q.fullName(p))}</h2><div class="small muted">${esc(p.code)} · ${UI.ageFrom(p.bday)} ${esc(t('years'))} · ${p.sex} · ${esc(UI.maskMobile(p.mobile))}</div><div class="row" style="margin-top:6px">${priorityBadge(Q.priorityOf(p))}${p.allergies ? `<span class="badge badge-error">${icon('alert')}${esc(t('Allergy'))}: ${esc(p.allergies)}</span>` : ''}</div></div></div><a class="btn btn-primary" href="#/doctor/consult/${p.id}">${icon('clipboard')}${esc(t('Start consultation'))}</a></section>
      ${appts.length ? `<section class="card"><h3>${esc(t('Upcoming visits'))}</h3><ul class="list small">${appts.map((a) => `<li class="row-between"><span><b>${esc(fmtDate(a.date))}</b> ${esc(fmtTime(a.time))} · ${esc(Q.doctor(a.doctorId).name)}</span>${statusBadge(a.status)}</li>`).join('')}</ul></section>` : ''}
      <section><h3>${esc(t('Consultation history'))}</h3>${recs.length ? `<ul class="timeline">${recs.map((r) => `<li><div class="card stack-sm"><div class="row-between"><b style="color:var(--navy)">${esc(fmtDate(r.date))} · ${esc(Q.doctor(r.doctorId).name)}</b>${statusBadge('signed')}</div>
        <div class="small"><b>${esc(t('Complaint'))}:</b> ${esc(r.complaint)}</div>${Object.keys(r.vitals || {}).length ? `<div class="small"><b>${esc(t('Vitals'))}:</b> ${esc(Object.entries(r.vitals).filter(([, x]) => x).map(([k, x]) => `${k.toUpperCase()} ${x}`).join(' · '))}</div>` : ''}
        <div class="small"><b>${esc(t('Findings'))}:</b> ${esc(r.findings)}</div><div class="small"><b>${esc(t('Diagnosis'))}:</b> ${esc(r.diagnosis.join('; '))}</div>
        ${r.rx.length ? `<div class="small"><b>${esc(t('Prescriptions'))}:</b><ul style="margin:4px 0 0;padding-left:20px">${r.rx.map((x) => `<li>${esc(x.drug)} — ${esc(x.dose)}, ${esc(x.freq)}, ${esc(x.dur)}</li>`).join('')}</ul></div>` : ''}
        ${r.labs.length ? `<div class="small"><b>${esc(t('Labs'))}:</b> ${esc(r.labs.join(', '))}</div>` : ''}${r.notes ? `<div class="small"><b>${esc(t('Notes'))}:</b> ${esc(r.notes)}</div>` : ''}</div></li>`).join('')}</ul>` : UI.emptyState('file', t('No signed records yet'), '')}</section>
    </div>`);
  }

  /* ---------- Schedule ---------- */
  function schedulePage() {
    const d = me();
    const dd = DS.sched || (DS.sched = { days: [...d.days], start: d.start, end: d.end, slot: d.slot, cap: d.walkInCap, walkIn: d.walkIn });
    const perDay = Math.floor(((Number(dd.end.split(':')[0]) * 60 + Number(dd.end.split(':')[1])) - (Number(dd.start.split(':')[0]) * 60 + Number(dd.start.split(':')[1]))) / dd.slot);
    const names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    shell('schedule', t('Clinic schedule'), `<div class="grid" style="grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:20px" id="sc-grid">
      <section class="card stack"><h2>${esc(t('Weekly clinic days'))}</h2>
        <div class="chips">${[1, 2, 3, 4, 5, 6, 0].map((i) => `<button class="chip" data-act="d.day" data-i="${i}" aria-pressed="${dd.days.includes(i)}">${dd.days.includes(i) ? icon('check') : ''}${esc(t(names[i]))}</button>`).join('')}</div>
        <div class="form-grid">
          ${field({ name: 'start', label: t('Clinic starts'), type: 'time', value: dd.start, attrs: 'data-change="d.sch"' })}
          ${field({ name: 'end', label: t('Clinic ends'), type: 'time', value: dd.end, attrs: 'data-change="d.sch"' })}
          ${field({ name: 'slot', label: t('Appointment slot length'), type: 'select', value: dd.slot, options: [[10, '10 min'], [15, '15 min'], [20, '20 min'], [30, '30 min']], attrs: 'data-change="d.sch"' })}
          ${field({ name: 'cap', label: t('Daily walk-in limit'), type: 'number', value: dd.cap, attrs: 'min="0" max="80" data-change="d.sch"' })}</div>
        <label class="switch"><input type="checkbox" ${dd.walkIn ? 'checked' : ''} data-change="d.schWalk"><span class="track"></span><span class="strong">${esc(t('Accept walk-in patients'))}</span></label>
        <div class="card-info small">${icon('info')} ${esc(t('Preview: {n} appointment slots per clinic day, {d} clinic day(s) per week.', { n: perDay > 0 ? perDay : 0, d: dd.days.length }))}</div>
        <div class="row" style="justify-content:flex-end"><button class="btn btn-secondary" data-act="d.schReset">${esc(t('Undo changes'))}</button><button class="btn btn-primary" data-act="d.schSave">${icon('check')}${esc(t('Save schedule'))}</button></div></section>
      <section class="card stack"><h2>${esc(t('Blocked dates'))}</h2><p class="small muted" style="margin:0">${esc(t('No bookings or walk-ins are accepted on these dates. Patients with existing appointments should be rescheduled by your secretary.'))}</p>
        ${d.blocked.length ? `<ul class="list">${d.blocked.sort((a, b) => a.date.localeCompare(b.date)).map((b, i) => `<li class="row-between"><div><b>${esc(fmtDate(b.date))}</b><div class="small muted">${esc(b.reason)}</div></div><button class="btn btn-ghost btn-sm" data-act="d.unblock" data-date="${b.date}" aria-label="${attr(t('Remove'))}">${icon('trash')}</button></li>`).join('')}</ul>` : `<p class="muted small">${esc(t('No blocked dates.'))}</p>`}
        <form data-submit="d.block" class="stack-sm" novalidate>${field({ name: 'date', label: t('Date'), type: 'date', required: true, attrs: `min="${UI.today()}"` })}${field({ name: 'reason', label: t('Reason'), type: 'select', options: [['Leave', t('Leave')], ['Medical convention', t('Medical convention')], ['Surgery schedule', t('Surgery schedule')], ['Holiday', t('Holiday')], ['Other', t('Other')]] })}<button class="btn btn-secondary" type="submit">${icon('ban')}${esc(t('Block this date'))}</button></form></section>
    </div><style>@media(max-width:1000px){#sc-grid{grid-template-columns:1fr!important}}</style>`);
  }
  UI.on('d.day', (el) => { const i = Number(el.dataset.i); const s = DS.sched; s.days = s.days.includes(i) ? s.days.filter((x) => x !== i) : [...s.days, i].sort(); App.refresh(); });
  UI.on('d.sch', (el) => { DS.sched[el.name] = el.name === 'slot' || el.name === 'cap' ? Number(el.value) : el.value; App.refresh(); });
  UI.on('d.schWalk', (el) => { DS.sched.walkIn = el.checked; });
  UI.on('d.schReset', () => { DS.sched = null; App.refresh(); });
  UI.on('d.schSave', async (el) => {
    const s = DS.sched;
    if (!s.days.length) return toast(t('Choose at least one clinic day.'), 'error');
    if (s.start >= s.end) return toast(t('Clinic end time must be after the start time.'), 'error');
    const ok = await UI.confirm({ title: t('Save schedule changes?'), message: t('New bookings will follow this schedule. Existing appointments are not moved automatically.'), confirmText: t('Save schedule') });
    if (!ok) return;
    await UI.busy(el, () => UI.wait(500), t('Saving…'));
    Object.assign(me(), { days: s.days, start: s.start, end: s.end, slot: s.slot, walkInCap: s.cap, walkIn: s.walkIn });
    MockAPI.log(user().name, ROLE, 'Updated clinic schedule', me().name); Store.save(); DS.sched = null; toast(t('Schedule saved.'), 'success'); App.refresh();
  });
  UI.on('d.block', async (form) => {
    const f = UI.formData(form);
    if (!f.date) return UI.setErrors(form, { date: t('Please choose a date.') });
    if (me().blocked.some((b) => b.date === f.date)) return UI.setErrors(form, { date: t('This date is already blocked.') });
    const affected = db().appointments.filter((a) => a.doctorId === me().id && a.date === f.date && ['booked', 'confirmed'].includes(a.status)).length;
    const ok = await UI.confirm({ title: t('Block {date}?', { date: fmtDate(f.date) }), message: affected ? t('{n} patient(s) already have appointments on this date. Your secretary will need to reschedule them.', { n: affected }) : t('Patients will not be able to book on this date.'), confirmText: t('Block date'), danger: !!affected, icon: 'ban' });
    if (!ok) return;
    me().blocked.push({ date: f.date, reason: f.reason }); MockAPI.log(user().name, ROLE, 'Blocked clinic date', f.date); Store.save(); toast(t('Date blocked.'), 'success'); App.refresh();
  });
  UI.on('d.unblock', async (el) => {
    const ok = await UI.confirm({ title: t('Remove blocked date?'), message: t('Patients will be able to book on {date} again.', { date: fmtDate(el.dataset.date) }), confirmText: t('Remove') });
    if (!ok) return; me().blocked = me().blocked.filter((b) => b.date !== el.dataset.date); Store.save(); toast(t('Blocked date removed.'), 'success'); App.refresh();
  });

  /* ---------- Templates ---------- */
  function templatesPage() {
    const tpl = templates();
    shell('templates', t('Favorites & templates'), `<div class="grid-2" style="align-items:start">
      <section class="card stack"><h2>${esc(t('Favorite diagnoses'))}</h2>
        ${tpl.dx.length ? `<ul class="list">${tpl.dx.map((x, i) => `<li class="row-between"><span>${esc(x)}</span><button class="btn btn-ghost btn-sm" data-act="d.tdxDel" data-i="${i}" aria-label="${attr(t('Remove'))}">${icon('trash')}</button></li>`).join('')}</ul>` : `<p class="muted small">${esc(t('No favorites yet.'))}</p>`}
        <form data-submit="d.tdxAdd" class="row-nowrap" novalidate><div class="field grow"><label class="sr-only" for="tdx">${esc(t('Diagnosis'))}</label><input class="input" id="tdx" name="dx" placeholder="${attr(t('Add a diagnosis'))}"></div><button class="btn btn-primary" type="submit">${icon('plus')}${esc(t('Add'))}</button></form></section>
      <section class="card stack"><h2>${esc(t('Prescription templates'))}</h2>
        ${tpl.rx.length ? tpl.rx.map((r, i) => `<div class="card-flat"><div class="row-between"><b>${esc(r.name)}</b><button class="btn btn-ghost btn-sm" data-act="d.trxDel" data-i="${i}">${icon('trash')}${esc(t('Delete'))}</button></div><ul class="small" style="margin:6px 0 0;padding-left:20px">${r.items.map((x) => `<li>${esc(x.drug)} — ${esc(x.dose)}, ${esc(x.freq)}, ${esc(x.dur)}</li>`).join('')}</ul></div>`).join('') : `<p class="muted small">${esc(t('No templates yet.'))}</p>`}
        <button class="btn btn-secondary" data-act="d.trxNew">${icon('plus')}${esc(t('New template'))}</button></section></div>`);
  }
  UI.on('d.tdxAdd', (form) => { const v = form.dx.value.trim(); if (!v) return UI.setErrors(form, { dx: t('Please type a diagnosis.') }); const tpl = templates(); if (tpl.dx.includes(v)) return UI.setErrors(form, { dx: t('Already in your favorites.') }); tpl.dx.push(v); Store.save(); toast(t('Added to favorites.'), 'success'); App.refresh(); });
  UI.on('d.tdxDel', async (el) => { const tpl = templates(); const ok = await UI.confirm({ title: t('Remove favorite?'), message: tpl.dx[Number(el.dataset.i)], confirmText: t('Remove'), danger: true }); if (!ok) return; tpl.dx.splice(Number(el.dataset.i), 1); Store.save(); App.refresh(); });
  UI.on('d.trxDel', async (el) => { const tpl = templates(); const ok = await UI.confirm({ title: t('Delete template?'), message: tpl.rx[Number(el.dataset.i)].name, confirmText: t('Delete'), danger: true }); if (!ok) return; tpl.rx.splice(Number(el.dataset.i), 1); Store.save(); App.refresh(); toast(t('Template deleted.'), 'success'); });
  UI.on('d.trxNew', () => {
    UI.openModal({ title: t('New prescription template'), size: 'lg', body: `<form id="trx" class="stack" novalidate>${field({ name: 'name', label: t('Template name'), required: true, placeholder: t('e.g. Hypertension starter') })}
      ${[0, 1, 2].map((i) => `<div class="rx-row">${[['drug', 'Medicine & strength'], ['dose', 'Dose'], ['freq', 'Frequency'], ['dur', 'Duration']].map(([k, l]) => `<div class="field"><label class="small">${esc(t(l))}${i === 0 && k === 'drug' ? '<span class="req">*</span>' : ''}</label><input class="input" name="${k}${i}"></div>`).join('')}<span></span></div>`).join('')}
      <p class="xs muted">${esc(t('Leave unused rows empty.'))}</p></form>`,
    footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-primary" data-act="d.trxSave">${esc(t('Save template'))}</button>` });
  });
  UI.on('d.trxSave', () => {
    const form = UI.topModal().querySelector('#trx'); const f = UI.formData(form); const e = {};
    if (!f.name) e.name = t('Please name the template.');
    const items = [0, 1, 2].map((i) => ({ drug: f['drug' + i], dose: f['dose' + i], freq: f['freq' + i], dur: f['dur' + i] })).filter((x) => x.drug);
    if (!items.length) e.drug0 = t('Add at least one medicine.');
    if (!UI.setErrors(form, e)) return;
    templates().rx.push({ name: f.name, items }); Store.save(); UI.closeModal(); toast(t('Template saved.'), 'success'); App.refresh();
  });

  /* ---------- Reports ---------- */
  function reportsPage() {
    const d = me(); const days = DS.reportDays;
    const series = Array.from({ length: days }, (_, i) => { const dt = UI.addDays(UI.today(), i - days + 1); const dow = UI.parseISO(dt).getDay(); const base = d.days.includes(dow) ? 10 + ((dt.charCodeAt(9) * 7 + i * 3) % 12) : 0; return { l: UI.fmtDate(dt, { month: 'short', day: 'numeric' }), v: dt === UI.today() ? Q.todaysQueue(d.id).filter((q) => q.status === 'done').length : base, date: dt }; });
    const total = series.reduce((s, x) => s + x.v, 0);
    const recs = db().records.filter((r) => r.doctorId === d.id && r.status === 'signed');
    const dxCount = {}; recs.forEach((r) => r.diagnosis.forEach((x) => { dxCount[x] = (dxCount[x] || 0) + 1; }));
    const extra = { 'Essential hypertension, controlled': 34, 'Type 2 diabetes mellitus without complications': 21, 'Acute upper respiratory infection': 18, 'Dyslipidemia': 11, 'Acute gastroenteritis': 7 };
    Object.entries(extra).forEach(([k, v]) => { dxCount[k] = (dxCount[k] || 0) + Math.round(v * days / 30); });
    const topDx = Object.entries(dxCount).sort((a, b) => b[1] - a[1]).slice(0, 6).map(([l, v]) => ({ l, v }));
    const pts = myPatientIds().map(Q.patient).filter(Boolean);
    const ages = [['0–17', 0, 17], ['18–39', 18, 39], ['40–59', 40, 59], ['60+', 60, 200]].map(([l, a, b]) => ({ l, v: pts.filter((p) => { const x = UI.ageFrom(p.bday); return x >= a && x <= b; }).length }));
    shell('reports', t('Reports'), `<div class="stack-lg">
      <div class="toolbar"><div class="seg" role="group" aria-label="${attr(t('Date range'))}">${[[7, 'Last 7 days'], [14, 'Last 14 days'], [30, 'Last 30 days']].map(([n, l]) => `<button data-act="d.range" data-n="${n}" aria-pressed="${days === n}">${esc(t(l))}</button>`).join('')}</div><div class="grow"></div><span class="demo-tag">${esc(t('Sample figures for demo'))}</span><button class="btn btn-secondary" data-act="d.export">${icon('download')}${esc(t('Export CSV'))}</button></div>
      <div class="grid-4"><div class="stat"><div class="stat-label">${esc(t('Patients seen'))}</div><div class="stat-value">${total}</div><div class="stat-sub">${esc(t(days === 7 ? 'Last 7 days' : days === 14 ? 'Last 14 days' : 'Last 30 days'))}</div></div>
        <div class="stat"><div class="stat-label">${esc(t('Avg. per clinic day'))}</div><div class="stat-value">${(total / Math.max(1, series.filter((x) => x.v).length)).toFixed(1)}</div></div>
        <div class="stat"><div class="stat-label">${esc(t('Avg. consultation'))}</div><div class="stat-value">${db().settings.avgConsultMins}<span class="small muted"> ${esc(t('min'))}</span></div></div>
        <div class="stat"><div class="stat-label">${esc(t('Follow-up rate'))}</div><div class="stat-value">38%</div></div></div>
      <section class="card"><div class="card-hd"><h3>${esc(t('Patients seen per day'))}</h3></div>${UI.barChart(series, { label: t('Patients seen per day'), height: 220 })}
        <details style="margin-top:8px"><summary class="small strong" style="cursor:pointer">${esc(t('Show as table'))}</summary><div class="table-wrap" style="margin-top:8px"><table class="table"><thead><tr><th>${esc(t('Date'))}</th><th>${esc(t('Patients'))}</th></tr></thead><tbody>${series.map((x) => `<tr><td>${esc(x.l)}</td><td>${x.v}</td></tr>`).join('')}</tbody></table></div></details></section>
      <div class="grid-2"><section class="card"><h3>${esc(t('Top diagnoses'))}</h3>${UI.hbars(topDx)}</section><section class="card"><h3>${esc(t('Patients by age group'))}</h3>${UI.hbars(ages)}</section></div>
    </div>`);
    DS.lastSeries = series;
  }
  UI.on('d.range', (el) => { DS.reportDays = Number(el.dataset.n); App.refresh(); });
  UI.on('d.export', () => { UI.downloadCSV(`dyh-${me().id}-patients-per-day.csv`, [['Date', 'Patients seen (sample data)'], ...DS.lastSeries.map((x) => [x.date, x.v])]); toast(t('CSV downloaded.'), 'success'); });

  /* ---------- Routes ---------- */
  function route(parts) {
    if (!user()) return App.staffLogin({ role: ROLE, title: t('Doctor Workspace'), username: 'msantos', onLogin: () => { db().session.staff[ROLE] = 'u1'; MockAPI.log('Dr. Maria Lourdes Santos', ROLE, 'Signed in', 'Doctor workspace'); Store.save(true); route(parts); } });
    switch (parts[0]) {
      case 'queue': return queuePage();
      case 'consult': return consult(parts[1]);
      case 'patients': return patientsPage();
      case 'patient': return patientRecord(parts[1]);
      case 'schedule': return schedulePage();
      case 'templates': return templatesPage();
      case 'reports': return reportsPage();
      default: return dashboard();
    }
  }
  Router.add('doctor', route);
})();
