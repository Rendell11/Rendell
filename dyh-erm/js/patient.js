/* ==========================================================================
   DYH ERM — Patient application
   Guest browsing, AI Guide, registration/login, booking, queue, records.
   Consultations always happen in person at DYH — no online consultation.
   ========================================================================== */
(function () {
  'use strict';
  const { icon, esc, attr, fmtDate, fmtDay, fmtTime, fmtDateShort, avatar, statusBadge, priorityBadge, toast, field } = UI;
  const db = () => Store.db;
  const me = () => (db().session.patientId ? Q.patient(db().session.patientId) : null);

  /* In-memory UI state (cleared on reload) */
  const PS = {
    filter: { q: '', spec: '', today: false },
    booking: null,
    guide: [],
    recordsUnlocked: false,
    login: { step: 'mobile', mobile: '', returnTo: null },
    reg: null,
    apptTab: 'upcoming',
    share: null,
  };

  const NAV = [
    ['home', 'home', 'Home'],
    ['doctors', 'stethoscope', 'Doctors'],
    ['queue', 'list', 'My Queue'],
    ['appointments', 'calendar', 'Visits'],
    ['records', 'file', 'Records'],
  ];

  function activeQueue(pid) {
    const list = db().queue.filter((q) => q.patientId === pid && q.date === UI.today() && q.status !== 'cancelled');
    return list.find((q) => q.status === 'serving') || list.find((q) => q.status === 'waiting') || list.find((q) => q.status === 'skipped') || list.sort((a, b) => b.createdAt - a.createdAt)[0];
  }
  function unread(pid) { return db().notifications.filter((n) => n.patientId === pid && !n.read).length; }

  /* ---------- Shell ---------- */
  function shell(page, content, rerender, live) {
    const p = me();
    const q = p && activeQueue(p.id);
    const navDot = q && ['waiting', 'serving'].includes(q.status) ? `<span class="nav-dot" aria-label="${attr(t('Active'))}">1</span>` : '';
    const navLink = ([k, ic, l], cls) => `<a href="#/patient/${k}" ${page === k ? 'aria-current="page"' : ''} class="${cls || ''}">${icon(ic)}<span>${esc(t(l))}</span>${k === 'queue' ? navDot : ''}</a>`;
    const right = p
      ? `<a class="btn btn-ghost btn-icon" href="#/patient/notifications" aria-label="${attr(t('Notifications'))}" style="position:relative">${icon('bell')}${unread(p.id) ? `<span class="nav-dot" style="top:4px;right:4px">${unread(p.id)}</span>` : ''}</a>
         <a class="btn btn-ghost" href="#/patient/profile" style="padding:0 8px" aria-label="${attr(t('My profile'))}">${avatar(p, 36)}<span class="hide-sm">${esc(p.first)}</span></a>`
      : `<a class="btn btn-primary btn-sm" href="#/patient/login">${icon('user')}${esc(t('Sign in'))}</a>`;
    App.mount(`${App.ribbon()}<div class="shell-patient">
      <header class="p-header"><div class="inner">
        ${App.brand(t('Patient App'))}
        <nav class="p-topnav" aria-label="${attr(t('Main navigation'))}">${NAV.map((n) => navLink(n)).join('')}</nav>
        <div class="grow"></div>
        <div class="seg" role="group" aria-label="${attr(t('Language'))}"><button data-act="app.lang" data-lang="en" aria-pressed="${I18N.lang === 'en'}">EN</button><button data-act="app.lang" data-lang="fil" aria-pressed="${I18N.lang === 'fil'}">FIL</button></div>
        ${right}
      </div></header>
      <main class="p-main fade-in" id="main">${content}</main>
      <nav class="p-bottomnav" aria-label="${attr(t('Main navigation'))}">${NAV.map((n) => navLink(n)).join('')}</nav>
    </div><style>@media (max-width:520px){.hide-sm{display:none}.p-header .brand > span{display:none}}</style>`, rerender, live);
  }

  function signInPrompt(page, title, text) {
    shell(page, `<div class="card center stack" style="max-width:560px;margin:24px auto;padding:32px">
      <div class="empty-icon" style="width:72px;height:72px;border-radius:50%;background:var(--light-blue);color:var(--navy);display:flex;align-items:center;justify-content:center;margin:0 auto">${icon('lock', 'icon-xl')}</div>
      <h1>${esc(title)}</h1><p class="muted">${esc(text)}</p>
      <a class="btn btn-primary btn-lg btn-block" href="#/patient/login" data-act="p.loginReturn" data-to="${page}">${icon('user')}${esc(t('Sign in'))}</a>
      <a class="btn btn-secondary btn-lg btn-block" href="#/patient/register">${icon('userPlus')}${esc(t('Create an account'))}</a>
      <p class="small muted">${esc(t('You can still browse doctors and schedules without an account.'))}</p></div>`, () => signInPrompt(page, title, text));
  }
  UI.on('p.loginReturn', (el) => { PS.login = { step: 'mobile', mobile: '', returnTo: el.dataset.to }; Router.go('/patient/login'); });

  function greeting() {
    const h = new Date().getHours();
    return h < 12 ? t('Good morning') : h < 18 ? t('Good afternoon') : t('Good evening');
  }
  const announcementsFor = (aud) => db().announcements.filter((a) => a.active && (a.audience === 'all' || a.audience === aud) && a.start <= UI.today() && a.end >= UI.today());

  /* ---------- Home ---------- */
  function home() {
    const p = me();
    const q = p && activeQueue(p.id);
    const pos = q && Q.positionOf(q);
    const nextAppt = p && db().appointments.filter((a) => a.patientId === p.id && ['booked', 'confirmed'].includes(a.status) && a.date >= UI.today()).sort((a, b) => (a.date + a.time).localeCompare(b.date + b.time))[0];
    const ann = announcementsFor('app');
    shell('home', `<div class="stack-lg">
      <div><p class="muted" style="margin:0">${esc(fmtDay(UI.today()))}</p>
        <h1 class="p-hello">${esc(greeting())}${p ? ', ' + esc(p.first) : ''}!</h1>
        <p class="muted" style="margin:4px 0 0">${esc(p ? t('What would you like to do today?') : t('Welcome to Dr. Yanga’s Hospital. How can we help you today?'))}</p></div>

      ${q && ['waiting', 'serving', 'skipped'].includes(q.status) ? `<a href="#/patient/queue" class="card card-click" style="display:block;text-decoration:none;border-color:var(--navy);border-width:2px">
        <div class="row-between"><div><div class="small muted strong">${esc(t('Your queue today'))}</div><div style="font-size:2.2em;font-weight:800;color:var(--navy);line-height:1.1">${esc(q.no)}</div><div class="small">${esc(Q.doctor(q.doctorId).name)} · ${esc(t('Room'))} ${esc(Q.doctor(q.doctorId).room)}</div></div>
        <div class="right">${statusBadge(q.status, 'badge-lg')}${q.status === 'waiting' ? `<div style="margin-top:8px"><b style="font-size:1.4em">${pos.ahead}</b> <span class="small muted">${esc(t('patients ahead'))}</span></div>` : ''}</div></div></a>` : ''}

      <div class="p-actions">
        <a class="p-action primary" href="#/patient/doctors"><span class="ic">${icon('search', 'icon-lg')}</span><span>${esc(t('Find a Doctor'))}<small>${esc(t('Search by name or clinic'))}</small></span></a>
        <a class="p-action" href="#/patient/book" data-act="p.bookStart"><span class="ic">${icon('calendarPlus', 'icon-lg')}</span><span>${esc(t('Book an Appointment'))}<small>${esc(t('Choose a doctor, date and time'))}</small></span></a>
        <a class="p-action" href="#/patient/queue"><span class="ic">${icon('list', 'icon-lg')}</span><span>${esc(t('View My Queue'))}<small>${esc(t('See your number and turn'))}</small></span></a>
      </div>

      <a class="card card-info card-click" href="#/patient/guide" style="display:flex;gap:16px;align-items:center;text-decoration:none;color:inherit">
        <span class="role-ic" style="background:#fff">${icon('sparkles', 'icon-lg')}</span>
        <div class="grow"><b style="color:var(--navy);font-size:1.05em">${esc(t('Not sure which doctor to see?'))}</b><div class="small">${esc(t('Ask the DYH Guide. It helps you find the right clinic and schedule. It does not diagnose.'))}</div></div>${icon('chevronRight')}</a>

      ${nextAppt ? `<section><div class="row-between" style="margin-bottom:8px"><h2 style="margin:0">${esc(t('Your next visit'))}</h2><a href="#/patient/appointments" class="small strong">${esc(t('See all'))}</a></div>${apptCard(nextAppt, true)}</section>` : ''}

      <div class="er-strip" role="note">${icon('alert', 'icon-lg')}<div>${esc(t('Emergency? Do not book online. Go directly to the Emergency Room — open 24 hours.'))}</div></div>

      ${ann.length ? `<section><h2>${esc(t('Announcements'))}</h2><div class="grid-2">${ann.map((a) => `<div class="card row-nowrap" style="align-items:flex-start"><span class="role-ic" style="width:48px;height:48px">${icon(a.icon || 'megaphone')}</span><div><b style="color:var(--navy)">${esc(I18N.pick(a.title))}</b><p class="small" style="margin:4px 0 0">${esc(I18N.pick(a.body))}</p></div></div>`).join('')}</div></section>` : ''}

      <section class="card"><h2>${esc(t('Hospital information'))}</h2><div class="grid-2 small">
        <div class="row-nowrap" style="align-items:flex-start">${icon('pin')}<div><b>${esc(t('Location'))}</b><br>Dr. Yanga’s Hospital Inc., Bocaue, Bulacan <span class="demo-tag">${esc(t('sample'))}</span></div></div>
        <div class="row-nowrap" style="align-items:flex-start">${icon('clock')}<div><b>${esc(t('Outpatient clinics'))}</b><br>${esc(t('Monday to Saturday, 8:00 AM – 5:00 PM'))}<br>${esc(t('Emergency Room: open 24 hours'))}</div></div>
        <div class="row-nowrap" style="align-items:flex-start">${icon('phone')}<div><b>${esc(t('Hotline'))}</b><br>(044) 000-0000 <span class="demo-tag">${esc(t('sample'))}</span></div></div>
        <div class="row-nowrap" style="align-items:flex-start">${icon('info')}<div><b>${esc(t('Please note'))}</b><br>${esc(t('All consultations are done in person at the hospital. This app is for booking, queueing and viewing records.'))}</div></div>
      </div></section>
      ${!p ? `<div class="card center stack-sm"><b>${esc(t('Have an account?'))}</b><div class="row" style="justify-content:center"><a class="btn btn-primary" href="#/patient/login">${esc(t('Sign in'))}</a><a class="btn btn-secondary" href="#/patient/register">${esc(t('Create an account'))}</a></div></div>` : ''}
    </div>`, home, true);
  }

  /* ---------- Doctor directory ---------- */
  function docStatusBadge(d) { const s = Q.walkInStatus(d); return statusBadge(s.state); }
  function doctorCard(d) {
    const s = Q.walkInStatus(d);
    return `<article class="card stack-sm">
      <div class="doc-card">${avatar(d, 72)}<div class="grow">
        <div class="doc-name">${esc(d.name)}</div>
        <div class="badge badge-info" style="margin-top:4px">${esc(Q.specName(d.spec))}</div>
        <div class="doc-meta" style="margin-top:8px"><span>${icon('door')}${esc(t('Room'))} ${esc(d.room)} · ${esc(d.floor)}</span><span>${icon('calendar')}${esc(Q.scheduleText(d))}</span></div>
      </div></div>
      <div class="row-between" style="padding-top:4px"><div>${statusBadge(s.state)}${s.state !== 'open' && s.reason ? `<div class="xs muted" style="margin-top:4px">${esc(s.reason)}</div>` : ''}</div>
      <div class="row"><a class="btn btn-secondary btn-sm" href="#/patient/doctor/${d.id}">${esc(t('View profile'))}</a><button class="btn btn-primary btn-sm" data-act="p.bookDoctor" data-id="${d.id}">${icon('calendarPlus')}${esc(t('Book'))}</button></div></div>
    </article>`;
  }
  function filteredDoctors() {
    const f = PS.filter; const q = f.q.toLowerCase();
    return db().doctors.filter((d) => d.status === 'active' && (!f.spec || d.spec === f.spec) && (!q || d.name.toLowerCase().includes(q) || Q.specName(d.spec).toLowerCase().includes(q) || Q.spec(d.spec).en.toLowerCase().includes(q)) && (!f.today || Q.walkInStatus(d).state === 'open'));
  }
  function doctorResults() {
    const list = filteredDoctors();
    return `<p class="small muted" aria-live="polite">${esc(t('{n} doctor(s) found', { n: list.length }))}</p>` + (list.length ? `<div class="grid-2">${list.map(doctorCard).join('')}</div>` : UI.emptyState('search', t('No doctors match your search'), t('Try a different name or clinic, or clear the filters.'), `<button class="btn btn-secondary" data-act="p.clearFilter">${esc(t('Clear filters'))}</button>`));
  }
  function doctors() {
    const f = PS.filter;
    shell('doctors', `<div class="stack">
      <div><h1>${esc(t('Find a Doctor'))}</h1><p class="muted">${esc(t('Search by doctor name or choose a clinic. Tap a doctor to see their schedule.'))}</p></div>
      <div class="input-icon">${icon('search')}<input class="input" type="search" placeholder="${attr(t('Search doctor or clinic…'))}" value="${attr(f.q)}" data-input="p.search" aria-label="${attr(t('Search doctor or clinic'))}"></div>
      <div class="chips chips-scroll" role="group" aria-label="${attr(t('Filter by clinic'))}">
        <button class="chip" data-act="p.spec" data-spec="" aria-pressed="${!f.spec}">${esc(t('All clinics'))}</button>
        ${Q.SPECIALTIES.map((s) => `<button class="chip" data-act="p.spec" data-spec="${s.id}" aria-pressed="${f.spec === s.id}">${esc(I18N.pick(s))}</button>`).join('')}
      </div>
      <label class="switch"><input type="checkbox" data-change="p.todayOnly" ${f.today ? 'checked' : ''}><span class="track"></span><span>${esc(t('Only show doctors accepting walk-ins today'))}</span></label>
      <div id="doc-results">${doctorResults()}</div>
    </div>`, doctors, true);
  }
  const updateResults = () => { const el = document.getElementById('doc-results'); if (el) el.innerHTML = doctorResults(); };
  UI.on('p.search', (el) => { PS.filter.q = el.value; updateResults(); });
  UI.on('p.spec', (el) => { PS.filter.spec = el.dataset.spec; document.querySelectorAll('[data-act="p.spec"]').forEach((b) => b.setAttribute('aria-pressed', b === el)); updateResults(); });
  UI.on('p.todayOnly', (el) => { PS.filter.today = el.checked; updateResults(); });
  UI.on('p.clearFilter', () => { PS.filter = { q: '', spec: '', today: false }; doctors(); });

  function doctorProfile(id) {
    const d = Q.doctor(id);
    if (!d) return shell('doctors', UI.emptyState('stethoscope', t('Doctor not found'), '', `<a class="btn btn-primary" href="#/patient/doctors">${esc(t('Back to doctors'))}</a>`));
    const s = Q.walkInStatus(d);
    const next = Q.nextClinicDays(d, UI.addDays(UI.today(), 1), 4);
    const days = [1, 2, 3, 4, 5, 6, 0];
    shell('doctors', `<div class="stack-lg">
      <a href="#/patient/doctors" class="btn btn-ghost" style="padding-left:0">${icon('chevronLeft')}${esc(t('Back to doctors'))}</a>
      <section class="card"><div class="doc-card">${avatar(d, 104)}<div class="grow"><h1 style="margin-bottom:4px">${esc(d.name)}</h1><span class="badge badge-info badge-lg">${esc(Q.specName(d.spec))}</span><p style="margin-top:12px">${esc(I18N.pick(d.bio))}</p>
        <div class="doc-meta" style="font-size:1em"><span>${icon('door')}${esc(t('Room'))} ${esc(d.room)} · ${esc(d.floor)}</span></div></div></div></section>
      <div class="grid-2">
        <section class="card"><h2>${esc(t('Today'))}</h2>
          <div class="stack-sm">${statusBadge(s.state, 'badge-lg')}${s.reason ? `<p class="muted" style="margin:0">${esc(s.reason)}</p>` : ''}
          ${Q.isClinicDay(d, UI.today()) ? `<div class="grid-3" style="margin-top:8px"><div class="stat"><div class="stat-label">${esc(t('Now serving'))}</div><div class="stat-value">${esc(s.servingNo)}</div></div><div class="stat"><div class="stat-label">${esc(t('Waiting'))}</div><div class="stat-value">${s.waiting}</div></div><div class="stat"><div class="stat-label">${esc(t('Slots left'))}</div><div class="stat-value">${s.left}</div></div></div>` : ''}
          ${s.state === 'open' ? `<button class="btn btn-secondary btn-lg btn-block" data-act="p.walkinDoctor" data-id="${d.id}">${icon('list')}${esc(t('Join today’s walk-in queue'))}</button>` : ''}
          </div></section>
        <section class="card"><h2>${esc(t('Weekly clinic schedule'))}</h2>
          <ul class="list">${days.map((dd) => `<li class="row-between"><b>${esc(t(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][dd]))}</b>${d.days.includes(dd) ? `<span>${esc(fmtTime(d.start))} – ${esc(fmtTime(d.end))}</span>` : `<span class="muted">${esc(t('No clinic'))}</span>`}</li>`).join('')}</ul>
          ${d.blocked.length ? `<div class="alert alert-warning" style="margin-top:12px">${icon('calendar')}<div><div class="alert-title">${esc(t('Not available on'))}</div>${d.blocked.map((b) => `${esc(fmtDate(b.date))}`).join('<br>')}</div></div>` : ''}
        </section></div>
      <section class="card"><h2>${esc(t('Next available clinic days'))}</h2>
        ${next.length ? `<div class="chips">${next.map((dt) => `<button class="chip" data-act="p.bookDoctorDate" data-id="${d.id}" data-date="${dt}">${icon('calendar')}${esc(fmtDate(dt, { weekday: 'short', month: 'short', day: 'numeric' }))}</button>`).join('')}</div>` : `<p class="muted">${esc(t('No open clinic days in the booking window.'))}</p>`}
        <button class="btn btn-primary btn-lg btn-block" style="margin-top:16px" data-act="p.bookDoctor" data-id="${d.id}">${icon('calendarPlus')}${esc(t('Book an appointment with this doctor'))}</button>
        <p class="small muted center" style="margin-top:8px">${esc(t('Your consultation will be in person at Room {room}.', { room: d.room }))}</p></section>
    </div>`, () => doctorProfile(id), true);
  }

  /* ---------- AI Guide (rule-based demo; not a diagnosis tool) ---------- */
  const GUIDE_CHIPS = [['Fever', 'fever'], ['Cough or colds', 'cough'], ['My child is sick', 'child'], ['Pregnancy check-up', 'pregnant'], ['Joint or back pain', 'joint'], ['Eye problem', 'eye'], ['Skin rash or itch', 'skin rash'], ['High blood pressure', 'blood pressure'], ['Heart check-up', 'heart'], ['Ear, nose or throat', 'throat']];
  function guideIntro() {
    return { from: 'bot', html: `<p style="margin:0 0 6px"><b>${esc(t('Hello! I’m the DYH Guide.'))}</b></p><p style="margin:0">${esc(t('Tell me what you need help with, and I’ll suggest which clinic and doctor to visit and when they are available. I cannot diagnose illnesses or give medical advice.'))}</p>` };
  }
  function guide() {
    if (!PS.guide.length) PS.guide.push(guideIntro());
    shell('home', `<div class="stack" style="max-width:760px;margin:0 auto">
      <div class="row-between"><div><h1 style="margin:0">${esc(t('DYH Guide'))}</h1><p class="muted small" style="margin:0">${esc(t('Helps you find the right clinic, doctor and schedule'))}</p></div><button class="btn btn-ghost btn-sm" data-act="p.guideReset">${icon('refresh')}${esc(t('Start over'))}</button></div>
      <div class="alert alert-info">${icon('shield')}<div class="small">${esc(t('This guide does not diagnose or replace a doctor. For chest pain, difficulty breathing, severe bleeding, fainting or other emergencies, go directly to the Emergency Room.'))} <span class="demo-tag">${esc(t('Demo: rule-based responses'))}</span></div></div>
      <div class="chat" id="chat" aria-live="polite">${PS.guide.map((m) => `<div class="bubble ${m.from === 'me' ? 'me' : 'bot'}">${m.html}</div>`).join('')}</div>
      <div><div class="small strong muted" style="margin-bottom:6px">${esc(t('Tap a topic or type below:'))}</div><div class="chips">${GUIDE_CHIPS.map(([l, k]) => `<button class="chip" data-act="p.guideChip" data-k="${attr(k)}" data-l="${attr(t(l))}">${esc(t(l))}</button>`).join('')}</div></div>
      <form class="row-nowrap" data-submit="p.guideSend" style="position:sticky;bottom:80px;background:var(--gray-50);padding:8px 0">
        <label for="guide-in" class="sr-only">${esc(t('Your message'))}</label>
        <input id="guide-in" class="input grow" name="msg" placeholder="${attr(t('e.g. My knee hurts when I walk'))}" autocomplete="off">
        <button class="btn btn-primary" type="submit">${icon('send')}<span class="hide-sm">${esc(t('Send'))}</span></button></form>
    </div>`, guide);
    const c = document.getElementById('chat'); if (c) c.lastElementChild.scrollIntoView({ block: 'nearest' });
  }
  function guideReply(text) {
    const s = text.toLowerCase();
    if (Q.EMERGENCY_KW.some((k) => s.includes(k))) {
      return `<div class="alert alert-emergency" style="margin:-4px -8px">${icon('alert', 'icon-lg')}<div><div class="alert-title" style="font-size:1.15em">${esc(t('This may be an emergency.'))}</div><p>${esc(t('Please go to the DYH Emergency Room immediately or call for help. Do not wait for an appointment or a queue number.'))}</p><p style="margin:0"><b>${esc(t('Emergency Room: open 24 hours, Ground Floor, Main Building.'))}</b></p></div></div>`;
    }
    const byName = db().doctors.find((d) => s.includes(d.name.split(' ').slice(-1)[0].toLowerCase()));
    if (byName) return `<p style="margin:0 0 8px">${esc(t('Here is the schedule of {doc}:', { doc: byName.name }))}</p>${guideDoctorList([byName])}`;
    const scored = Q.SPECIALTIES.map((sp) => ({ sp, n: sp.kw.filter((k) => s.includes(k)).length })).filter((x) => x.n).sort((a, b) => b.n - a.n);
    if (!scored.length) {
      return `<p style="margin:0 0 8px">${esc(t('I’m not sure which clinic fits yet. Could you choose the closest topic below, or describe it in a few words? If unsure, adults can start with Internal Medicine or Family Medicine, and children with Pediatrics.'))}</p><div class="chips">${['im', 'fm', 'peds'].map((id) => `<button class="chip" data-act="p.guideSpec" data-spec="${id}">${esc(Q.specName(id))}</button>`).join('')}</div>`;
    }
    return guideSpecReply(scored[0].sp.id);
  }
  function guideSpecReply(specId) {
    const sp = Q.spec(specId);
    const docs = db().doctors.filter((d) => d.spec === specId && d.status === 'active');
    return `<p style="margin:0 0 4px">${esc(t('Based on what you shared, the {clinic} clinic may be a good place to start.', { clinic: I18N.pick(sp) }))}</p><p class="small" style="margin:0 0 8px;opacity:.85">${esc(I18N.pick(sp.desc))}. ${esc(t('This is a suggestion only, not a diagnosis. The doctor will assess you in person.'))}</p>${guideDoctorList(docs)}`;
  }
  function guideDoctorList(docs) {
    return `<div class="stack-sm">${docs.map((d) => {
      const s = Q.walkInStatus(d); const nx = Q.nextClinicDays(d, UI.addDays(UI.today(), 1), 1)[0];
      return `<div class="card" style="padding:12px"><div class="row-nowrap">${avatar(d, 44)}<div class="grow"><b style="color:var(--navy)">${esc(d.name)}</b><div class="xs muted">${esc(t('Room'))} ${esc(d.room)} · ${esc(Q.scheduleText(d))}</div></div></div>
        <div class="row-between" style="margin-top:8px"><div class="small">${statusBadge(s.state)}${nx ? `<div class="xs muted" style="margin-top:4px">${esc(t('Next clinic day'))}: ${esc(fmtDate(nx, { weekday: 'short', month: 'short', day: 'numeric' }))}</div>` : ''}</div><button class="btn btn-primary btn-sm" data-act="p.bookDoctor" data-id="${d.id}">${esc(t('Book'))}</button></div></div>`;
    }).join('')}</div>`;
  }
  async function guideAsk(label, text) {
    PS.guide.push({ from: 'me', html: esc(label) });
    PS.guide.push({ from: 'bot', html: '<span class="typing" aria-label="typing"><span></span><span></span><span></span></span>' });
    guide();
    await UI.wait(650);
    PS.guide[PS.guide.length - 1] = { from: 'bot', html: guideReply(text) };
    if (location.hash.includes('/guide')) guide();
  }
  UI.on('p.guideSend', (form) => { const v = form.msg.value.trim(); if (!v) { form.msg.focus(); return; } guideAsk(v, v); });
  UI.on('p.guideChip', (el) => guideAsk(el.dataset.l, el.dataset.k));
  UI.on('p.guideSpec', async (el) => { PS.guide.push({ from: 'me', html: esc(Q.specName(el.dataset.spec)) }); PS.guide.push({ from: 'bot', html: guideSpecReply(el.dataset.spec) }); guide(); });
  UI.on('p.guideReset', () => { PS.guide = []; guide(); });

  /* ---------- Booking flow ---------- */
  function startBooking(pre) { PS.booking = { step: 1, mode: null, spec: '', doctorId: '', date: '', time: '', reason: 'New concern', ...pre }; }
  UI.on('p.bookStart', () => { startBooking(); Router.go('/patient/book'); });
  UI.on('p.bookDoctor', (el) => { const d = Q.doctor(el.dataset.id); startBooking({ mode: 'appointment', spec: d.spec, doctorId: d.id, step: 4 }); Router.go('/patient/book'); });
  UI.on('p.bookDoctorDate', (el) => { const d = Q.doctor(el.dataset.id); startBooking({ mode: 'appointment', spec: d.spec, doctorId: d.id, date: el.dataset.date, step: 4 }); Router.go('/patient/book'); });
  UI.on('p.walkinDoctor', (el) => { const d = Q.doctor(el.dataset.id); startBooking({ mode: 'walkin', spec: d.spec, doctorId: d.id, step: 5 }); Router.go('/patient/book'); });

  function book() {
    if (!PS.booking) startBooking();
    const b = PS.booking;
    const steps = b.mode === 'walkin' ? ['Visit type', 'Clinic', 'Doctor', 'Review'] : ['Visit type', 'Clinic', 'Doctor', 'Date & time', 'Review'];
    const idx = b.mode === 'walkin' ? ({ 1: 0, 2: 1, 3: 2, 5: 3 }[b.step] || 0) : b.step - 1;
    const stepper = b.step < 6 ? `<ol class="stepper" aria-label="${attr(t('Booking steps'))}">${steps.map((s, i) => `<li class="${i < idx ? 'done' : i === idx ? 'current' : ''}" ${i === idx ? 'aria-current="step"' : ''}><span>${esc(t(s))}</span></li>`).join('')}</ol>` : '';
    let body = '';
    const back = (to) => `<button class="btn btn-secondary btn-lg" data-act="p.bStep" data-step="${to}">${icon('chevronLeft')}${esc(t('Back'))}</button>`;

    if (b.step === 1) {
      body = `<h1>${esc(t('What kind of visit do you need?'))}</h1><p class="muted">${esc(t('Both options are for in-person consultation at the hospital.'))}</p>
      <div class="grid-2">
        <button class="p-action" style="flex-direction:column;align-items:flex-start;min-height:180px" data-act="p.bMode" data-mode="appointment"><span class="ic">${icon('calendarPlus', 'icon-lg')}</span><span>${esc(t('Book a future appointment'))}<small>${esc(t('Pick a date and time slot. You will get an SMS reminder before your visit.'))}</small></span></button>
        <button class="p-action" style="flex-direction:column;align-items:flex-start;min-height:180px" data-act="p.bMode" data-mode="walkin"><span class="ic">${icon('list', 'icon-lg')}</span><span>${esc(t('Join today’s walk-in queue'))}<small>${esc(t('Same-day only. Get a queue number now and come to the clinic before your turn.'))}</small></span></button>
      </div>`;
    } else if (b.step === 2) {
      body = `<h1>${esc(t('Which clinic do you need?'))}</h1><p class="muted">${esc(t('Not sure?'))} <a href="#/patient/guide">${esc(t('Ask the DYH Guide'))}</a></p>
      <div class="grid-2">${Q.SPECIALTIES.map((s) => `<button class="card card-click" data-act="p.bSpec" data-spec="${s.id}" ${b.spec === s.id ? 'style="border-color:var(--navy);box-shadow:0 0 0 1px var(--navy)"' : ''}><b style="color:var(--navy);font-size:1.05em">${esc(I18N.pick(s))}</b><div class="small muted">${esc(I18N.pick(s.desc))}</div></button>`).join('')}</div>
      <div class="row">${back(1)}</div>`;
    } else if (b.step === 3) {
      const docs = db().doctors.filter((d) => d.spec === b.spec && d.status === 'active');
      body = `<h1>${esc(t('Choose a doctor'))}</h1><p class="muted">${esc(Q.specName(b.spec))}</p>
      <div class="stack-sm">${docs.map((d) => {
        const s = Q.walkInStatus(d); const disabled = b.mode === 'walkin' && s.state !== 'open';
        return `<button class="card card-click doc-card" data-act="p.bDoctor" data-id="${d.id}" ${disabled ? 'aria-disabled="true" style="opacity:.65;cursor:not-allowed"' : ''}>${avatar(d, 64)}<div class="grow"><div class="doc-name">${esc(d.name)}</div><div class="doc-meta"><span>${icon('door')}${esc(t('Room'))} ${esc(d.room)}</span><span>${icon('calendar')}${esc(Q.scheduleText(d))}</span></div>
          ${b.mode === 'walkin' ? `<div style="margin-top:6px">${statusBadge(s.state)} ${s.state === 'open' ? `<span class="small muted">${esc(t('Now serving'))} ${esc(s.servingNo)} · ${s.waiting} ${esc(t('waiting'))}</span>` : `<span class="small muted">${esc(s.reason)}</span>`}</div>` : ''}</div>${disabled ? '' : icon('chevronRight')}</button>`;
      }).join('')}</div><div class="row">${back(2)}</div>`;
    } else if (b.step === 4) {
      const d = Q.doctor(b.doctorId);
      const dates = Q.nextClinicDays(d, db().settings.sameDayBooking ? UI.today() : UI.addDays(UI.today(), 1), 12);
      if (!b.date || !dates.includes(b.date)) b.date = dates[0] || '';
      const slots = b.date ? Q.slotsFor(d.id, b.date) : [];
      if (b.time && !slots.some((s) => s.time === b.time && !s.taken)) b.time = '';
      body = `<h1>${esc(t('Choose a date and time'))}</h1>
      <div class="card row-nowrap" style="padding:12px 16px">${avatar(d, 48)}<div class="grow"><b style="color:var(--navy)">${esc(d.name)}</b><div class="small muted">${esc(Q.specName(d.spec))} · ${esc(t('Room'))} ${esc(d.room)}</div></div><button class="btn btn-ghost btn-sm" data-act="p.bStep" data-step="3">${esc(t('Change'))}</button></div>
      <section><h2>${esc(t('1. Pick a date'))}</h2>${dates.length ? `<div class="date-grid">${dates.map((dt) => { const x = UI.parseISO(dt); return `<button class="date-btn" data-act="p.bDate" data-date="${dt}" aria-pressed="${b.date === dt}" aria-label="${attr(fmtDay(dt))}"><span>${esc(x.toLocaleDateString(I18N.lang === 'fil' ? 'fil-PH' : 'en-PH', { weekday: 'short' }))}</span><b>${x.getDate()}</b><span>${esc(x.toLocaleDateString(I18N.lang === 'fil' ? 'fil-PH' : 'en-PH', { month: 'short' }))}</span></button>`; }).join('')}</div>` : UI.emptyState('calendar', t('No available dates'), t('This doctor has no open clinic days in the next {n} days.', { n: db().settings.bookingWindowDays }))}</section>
      ${b.date ? `<section><h2>${esc(t('2. Pick a time'))}</h2><p class="small muted">${esc(fmtDay(b.date))} · ${esc(t('Crossed-out times are already taken.'))}</p>
        ${slots.some((s) => !s.taken) ? `<div class="slot-grid">${slots.map((s) => `<button class="slot" data-act="p.bTime" data-time="${s.time}" aria-pressed="${b.time === s.time}" ${s.taken ? `disabled aria-label="${attr(fmtTime(s.time) + ' ' + t('taken'))}"` : ''}>${esc(fmtTime(s.time))}</button>`).join('')}</div>` : `<div class="alert alert-warning">${icon('calendar')}<div>${esc(t('All times on this date are taken. Please choose another date.'))}</div></div>`}</section>` : ''}
      <div class="row-between">${back(3)}<button class="btn btn-primary btn-lg" data-act="p.bStep" data-step="5" ${b.date && b.time ? '' : 'disabled'}>${esc(t('Continue'))}${icon('chevronRight')}</button></div>`;
    } else if (b.step === 5) {
      const d = Q.doctor(b.doctorId); const s = Q.walkInStatus(d);
      const walk = b.mode === 'walkin';
      body = `<h1>${esc(walk ? t('Review and join the queue') : t('Review your appointment'))}</h1><p class="muted">${esc(t('Please check the details below before confirming.'))}</p>
      <section class="card stack">
        <div class="row-nowrap">${avatar(d, 64)}<div><div class="doc-name">${esc(d.name)}</div><div class="muted small">${esc(Q.specName(d.spec))}</div></div></div>
        <div class="grid-2">
          <div><div class="small muted strong">${esc(t('Visit type'))}</div><div class="strong">${esc(walk ? t('Same-day walk-in queue') : t('Scheduled appointment'))}</div></div>
          <div><div class="small muted strong">${esc(t('Date'))}</div><div class="strong">${esc(fmtDay(walk ? UI.today() : b.date))}</div></div>
          <div><div class="small muted strong">${esc(walk ? t('Current queue') : t('Time'))}</div><div class="strong">${walk ? `${esc(t('Now serving'))} ${esc(s.servingNo)} · ${s.waiting} ${esc(t('waiting'))}` : esc(fmtTime(b.time))}</div></div>
          <div><div class="small muted strong">${esc(t('Where to go'))}</div><div class="strong">${esc(t('Room'))} ${esc(d.room)}, ${esc(d.floor)}</div></div>
        </div>
        ${field({ name: 'reason', label: t('Reason for visit'), type: 'select', value: b.reason, options: [['New concern', t('New concern')], ['Follow-up', t('Follow-up')], ['Lab results review', t('Lab results review')], ['Medical certificate', t('Medical certificate')], ['Other', t('Other')]], attrs: 'data-change="p.bReason"' })}
      </section>
      <div class="alert alert-info">${icon('info')}<div><div class="alert-title">${esc(t('Good to know'))}</div>${esc(t('Your consultation will be in person at the hospital. Please bring a valid ID, your OSCA/PWD ID if applicable, and any previous results.'))}</div></div>
      ${walk && s.state !== 'open' ? `<div class="alert alert-error">${icon('ban')}<div>${esc(t('This doctor is no longer accepting walk-ins today.'))} ${esc(s.reason)}</div></div>` : ''}
      <div class="row-between">${back(walk ? 3 : 4)}<button class="btn btn-success btn-lg" data-act="p.bConfirm" ${walk && s.state !== 'open' ? 'disabled' : ''}>${icon('check')}${esc(walk ? t('Get my queue number') : t('Confirm booking'))}</button></div>`;
    } else if (b.step === 6) {
      const a = db().appointments.find((x) => x.id === b.apptId); const d = Q.doctor(a.doctorId);
      body = `<div class="center stack" style="max-width:640px;margin:0 auto">
        <div style="width:88px;height:88px;border-radius:50%;background:var(--success-bg);color:var(--success);display:flex;align-items:center;justify-content:center;margin:0 auto">${icon('checkCircle', 'icon-xl')}</div>
        <h1>${esc(t('Your appointment is booked!'))}</h1>
        <p class="muted">${esc(t('Reference no.'))} <b class="mono">${esc(a.id.slice(2).toUpperCase())}</b></p>
        <div class="card" style="text-align:left">${apptDetails(a)}</div>
        <div class="card" style="text-align:left"><h2>${esc(t('What happens next'))}</h2><ol style="margin:0;padding-left:22px" class="stack-sm">
          <li>${esc(t('We will send an SMS reminder {h} hours before your visit.', { h: db().settings.reminderHours }))}</li>
          <li>${esc(t('On the day, arrive 15 minutes early and tell the clinic secretary at Room {room} that you have arrived.', { room: d.room }))}</li>
          <li>${esc(t('You will receive a queue number. Watch your queue status in this app.'))}</li>
          <li>${esc(t('Need to change plans? You can reschedule or cancel in “Visits”.'))}</li></ol></div>
        <div class="row" style="justify-content:center"><a class="btn btn-primary btn-lg" href="#/patient/appointments">${esc(t('View my visits'))}</a><a class="btn btn-secondary btn-lg" href="#/patient/home">${esc(t('Back to home'))}</a></div></div>`;
    }
    shell('home', `<div class="stack-lg" style="max-width:860px;margin:0 auto">${stepper}${body}</div>`, book);
  }
  UI.on('p.bStep', (el) => { PS.booking.step = Number(el.dataset.step); book(); window.scrollTo(0, 0); });
  UI.on('p.bMode', (el) => { const b = PS.booking; b.mode = el.dataset.mode; b.step = b.spec && b.doctorId ? (b.mode === 'walkin' ? 5 : 4) : 2; book(); });
  UI.on('p.bSpec', (el) => { const b = PS.booking; if (b.spec !== el.dataset.spec) b.doctorId = ''; b.spec = el.dataset.spec; b.step = 3; book(); window.scrollTo(0, 0); });
  UI.on('p.bDoctor', (el) => { const b = PS.booking; b.doctorId = el.dataset.id; b.date = ''; b.time = ''; if (!b.mode) b.mode = 'appointment'; b.step = b.mode === 'walkin' ? 5 : 4; book(); window.scrollTo(0, 0); });
  UI.on('p.bDate', (el) => { PS.booking.date = el.dataset.date; PS.booking.time = ''; book(); });
  UI.on('p.bTime', (el) => { PS.booking.time = el.dataset.time; book(); });
  UI.on('p.bReason', (el) => { PS.booking.reason = el.value; });
  UI.on('p.bConfirm', async (el) => {
    const b = PS.booking; const p = me();
    if (!p) {
      UI.openModal({ title: t('Sign in to finish'), size: 'sm', body: `<p>${esc(t('Your selections are saved. Please sign in or create an account so we can send your confirmation and reminders.'))}</p>`, footer: `<a class="btn btn-secondary" href="#/patient/register">${esc(t('Create an account'))}</a><button class="btn btn-primary" data-act="p.loginReturn" data-to="book">${esc(t('Sign in'))}</button>` });
      return;
    }
    const d = Q.doctor(b.doctorId);
    const ok = await UI.confirm({ title: b.mode === 'walkin' ? t('Join the queue?') : t('Confirm this booking?'), message: b.mode === 'walkin' ? t('You will get a queue number for {doc} today. Please go to Room {room} before your turn.', { doc: d.name, room: d.room }) : t('{doc} on {date} at {time}.', { doc: d.name, date: fmtDate(b.date), time: fmtTime(b.time) }), confirmText: b.mode === 'walkin' ? t('Get my queue number') : t('Confirm booking'), icon: 'calendarPlus' });
    if (!ok) return;
    await UI.busy(el, () => UI.wait(700), t('Saving…'));
    if (b.mode === 'walkin') {
      const r = MockAPI.addToQueue({ doctorId: d.id, patientId: p.id, source: 'app', by: 'Patient app' });
      if (r.error === 'already') { toast(t('You are already in this doctor’s queue today ({no}).', { no: r.entry.no }), 'warning'); Router.go('/patient/queue'); return; }
      PS.booking = null;
      toast(t('Your queue number is {no}.', { no: r.entry.no }), 'success', t('You are in the queue'));
      Router.go('/patient/queue');
    } else {
      const r = MockAPI.book({ patientId: p.id, doctorId: d.id, date: b.date, time: b.time, reason: b.reason, by: Q.shortName(p) });
      if (r.error) { toast(r.error, 'error'); if (r.error.includes('taken')) { b.step = 4; b.time = ''; book(); } return; }
      b.apptId = r.appt.id; b.step = 6; book(); window.scrollTo(0, 0);
      toast(t('Booking confirmed. We sent the details by SMS.'), 'success');
    }
  });

  /* ---------- My Queue ---------- */
  function queue() {
    const p = me();
    if (!p) return signInPrompt('queue', t('Sign in to see your queue'), t('Your queue number, position and turn alerts appear here after you sign in.'));
    const q = activeQueue(p.id);
    if (!q) {
      return shell('queue', `<div class="stack-lg"><h1>${esc(t('My Queue'))}</h1>${UI.emptyState('list', t('You are not in a queue today'), t('If you are visiting today, you can join a doctor’s walk-in queue. For future visits, book an appointment.'), `<div class="row" style="justify-content:center"><button class="btn btn-primary btn-lg" data-act="p.queueJoin">${icon('list')}${esc(t('Join a walk-in queue'))}</button><button class="btn btn-secondary btn-lg" data-act="p.bookStart">${esc(t('Book an appointment'))}</button></div>`)}</div>`, queue, true);
    }
    const d = Q.doctor(q.doctorId); const pos = Q.positionOf(q);
    const total = Q.todaysQueue(d.id).filter((x) => ['waiting', 'serving'].includes(x.status)).length;
    const pct = q.status === 'waiting' ? Math.max(6, 100 - (pos.ahead / Math.max(1, total)) * 100) : 100;
    let alert = '';
    if (q.status === 'serving') alert = `<div class="alert alert-success" role="alert">${icon('door', 'icon-lg')}<div><div class="alert-title" style="font-size:1.2em">${esc(t('It is your turn!'))}</div>${esc(t('Please proceed to Room {room} now.', { room: d.room }))}</div></div>`;
    else if (q.status === 'waiting' && pos.ahead === 0) alert = `<div class="alert alert-warning" role="alert">${icon('bell', 'icon-lg')}<div><div class="alert-title" style="font-size:1.2em">${esc(t('You are next!'))}</div>${esc(t('Please stay near Room {room}. Your number will be called soon.', { room: d.room }))}</div></div>`;
    else if (q.status === 'waiting' && pos.ahead < db().settings.approachingAt) alert = `<div class="alert alert-info" role="status">${icon('bell', 'icon-lg')}<div><div class="alert-title">${esc(t('Your turn is approaching'))}</div>${esc(t('{n} patient(s) ahead of you. Please make your way to the {floor}.', { n: pos.ahead, floor: d.floor }))}</div></div>`;
    else if (q.status === 'skipped') alert = `<div class="alert alert-error" role="alert">${icon('alert', 'icon-lg')}<div><div class="alert-title">${esc(t('You missed your call'))}</div>${esc(t('Your number was called but you were not present. Please approach the clinic secretary at Room {room} to be called back.', { room: d.room }))}</div></div>`;
    else if (q.status === 'done') alert = `<div class="alert alert-success">${icon('checkCircle', 'icon-lg')}<div><div class="alert-title">${esc(t('Consultation complete'))}</div>${esc(t('Thank you for visiting DYH. Your doctor’s notes will appear in Records once signed.'))}</div></div>`;

    shell('queue', `<div class="stack-lg" style="max-width:720px;margin:0 auto">
      <div class="row-between"><h1 style="margin:0">${esc(t('My Queue'))}</h1><button class="btn btn-ghost" data-act="p.queueRefresh">${icon('refresh')}${esc(t('Refresh'))}</button></div>
      ${alert}
      <section class="ticket" aria-label="${attr(t('Your queue ticket'))}">
        <div class="ticket-label">${esc(t('Your queue number'))}</div>
        <div class="ticket-no">${esc(q.no)}</div>
        <div style="font-weight:700;font-size:1.1em">${esc(d.name)}</div>
        <div style="color:#C9D8EA">${esc(Q.specName(d.spec))} · ${esc(t('Room'))} ${esc(d.room)}</div>
        <div style="margin-top:12px">${statusBadge(q.status, 'badge-lg')}</div>
        <div class="ticket-meta"><div><b>${esc(pos.nowServing || q.no)}</b><span>${esc(t('Now serving'))}</span></div><div><b>${q.status === 'waiting' ? pos.ahead : '—'}</b><span>${esc(t('Patients ahead'))}</span></div><div><b>${q.status === 'waiting' ? '~' + pos.etaMins : '—'}</b><span>${esc(t('Est. minutes'))}</span></div></div>
      </section>
      ${q.status === 'waiting' ? `<div><div class="row-between small strong" style="margin-bottom:6px"><span>${esc(t('Your progress'))}</span><span>${pos.ahead === 0 ? esc(t('Next')) : esc(t('{n} ahead', { n: pos.ahead }))}</span></div><div class="progress" role="progressbar" aria-valuenow="${Math.round(pct)}" aria-valuemin="0" aria-valuemax="100"><span style="width:${pct}%"></span></div><p class="xs muted" style="margin-top:6px">${esc(t('Estimated time is a guide only. Priority patients (senior citizens, PWDs, pregnant women) may be called first.'))}</p></div>` : ''}
      <section class="card"><h2>${esc(t('While you wait'))}</h2><ul class="list small">
        <li class="row-nowrap">${icon('pin')}<span>${esc(t('Go to {floor}, Room {room}.', { floor: d.floor, room: d.room }))}</span></li>
        <li class="row-nowrap">${icon('bell')}<span>${esc(t('We will send an SMS when you are {n} patients away and when you are next.', { n: db().settings.approachingAt }))}</span></li>
        <li class="row-nowrap">${icon('user')}<span>${esc(t('Bring a valid ID. Senior citizens and PWDs, please show your ID to the secretary.'))}</span></li>
        <li class="row-nowrap">${icon('alert')}<span>${esc(t('If you feel worse while waiting, tell any staff member or go to the Emergency Room.'))}</span></li></ul></section>
      ${['waiting', 'skipped'].includes(q.status) ? `<button class="btn btn-danger-outline btn-lg btn-block" data-act="p.queueLeave" data-id="${q.id}">${icon('x')}${esc(t('Leave the queue'))}</button>` : ''}
      ${q.status === 'done' ? `<button class="btn btn-secondary btn-lg btn-block" data-act="p.rate">${icon('star')}${esc(t('Rate your visit'))}</button>` : ''}
      <div class="card-flat stack-sm"><div class="row"><span class="demo-tag">${icon('info')}${esc(t('Demo control'))}</span><span class="small muted">${esc(t('Simulates the clinic secretary pressing “Done / Next”. In the real system this happens at the clinic.'))}</span></div><button class="btn btn-soft" data-act="p.simNext" data-doc="${d.id}">${icon('skip')}${esc(t('Simulate: clinic calls the next patient'))}</button></div>
    </div>`, queue, true);
  }
  UI.on('p.queueRefresh', async (el) => { await UI.busy(el, () => UI.wait(500), t('Refreshing…')); queue(); toast(t('Queue status updated.'), 'success'); });
  UI.on('p.queueJoin', () => { startBooking({ mode: 'walkin', step: 2 }); Router.go('/patient/book'); });
  UI.on('p.queueLeave', async (el) => {
    const ok = await UI.confirm({ title: t('Leave the queue?'), message: t('You will lose your place in line. If you change your mind, you will need a new queue number.'), confirmText: t('Leave the queue'), danger: true, icon: 'alert' });
    if (!ok) return; MockAPI.cancelQueue(el.dataset.id, Q.shortName(me())); toast(t('You have left the queue.'), 'success'); queue();
  });
  UI.on('p.simNext', (el) => { MockAPI.callNext(el.dataset.doc, 'Demo control'); queue(); });
  UI.on('p.rate', () => rateModal('app'));

  function rateModal(source) {
    let rating = 0;
    const faces = [[1, 'Very poor'], [2, 'Poor'], [3, 'Okay'], [4, 'Good'], [5, 'Excellent']];
    UI.openModal({ title: t('How was your visit?'), body: `<p class="muted">${esc(t('Your feedback helps us improve. It is anonymous.'))}</p><div class="row" role="radiogroup" aria-label="${attr(t('Rating'))}" style="justify-content:center;gap:8px">${faces.map(([n, l]) => `<button class="btn btn-secondary" style="flex-direction:column;min-height:88px;min-width:88px" data-act="p.rateSet" data-n="${n}" role="radio" aria-checked="false">${'★'.repeat(n)}<span class="xs">${esc(t(l))}</span></button>`).join('')}</div><div style="margin-top:16px">${field({ name: 'comment', label: t('Comments (optional)'), type: 'textarea', placeholder: t('Tell us what went well or what we can improve') })}</div><div id="rate-err"></div>`, footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Not now'))}</button><button class="btn btn-primary" data-act="p.rateSend">${esc(t('Send feedback'))}</button>` });
    UI.on('p.rateSet', (el) => { rating = Number(el.dataset.n); UI.topModal().querySelectorAll('[data-act="p.rateSet"]').forEach((b) => { const on = Number(b.dataset.n) === rating; b.setAttribute('aria-checked', on); b.className = 'btn ' + (on ? 'btn-primary' : 'btn-secondary'); b.style.cssText = 'flex-direction:column;min-height:88px;min-width:88px'; }); });
    UI.on('p.rateSend', () => {
      if (!rating) { UI.topModal().querySelector('#rate-err').innerHTML = `<div class="field-error" style="margin-top:8px">${icon('alert')}<span>${esc(t('Please choose a rating first.'))}</span></div>`; return; }
      MockAPI.feedback(rating, UI.topModal().querySelector('[name=comment]').value, source); UI.closeModal(); toast(t('Thank you for your feedback!'), 'success');
    });
  }

  /* ---------- Appointments ---------- */
  function apptDetails(a) {
    const d = Q.doctor(a.doctorId);
    return `<div class="row-nowrap" style="align-items:flex-start">${avatar(d, 56)}<div class="grow stack-sm" style="margin:0">
      <div class="doc-name">${esc(d.name)}</div><div class="small muted" style="margin-top:0">${esc(Q.specName(d.spec))}</div>
      <div class="doc-meta" style="font-size:.95em"><span>${icon('calendar')}<b>${esc(fmtDay(a.date))}</b></span><span>${icon('clock')}${esc(fmtTime(a.time))}</span><span>${icon('door')}${esc(t('Room'))} ${esc(d.room)} · ${esc(d.floor)}</span></div></div></div>`;
  }
  function apptCard(a, compact) {
    const d = Q.doctor(a.doctorId);
    const upcoming = ['booked', 'confirmed'].includes(a.status) && a.date >= UI.today();
    const hoursTo = (UI.parseISO(a.date).getTime() + Number(a.time.slice(0, 2)) * 3600000 - Date.now()) / 3600000;
    const locked = hoursTo < db().settings.cancelCutoffHours;
    const rec = db().records.find((r) => r.patientId === a.patientId && r.doctorId === a.doctorId && r.date === a.date && r.status === 'signed');
    return `<article class="card stack-sm">
      <div class="row-between" style="align-items:flex-start"><div class="row">${statusBadge(a.status)}${a.type === 'follow-up' ? `<span class="badge badge-navy">${icon('bookmark')}${esc(a.scheduledBy ? t('Follow-up set by {doc}', { doc: Q.doctor(a.scheduledBy).name }) : t('Follow-up'))}</span>` : ''}</div></div>
      ${apptDetails(a)}
      ${a.reason ? `<div class="small"><span class="muted">${esc(t('Reason'))}:</span> ${esc(t(a.reason))}</div>` : ''}
      ${upcoming && !compact ? `<div class="row" style="padding-top:8px;border-top:1px solid var(--gray-100)">
        ${a.status === 'booked' ? `<button class="btn btn-success btn-sm" data-act="p.apptConfirm" data-id="${a.id}">${icon('check')}${esc(t('Confirm I will come'))}</button>` : ''}
        <button class="btn btn-secondary btn-sm" data-act="p.apptResched" data-id="${a.id}" ${locked ? 'disabled' : ''}>${icon('calendar')}${esc(t('Reschedule'))}</button>
        <button class="btn btn-danger-outline btn-sm" data-act="p.apptCancel" data-id="${a.id}" ${locked ? 'disabled' : ''}>${icon('x')}${esc(t('Cancel'))}</button></div>
        ${locked ? `<p class="xs muted" style="margin:0">${esc(t('Changes are closed {h} hours before the visit. Please call the hospital for help.', { h: db().settings.cancelCutoffHours }))}</p>` : ''}` : ''}
      ${rec && !compact ? `<a class="btn btn-ghost btn-sm" href="#/patient/record/${rec.id}" style="align-self:flex-start">${icon('file')}${esc(t('View consultation record'))}</a>` : ''}
      ${compact ? `<a class="btn btn-secondary btn-sm" href="#/patient/appointments" style="align-self:flex-start">${esc(t('Manage'))}</a>` : ''}
    </article>`;
  }
  function appointments() {
    const p = me();
    if (!p) return signInPrompt('appointments', t('Sign in to see your visits'), t('Your booked appointments and follow-ups from your doctor appear here.'));
    const all = db().appointments.filter((a) => a.patientId === p.id);
    const up = all.filter((a) => ['booked', 'confirmed', 'arrived'].includes(a.status) && a.date >= UI.today()).sort((a, b) => (a.date + a.time).localeCompare(b.date + b.time));
    const past = all.filter((a) => !up.includes(a)).sort((a, b) => (b.date + b.time).localeCompare(a.date + a.time));
    const list = PS.apptTab === 'upcoming' ? up : past;
    shell('appointments', `<div class="stack">
      <div class="row-between"><h1 style="margin:0">${esc(t('My Visits'))}</h1><button class="btn btn-primary" data-act="p.bookStart">${icon('plus')}${esc(t('Book'))}</button></div>
      <div class="tabs" role="tablist"><button role="tab" aria-selected="${PS.apptTab === 'upcoming'}" data-act="p.apptTab" data-tab="upcoming">${esc(t('Upcoming'))} (${up.length})</button><button role="tab" aria-selected="${PS.apptTab === 'past'}" data-act="p.apptTab" data-tab="past">${esc(t('Past & cancelled'))} (${past.length})</button></div>
      ${list.length ? `<div class="grid-2">${list.map((a) => apptCard(a)).join('')}</div>` : UI.emptyState('calendar', PS.apptTab === 'upcoming' ? t('No upcoming visits') : t('No past visits yet'), PS.apptTab === 'upcoming' ? t('When you book an appointment, it will appear here.') : '', PS.apptTab === 'upcoming' ? `<button class="btn btn-primary btn-lg" data-act="p.bookStart">${esc(t('Book an appointment'))}</button>` : '')}
    </div>`, appointments, true);
  }
  UI.on('p.apptTab', (el) => { PS.apptTab = el.dataset.tab; appointments(); });
  UI.on('p.apptConfirm', (el) => { MockAPI.confirmAppt(el.dataset.id); toast(t('Thank you! Your attendance is confirmed.'), 'success'); App.refresh(); });
  UI.on('p.apptCancel', async (el) => {
    const a = db().appointments.find((x) => x.id === el.dataset.id); const d = Q.doctor(a.doctorId);
    const ok = await UI.confirm({ title: t('Cancel this appointment?'), message: t('{doc} on {date} at {time}. This time slot will be given to other patients.', { doc: d.name, date: fmtDate(a.date), time: fmtTime(a.time) }), confirmText: t('Yes, cancel it'), cancelText: t('Keep appointment'), danger: true, icon: 'alert' });
    if (!ok) return; MockAPI.cancelAppt(a.id, Q.shortName(me()), 'patient'); toast(t('Your appointment was cancelled.'), 'success'); App.refresh();
  });
  UI.on('p.apptResched', (el) => rescheduleModal(el.dataset.id, 'patient', Q.shortName(me())));

  /** Reschedule dialog shared with the secretary screen. */
  function rescheduleModal(id, role, by) {
    const a = db().appointments.find((x) => x.id === id); const d = Q.doctor(a.doctorId);
    const st = { date: '', time: '' };
    const dates = Q.nextClinicDays(d, UI.today(), 10).filter((x) => x !== a.date || true);
    const render = () => {
      const slots = st.date ? Q.slotsFor(d.id, st.date) : [];
      return `<p class="muted">${esc(t('Current'))}: <b>${esc(fmtDate(a.date))}, ${esc(fmtTime(a.time))}</b></p>
        <h3>${esc(t('New date'))}</h3><div class="date-grid">${dates.map((dt) => { const x = UI.parseISO(dt); return `<button class="date-btn" data-act="rs.date" data-date="${dt}" aria-pressed="${st.date === dt}"><span>${esc(x.toLocaleDateString('en-PH', { weekday: 'short' }))}</span><b>${x.getDate()}</b><span>${esc(x.toLocaleDateString('en-PH', { month: 'short' }))}</span></button>`; }).join('')}</div>
        ${st.date ? `<h3 style="margin-top:16px">${esc(t('New time'))}</h3><div class="slot-grid">${slots.map((s) => `<button class="slot" data-act="rs.time" data-time="${s.time}" aria-pressed="${st.time === s.time}" ${s.taken && !(st.date === a.date && s.time === a.time) ? 'disabled' : ''}>${esc(fmtTime(s.time))}</button>`).join('')}</div>` : ''}`;
    };
    const m = UI.openModal({ title: t('Reschedule appointment'), size: 'lg', body: `<div id="rs-body">${render()}</div>`, footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Go back'))}</button><button class="btn btn-primary" data-act="rs.save" id="rs-save" disabled>${esc(t('Save new schedule'))}</button>` });
    const upd = () => { m.querySelector('#rs-body').innerHTML = render(); m.querySelector('#rs-save').disabled = !(st.date && st.time); };
    UI.on('rs.date', (el) => { st.date = el.dataset.date; st.time = ''; upd(); });
    UI.on('rs.time', (el) => { st.time = el.dataset.time; upd(); });
    UI.on('rs.save', async (el) => {
      await UI.busy(el, () => UI.wait(500), t('Saving…'));
      const r = MockAPI.reschedule(id, st.date, st.time, by, role);
      if (r.error) { toast(r.error, 'error'); return; }
      UI.closeModal(); toast(t('Rescheduled to {date} at {time}.', { date: fmtDate(st.date), time: fmtTime(st.time) }), 'success'); App.refresh();
    });
  }

  /* ---------- Records ---------- */
  function records() {
    const p = me();
    if (!p) return signInPrompt('records', t('Sign in to view your records'), t('Your medical records are private. Please sign in to continue.'));
    if (!PS.recordsUnlocked) {
      return shell('records', `<div class="card stack" style="max-width:560px;margin:16px auto;padding:28px">
        <div class="center"><div style="width:72px;height:72px;border-radius:50%;background:var(--light-blue);color:var(--navy);display:flex;align-items:center;justify-content:center;margin:0 auto 8px">${icon('shield', 'icon-xl')}</div>
        <h1>${esc(t('Confirm it’s you'))}</h1><p class="muted">${esc(t('For your privacy, we ask for a security code each time you open your medical records.'))}</p></div>
        <div class="alert alert-warning">${icon('smartphone')}<div>${esc(t('We sent a 6-digit code to {m}.', { m: UI.maskMobile(p.mobile) }))}<br><b>${esc(t('Demo code'))}: 123456</b></div></div>
        <form data-submit="p.recUnlock" class="stack" novalidate>${field({ name: 'code', label: t('Security code'), inputmode: 'numeric', autocomplete: 'one-time-code', placeholder: '••••••', attrs: 'maxlength="6"' })}
        <button class="btn btn-primary btn-lg btn-block" type="submit">${icon('lock')}${esc(t('Open my records'))}</button></form>
        <p class="xs muted center">${esc(t('Never share this code with anyone, including people who say they are from the hospital.'))}</p></div>`, records);
    }
    const recs = db().records.filter((r) => r.patientId === p.id && r.status === 'signed').sort((a, b) => b.date.localeCompare(a.date));
    const labs = recs.flatMap((r) => r.labs.map((l) => ({ l, r })));
    shell('records', `<div class="stack-lg">
      <div class="row-between"><div><h1 style="margin:0">${esc(t('My Medical Records'))}</h1><p class="muted small" style="margin:0">${esc(t('Only records reviewed and signed by your doctor appear here.'))}</p></div><button class="btn btn-ghost btn-sm" data-act="p.recLock">${icon('lock')}${esc(t('Lock'))}</button></div>
      <section class="card row-between">
        <div class="row-nowrap">${avatar(p, 56)}<div><b style="font-size:1.1em;color:var(--navy)">${esc(Q.fullName(p))}</b><div class="small muted">${esc(t('Patient code'))}: <b class="mono">${esc(p.code)}</b></div><div class="small">${esc(t('Age'))} ${UI.ageFrom(p.bday)} · ${esc(p.sex === 'F' ? t('Female') : t('Male'))}${p.allergies ? ` · <span class="badge badge-error">${icon('alert')}${esc(t('Allergy'))}: ${esc(p.allergies)}</span>` : ''}</div></div></div>
        <div class="row"><button class="btn btn-secondary" data-act="p.showQR">${icon('qr')}${esc(t('My patient QR'))}</button><button class="btn btn-primary" data-act="p.share">${icon('send')}${esc(t('Share records'))}</button></div>
      </section>
      ${PS.share ? `<div class="alert alert-info">${icon('shield')}<div class="grow"><div class="alert-title">${esc(t('Sharing is active'))}</div>${esc(t('Share code {code} expires {when}.', { code: PS.share.code, when: UI.fmtDateTime(PS.share.exp) }))}</div><button class="btn btn-danger-outline btn-sm" data-act="p.shareStop">${esc(t('Stop sharing'))}</button></div>` : ''}
      <section><h2>${esc(t('Consultation history'))}</h2>
        ${recs.length ? `<ul class="timeline">${recs.map((r) => { const d = Q.doctor(r.doctorId); return `<li><a class="card card-click" href="#/patient/record/${r.id}" style="display:block;text-decoration:none;color:inherit"><div class="row-between"><b style="color:var(--navy)">${esc(fmtDate(r.date))}</b>${statusBadge('signed')}</div><div class="small">${esc(d.name)} · ${esc(Q.specName(d.spec))}</div><div style="margin-top:6px">${esc(r.diagnosis.join('; '))}</div><div class="row small muted" style="margin-top:6px"><span>${icon('pill')} ${r.rx.length} ${esc(t('prescription(s)'))}</span><span>${icon('flask')} ${r.labs.length} ${esc(t('lab request(s)'))}</span></div></a></li>`; }).join('')}</ul>` : UI.emptyState('file', t('No records yet'), t('After your consultation, your doctor’s signed notes will appear here.'))}
      </section>
      ${labs.length ? `<section class="card"><h2>${esc(t('Laboratory requests'))}</h2><div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Test'))}</th><th>${esc(t('Requested by'))}</th><th>${esc(t('Date'))}</th></tr></thead><tbody>${labs.map(({ l, r }) => `<tr><td>${esc(l)}</td><td>${esc(Q.doctor(r.doctorId).name)}</td><td>${esc(fmtDateShort(r.date))}</td></tr>`).join('')}</tbody></table></div></section>` : ''}
    </div>`, records);
  }
  UI.on('p.recUnlock', async (form) => {
    const v = form.code.value.trim();
    if (!/^\d{6}$/.test(v)) return UI.setErrors(form, { code: t('Please enter the 6-digit code.') });
    await UI.busy(form.querySelector('button'), () => UI.wait(500), t('Checking…'));
    if (v !== '123456') return UI.setErrors(form, { code: t('That code is not correct. Please check the SMS and try again.') });
    PS.recordsUnlocked = true; MockAPI.log(Q.shortName(me()), 'patient', 'Opened own medical records', me().code); Store.save(true); records();
  });
  UI.on('p.recLock', () => { PS.recordsUnlocked = false; records(); toast(t('Records locked.'), 'success'); });
  UI.on('p.showQR', () => {
    const p = me();
    UI.openModal({ title: t('My patient QR code'), size: 'sm', body: `<div class="center stack"><div class="qr" style="margin:0 auto">${UI.demoQR(p.code, 200)}</div><div class="mono" style="font-size:1.5em;font-weight:800;color:var(--navy)">${esc(p.code)}</div><p class="small">${esc(t('Show this at the kiosk or front desk to find your record quickly. This code only identifies you — it does not show your medical information.'))}</p><span class="demo-tag">${esc(t('Demo pattern — not a scannable QR'))}</span></div>`, footer: `<button class="btn btn-primary" data-act="modal.close">${esc(t('Done'))}</button>` });
  });
  UI.on('p.share', () => {
    UI.openModal({ title: t('Share my medical records'), body: `<div class="stack">
      <div class="alert alert-info">${icon('shield')}<div>${esc(t('Use this only when a doctor or hospital outside DYH needs to see your records. You stay in control.'))}</div></div>
      <ul class="small stack-sm" style="padding-left:20px;margin:0"><li>${esc(t('The person you share with can VIEW your signed consultation records, prescriptions and lab requests.'))}</li><li>${esc(t('They cannot change your records.'))}</li><li>${esc(t('Access ends automatically when the code expires, or when you press “Stop sharing”.'))}</li><li>${esc(t('Every view is recorded in the hospital audit log.'))}</li></ul>
      ${field({ name: 'dur', label: t('Share for how long?'), type: 'select', options: [['1', t('1 hour')], ['24', t('24 hours')], ['72', t('3 days')]] })}
      <label class="check"><input type="checkbox" name="consent"><span>${esc(t('I understand and I consent to share my medical records using a one-time code. (Data Privacy Act of 2012)'))}</span></label><div id="share-err"></div></div>`,
    footer: `<button class="btn btn-secondary" data-act="modal.close">${esc(t('Cancel'))}</button><button class="btn btn-primary" data-act="p.shareGo">${esc(t('Create share code'))}</button>` });
  });
  UI.on('p.shareGo', async (el) => {
    const m = UI.topModal();
    if (!m.querySelector('[name=consent]').checked) { m.querySelector('#share-err').innerHTML = `<div class="field-error">${icon('alert')}<span>${esc(t('Please tick the consent box to continue.'))}</span></div>`; return; }
    const hrs = Number(m.querySelector('[name=dur]').value);
    await UI.busy(el, () => UI.wait(600), t('Creating…'));
    PS.share = { code: 'SHR-' + Math.random().toString(36).slice(2, 8).toUpperCase(), exp: Date.now() + hrs * 3600000 };
    MockAPI.log(Q.shortName(me()), 'patient', 'Created record share code', `${hrs}h`); Store.save(true);
    UI.closeModal();
    UI.openModal({ title: t('Share code ready'), size: 'sm', body: `<div class="center stack"><div class="qr" style="margin:0 auto">${UI.demoQR(PS.share.code, 180)}</div><div class="mono" style="font-size:1.6em;font-weight:800;color:var(--navy)">${esc(PS.share.code)}</div><p class="small">${esc(t('Give this code only to the doctor who needs it. Expires {when}.', { when: UI.fmtDateTime(PS.share.exp) }))}</p><span class="demo-tag">${esc(t('Demo pattern — not a scannable QR'))}</span></div>`, footer: `<button class="btn btn-primary" data-act="modal.close">${esc(t('Done'))}</button>`, onClose: () => records() });
  });
  UI.on('p.shareStop', async () => {
    const ok = await UI.confirm({ title: t('Stop sharing?'), message: t('The share code will stop working immediately.'), confirmText: t('Stop sharing'), danger: true });
    if (ok) { PS.share = null; toast(t('Sharing stopped.'), 'success'); records(); }
  });

  function recordDetail(id) {
    const p = me(); const r = db().records.find((x) => x.id === id);
    if (!p || !r || r.patientId !== p.id) return records();
    if (!PS.recordsUnlocked) return records();
    const d = Q.doctor(r.doctorId);
    shell('records', `<div class="stack-lg" style="max-width:820px;margin:0 auto">
      <a href="#/patient/records" class="btn btn-ghost" style="padding-left:0">${icon('chevronLeft')}${esc(t('Back to records'))}</a>
      <section class="card stack">
        <div class="row-between"><div><h1 style="margin:0">${esc(t('Consultation on {date}', { date: fmtDate(r.date) }))}</h1><div class="muted">${esc(d.name)} · ${esc(Q.specName(d.spec))}</div></div>${statusBadge('signed')}</div>
        ${r.complaint ? `<div><h3>${esc(t('Reason for visit'))}</h3><p>${esc(r.complaint)}</p></div>` : ''}
        ${Object.keys(r.vitals || {}).length ? `<div><h3>${esc(t('Vital signs'))}</h3><div class="grid-4">${[['bp', 'Blood pressure', 'mmHg'], ['hr', 'Heart rate', 'bpm'], ['temp', 'Temperature', '°C'], ['wt', 'Weight', 'kg']].filter(([k]) => r.vitals[k]).map(([k, l, u]) => `<div class="stat"><div class="stat-label">${esc(t(l))}</div><div class="stat-value" style="font-size:1.4em">${esc(r.vitals[k])} <span class="small muted">${u}</span></div></div>`).join('')}</div></div>` : ''}
        <div><h3>${esc(t('Findings'))}</h3><p>${esc(r.findings)}</p></div>
        <div><h3>${esc(t('Diagnosis'))}</h3><ul style="margin:0;padding-left:22px">${r.diagnosis.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></div>
        ${r.rx.length ? `<div><h3>${esc(t('Prescriptions'))}</h3><div class="table-wrap"><table class="table"><thead><tr><th>${esc(t('Medicine'))}</th><th>${esc(t('Dose'))}</th><th>${esc(t('How often'))}</th><th>${esc(t('How long'))}</th></tr></thead><tbody>${r.rx.map((x) => `<tr><td><b>${esc(x.drug)}</b></td><td>${esc(x.dose)}</td><td>${esc(x.freq)}</td><td>${esc(x.dur)}</td></tr>`).join('')}</tbody></table></div></div>` : ''}
        ${r.labs.length ? `<div><h3>${esc(t('Laboratory requests'))}</h3><div class="chips">${r.labs.map((l) => `<span class="badge badge-info badge-lg">${icon('flask')}${esc(l)}</span>`).join('')}</div></div>` : ''}
        ${r.notes ? `<div><h3>${esc(t('Doctor’s instructions'))}</h3><p>${esc(r.notes)}</p></div>` : ''}
        <div class="card-flat small row-nowrap">${icon('signature')}<span>${esc(t('Reviewed and signed by {doc} on {date}.', { doc: d.name, date: UI.fmtDateTime(r.signedAt) }))}</span></div>
      </section>
      <p class="small muted center">${esc(t('Questions about this record? Ask your doctor at your next visit. Do not change your medicines without talking to your doctor.'))}</p>
    </div>`, () => recordDetail(id));
  }

  /* ---------- Login (mobile + OTP) ---------- */
  function login() {
    const L = PS.login;
    shell('profile', `<div class="card stack" style="max-width:520px;margin:16px auto;padding:28px">
      ${L.step === 'mobile' ? `<h1>${esc(t('Sign in'))}</h1><p class="muted">${esc(t('Enter the mobile number you registered with. We will text you a 6-digit code.'))}</p>
        <div class="alert alert-warning">${icon('info')}<div class="small">${esc(t('Demo: use the sample patient number'))} <b class="mono">0917 123 4567</b> (Lorna Mercado)</div></div>
        <form data-submit="p.loginMobile" class="stack" novalidate>${field({ name: 'mobile', label: t('Mobile number'), type: 'tel', inputmode: 'numeric', placeholder: '09XX XXX XXXX', value: L.mobile || '09171234567', autocomplete: 'tel', required: true, hint: t('11 digits, starting with 09') })}
        <button class="btn btn-primary btn-lg btn-block" type="submit">${esc(t('Send code'))}${icon('arrowRight')}</button></form>
        <div class="divider"></div><p class="center" style="margin:0">${esc(t('New to DYH?'))} <a href="#/patient/register" class="strong">${esc(t('Create an account'))}</a></p>`
      : `<h1>${esc(t('Enter the code'))}</h1><p class="muted">${esc(t('We sent a 6-digit code to {m}.', { m: UI.maskMobile(L.mobile) }))}</p>
        <div class="alert alert-warning">${icon('smartphone')}<div><b>${esc(t('Demo code'))}: 123456</b></div></div>
        <form data-submit="p.loginOtp" class="stack" novalidate>${otpInputs()}<div id="otp-err"></div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">${icon('check')}${esc(t('Verify and sign in'))}</button></form>
        <div class="row-between"><button class="btn btn-ghost btn-sm" data-act="p.loginBack">${icon('chevronLeft')}${esc(t('Change number'))}</button><button class="btn btn-ghost btn-sm" data-act="p.resend">${icon('refresh')}${esc(t('Resend code'))}</button></div>`}
      <p class="xs muted center" style="margin:0">${icon('lock')} ${esc(t('Your information is protected under the Data Privacy Act of 2012.'))}</p>
    </div>`, login);
    bindOtp();
  }
  function otpInputs() { return `<div class="otp" role="group" aria-label="${attr(t('6-digit code'))}">${[0, 1, 2, 3, 4, 5].map((i) => `<input inputmode="numeric" maxlength="1" aria-label="${attr(t('Digit {n}', { n: i + 1 }))}" data-otp="${i}" autocomplete="${i === 0 ? 'one-time-code' : 'off'}">`).join('')}</div>`; }
  function bindOtp() {
    const ins = [...document.querySelectorAll('[data-otp]')]; if (!ins.length) return;
    ins[0].focus();
    ins.forEach((el, i) => {
      el.addEventListener('input', () => { el.value = el.value.replace(/\D/g, '').slice(-1); if (el.value && ins[i + 1]) ins[i + 1].focus(); });
      el.addEventListener('keydown', (e) => { if (e.key === 'Backspace' && !el.value && ins[i - 1]) ins[i - 1].focus(); });
      el.addEventListener('paste', (e) => { const v = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6); if (v.length) { e.preventDefault(); ins.forEach((x, k) => { x.value = v[k] || ''; }); ins[Math.min(5, v.length - 1)].focus(); } });
    });
  }
  const otpValue = () => [...document.querySelectorAll('[data-otp]')].map((x) => x.value).join('');
  const otpError = (msg) => { document.getElementById('otp-err').innerHTML = `<div class="field-error" role="alert">${icon('alert')}<span>${esc(msg)}</span></div>`; };
  UI.on('p.loginMobile', async (form) => {
    const m = form.mobile.value.replace(/[\s-]/g, '');
    if (!UI.validMobile(m)) return UI.setErrors(form, { mobile: t('Please enter an 11-digit mobile number starting with 09.') });
    const p = db().patients.find((x) => x.mobile === m && x.hasAccount !== false);
    await UI.busy(form.querySelector('button'), () => UI.wait(700), t('Sending code…'));
    if (!p) return UI.setErrors(form, { mobile: t('We could not find an account with this number. Please check it, or create a new account.') });
    PS.login.mobile = m; PS.login.step = 'otp'; login(); toast(t('Code sent to {m}.', { m: UI.maskMobile(m) }), 'success');
  });
  UI.on('p.loginBack', () => { PS.login.step = 'mobile'; login(); });
  UI.on('p.resend', async (el) => { await UI.busy(el, () => UI.wait(600), t('Sending…')); toast(t('A new code was sent.'), 'success'); });
  UI.on('p.loginOtp', async (form) => {
    const v = otpValue();
    if (v.length < 6) return otpError(t('Please enter all 6 digits.'));
    await UI.busy(form.querySelector('button[type=submit]'), () => UI.wait(600), t('Verifying…'));
    if (v !== '123456') return otpError(t('That code is not correct. Please check the SMS and try again.'));
    const p = db().patients.find((x) => x.mobile === PS.login.mobile);
    db().session.patientId = p.id; p.hasAccount = true; Store.save(true);
    MockAPI.log(Q.shortName(p), 'patient', 'Signed in to patient app', p.code); Store.save(true);
    const to = PS.login.returnTo; PS.login = { step: 'mobile', mobile: '', returnTo: null };
    toast(t('Welcome back, {name}!', { name: p.first }), 'success');
    Router.go('/patient/' + (to || 'home'));
  });

  /* ---------- Registration ---------- */
  function register() {
    if (!PS.reg) PS.reg = { step: 1, data: { first: '', middle: '', last: '', bday: '', sex: '', mobile: '', consent: false }, link: null };
    const R = PS.reg, D = R.data;
    const steps = ['Your details', 'Mobile number', 'Privacy', 'Verify', 'Hospital record'];
    const stepper = R.step <= 5 ? `<ol class="stepper">${steps.map((s, i) => `<li class="${i + 1 < R.step ? 'done' : i + 1 === R.step ? 'current' : ''}"><span>${esc(t(s))}</span></li>`).join('')}</ol>` : '';
    let body = '';
    if (R.step === 1) {
      const age = UI.ageFrom(D.bday);
      body = `<h1>${esc(t('Create your account'))}</h1><p class="muted">${esc(t('Please type your name exactly as it appears on a valid ID.'))}</p>
      <form data-submit="p.reg1" class="stack" novalidate><div class="form-grid">
        ${field({ name: 'first', label: t('First name'), value: D.first, required: true, autocomplete: 'given-name' })}
        ${field({ name: 'middle', label: t('Middle name'), value: D.middle, hint: t('Leave blank if none'), autocomplete: 'additional-name' })}
        ${field({ name: 'last', label: t('Last name'), value: D.last, required: true, autocomplete: 'family-name', span2: true })}
        ${field({ name: 'bday', label: t('Birthday'), type: 'date', value: D.bday, required: true, attrs: `max="${UI.today()}" data-input="p.regAge"` })}
        <div class="field"><label>${esc(t('Age'))}</label><div class="input" id="reg-age" style="background:var(--gray-50);display:flex;align-items:center" aria-live="polite">${age != null && age >= 0 ? esc(t('{n} years old', { n: age })) : '<span class="muted">' + esc(t('Calculated from birthday')) + '</span>'}</div></div>
        ${field({ name: 'sex', label: t('Sex'), type: 'select', value: D.sex, required: true, options: [['', t('Select…')], ['F', t('Female')], ['M', t('Male')]] })}
      </div><div class="row-between"><a class="btn btn-secondary btn-lg" href="#/patient/login">${esc(t('I have an account'))}</a><button class="btn btn-primary btn-lg" type="submit">${esc(t('Continue'))}${icon('chevronRight')}</button></div></form>`;
    } else if (R.step === 2) {
      body = `<h1>${esc(t('Your mobile number'))}</h1><p class="muted">${esc(t('We use this to send your verification code, queue alerts and appointment reminders.'))}</p>
      <form data-submit="p.reg2" class="stack" novalidate>${field({ name: 'mobile', label: t('Mobile number'), type: 'tel', inputmode: 'numeric', value: D.mobile, placeholder: '09XX XXX XXXX', required: true, hint: t('11 digits, starting with 09') })}
      <div class="row-between"><button type="button" class="btn btn-secondary btn-lg" data-act="p.regStep" data-step="1">${icon('chevronLeft')}${esc(t('Back'))}</button><button class="btn btn-primary btn-lg" type="submit">${esc(t('Continue'))}${icon('chevronRight')}</button></div></form>`;
    } else if (R.step === 3) {
      body = `<h1>${esc(t('Data privacy consent'))}</h1>
      <div class="card-flat small stack-sm" style="max-height:280px;overflow:auto" tabindex="0">
        <p><b>${esc(t('How DYH uses your information'))}</b></p>
        <p>${esc(t('Dr. Yanga’s Hospital Inc. collects your name, birthday, sex, contact details and medical information to register you, manage appointments and queues, and keep your medical records, in accordance with Republic Act No. 10173 (Data Privacy Act of 2012).'))}</p>
        <p>${esc(t('Your medical records are only seen by your authorized doctors and staff. Every access is recorded. We will never sell your information.'))}</p>
        <p>${esc(t('You may ask to see, correct, or delete your information by contacting the hospital’s Data Protection Officer.'))}</p></div>
      <form data-submit="p.reg3" class="stack" novalidate><div class="field"><label class="check"><input type="checkbox" name="consent" ${D.consent ? 'checked' : ''}><span>${esc(t('I have read and I agree to the collection and use of my personal information as described above.'))}</span></label></div>
      <div class="row-between"><button type="button" class="btn btn-secondary btn-lg" data-act="p.regStep" data-step="2">${icon('chevronLeft')}${esc(t('Back'))}</button><button class="btn btn-primary btn-lg" type="submit">${esc(t('I agree, continue'))}${icon('chevronRight')}</button></div></form>`;
    } else if (R.step === 4) {
      body = `<h1>${esc(t('Verify your number'))}</h1><p class="muted">${esc(t('We sent a 6-digit code to {m}.', { m: UI.maskMobile(D.mobile) }))}</p>
      <div class="alert alert-warning">${icon('smartphone')}<div><b>${esc(t('Demo code'))}: 123456</b></div></div>
      <form data-submit="p.reg4" class="stack" novalidate>${otpInputs()}<div id="otp-err"></div><div class="row-between"><button type="button" class="btn btn-ghost" data-act="p.resend">${icon('refresh')}${esc(t('Resend code'))}</button><button class="btn btn-primary btn-lg" type="submit">${esc(t('Verify'))}</button></div></form>`;
    } else if (R.step === 5) {
      body = `<h1>${esc(t('Have you been a DYH patient before?'))}</h1><p class="muted">${esc(t('If you already have a hospital record, we can link it so you can see your past consultations.'))}</p>
      <div class="grid-2"><button class="p-action" data-act="p.regLinkYes" style="min-height:120px"><span class="ic">${icon('userCheck', 'icon-lg')}</span><span>${esc(t('Yes, link my record'))}<small>${esc(t('I have a DYH patient code'))}</small></span></button>
      <button class="p-action" data-act="p.regNew" style="min-height:120px"><span class="ic">${icon('userPlus', 'icon-lg')}</span><span>${esc(t('No, I am new'))}<small>${esc(t('Create a new patient record'))}</small></span></button></div>
      ${R.link ? `<form data-submit="p.regLink" class="card stack" novalidate>${field({ name: 'code', label: t('Patient code'), placeholder: 'DYH-104501', required: true, hint: t('Printed on your hospital card or queue slip') })}<p class="xs muted">${esc(t('Demo: try DYH-104507 (Maricel Tolentino) with her birthday 1989-12-12, or any other code to see the pending verification flow.'))}</p><button class="btn btn-primary btn-lg" type="submit">${esc(t('Link my record'))}</button></form>` : ''}`;
    } else {
      body = `<div class="center stack"><div style="width:88px;height:88px;border-radius:50%;background:var(--success-bg);color:var(--success);display:flex;align-items:center;justify-content:center;margin:0 auto">${icon('checkCircle', 'icon-xl')}</div>
        <h1>${esc(t('Your account is ready!'))}</h1><p>${esc(R.done)}</p>
        <div class="row" style="justify-content:center"><a class="btn btn-primary btn-lg" href="#/patient/home" data-act="p.regFinish">${esc(t('Go to home'))}</a></div></div>`;
    }
    shell('profile', `<div class="card stack" style="max-width:720px;margin:8px auto;padding:28px">${stepper}${body}</div>`, register);
    bindOtp();
  }
  UI.on('p.regAge', (el) => { const a = UI.ageFrom(el.value); document.getElementById('reg-age').textContent = a != null && a >= 0 && a < 130 ? t('{n} years old', { n: a }) : t('Calculated from birthday'); });
  UI.on('p.regStep', (el) => { PS.reg.step = Number(el.dataset.step); register(); });
  UI.on('p.reg1', (form) => {
    const d = UI.formData(form); const e = {};
    if (!d.first) e.first = t('Please enter your first name.');
    if (!d.last) e.last = t('Please enter your last name.');
    const a = UI.ageFrom(d.bday);
    if (!d.bday) e.bday = t('Please enter your birthday.'); else if (a < 0 || a > 120) e.bday = t('Please check your birthday.'); else if (a < 18) e.bday = t('Patients under 18 should be registered by a parent or guardian at the front desk.');
    if (!d.sex) e.sex = t('Please select an option.');
    if (!UI.setErrors(form, e)) return;
    Object.assign(PS.reg.data, d); PS.reg.step = 2; register();
  });
  UI.on('p.reg2', async (form) => {
    const m = form.mobile.value.replace(/[\s-]/g, '');
    if (!UI.validMobile(m)) return UI.setErrors(form, { mobile: t('Please enter an 11-digit mobile number starting with 09.') });
    if (db().patients.some((p) => p.mobile === m && p.hasAccount)) return UI.setErrors(form, { mobile: t('This number already has an account. Please sign in instead.') });
    PS.reg.data.mobile = m; PS.reg.step = 3; register();
  });
  UI.on('p.reg3', async (form) => {
    if (!form.consent.checked) return UI.setErrors(form, { consent: t('Please agree to the data privacy terms to create an account.') });
    PS.reg.data.consent = true;
    await UI.busy(form.querySelector('button[type=submit]'), () => UI.wait(700), t('Sending code…'));
    PS.reg.step = 4; register(); toast(t('Code sent to {m}.', { m: UI.maskMobile(PS.reg.data.mobile) }), 'success');
  });
  UI.on('p.reg4', async (form) => {
    const v = otpValue(); if (v.length < 6) return otpError(t('Please enter all 6 digits.'));
    await UI.busy(form.querySelector('button[type=submit]'), () => UI.wait(600), t('Verifying…'));
    if (v !== '123456') return otpError(t('That code is not correct. Please check the SMS and try again.'));
    PS.reg.step = 5; register();
  });
  UI.on('p.regLinkYes', () => { PS.reg.link = true; register(); setTimeout(() => { const i = document.querySelector('[name=code]'); if (i) i.focus(); }, 50); });
  UI.on('p.regLink', async (form) => {
    const code = form.code.value.trim().toUpperCase();
    if (!/^DYH-\d{6}$/.test(code)) return UI.setErrors(form, { code: t('Please enter the code in this format: DYH-123456.') });
    await UI.busy(form.querySelector('button'), () => UI.wait(800), t('Checking record…'));
    const D = PS.reg.data;
    const match = db().patients.find((p) => p.code === code && p.bday === D.bday && p.last.toLowerCase() === D.last.toLowerCase());
    if (match) {
      match.mobile = D.mobile; match.hasAccount = true; db().session.patientId = match.id;
      MockAPI.log(Q.shortName(match), 'patient', 'Linked app account to hospital record', code); Store.save(true);
      PS.reg.done = t('Your account is linked to hospital record {code}. You can now see your past consultations.', { code });
    } else {
      const p = MockAPI.registerPatient({ first: D.first, middle: D.middle, last: D.last, bday: D.bday, sex: D.sex, mobile: D.mobile, hasAccount: true, address: { region: '', province: '', city: '', brgy: '', street: '' }, emergency: { name: '', mobile: '' } }, 'Patient app');
      db().verifications.unshift({ id: UI.uid('v'), patientId: p.id, type: 'link', requestedCode: code, note: 'Name/birthday did not match automatically', at: Date.now(), status: 'pending' });
      db().session.patientId = p.id; Store.save(true);
      PS.reg.done = t('We could not match code {code} automatically. Your request was sent to the hospital for verification (usually within 1 working day). You can already book appointments.', { code });
    }
    PS.reg.step = 6; register();
  });
  UI.on('p.regNew', async (el) => {
    await UI.busy(el, () => UI.wait(700), t('Creating account…'));
    const D = PS.reg.data;
    const p = MockAPI.registerPatient({ first: D.first, middle: D.middle, last: D.last, bday: D.bday, sex: D.sex, mobile: D.mobile, hasAccount: true, address: { region: '', province: '', city: '', brgy: '', street: '' }, emergency: { name: '', mobile: '' } }, 'Patient app');
    db().verifications.unshift({ id: UI.uid('v'), patientId: p.id, type: 'new', requestedCode: '', note: 'New app registration — mobile verified', at: Date.now(), status: 'pending' });
    db().session.patientId = p.id; Store.save(true);
    PS.reg.done = t('Your new patient code is {code}. Please show a valid ID at your first visit to complete verification.', { code: p.code });
    PS.reg.step = 6; register();
  });
  UI.on('p.regFinish', () => { PS.reg = null; Router.go('/patient/home'); });

  /* ---------- Profile & notifications ---------- */
  function profile() {
    const p = me();
    if (!p) { PS.login.step = 'mobile'; return login(); }
    const pr = Q.priorityOf(p);
    const v = db().verifications.find((x) => x.patientId === p.id && x.status === 'pending');
    shell('profile', `<div class="stack-lg" style="max-width:760px;margin:0 auto">
      <section class="card row-nowrap">${avatar(p, 72)}<div class="grow"><h1 style="margin:0">${esc(Q.fullName(p))}</h1><div class="muted">${esc(t('Patient code'))}: <b class="mono">${esc(p.code)}</b></div><div class="row" style="margin-top:6px">${v ? `<span class="badge badge-warning">${icon('clock')}${esc(t('Verification pending'))}</span>` : `<span class="badge badge-success">${icon('shield')}${esc(t('Verified hospital record'))}</span>`}${priorityBadge(pr)}</div></div></section>
      <section class="card"><h2>${esc(t('My details'))}</h2><dl class="grid-2" style="margin:0">
        ${[[t('Birthday'), `${fmtDate(p.bday)} (${t('{n} years old', { n: UI.ageFrom(p.bday) })})`], [t('Sex'), p.sex === 'F' ? t('Female') : t('Male')], [t('Mobile number'), UI.maskMobile(p.mobile)], [t('Address'), [p.address.street, p.address.brgy, p.address.city, p.address.province].filter(Boolean).join(', ') || '—'], [t('Emergency contact'), p.emergency.name ? `${p.emergency.name} · ${UI.maskMobile(p.emergency.mobile)}` : '—']].map(([k, val]) => `<div><dt class="small muted strong">${esc(k)}</dt><dd style="margin:0">${esc(val)}</dd></div>`).join('')}</dl>
        <p class="small muted" style="margin:12px 0 0">${esc(t('To correct your details, please visit the front desk with a valid ID.'))}</p></section>
      <section class="card stack"><h2>${esc(t('Display & language'))}</h2>
        <div class="row-between"><span class="strong">${esc(t('Language'))}</span>${App.langToggle()}</div>
        <div class="row-between"><span class="strong">${esc(t('Text size'))}</span>${App.textSize()}</div></section>
      <section class="card stack"><h2>${esc(t('Notifications'))}</h2>
        <label class="switch"><input type="checkbox" checked data-change="p.pref"><span class="track"></span><span>${esc(t('SMS queue alerts (when my turn is near)'))}</span></label>
        <label class="switch"><input type="checkbox" checked data-change="p.pref"><span class="track"></span><span>${esc(t('SMS appointment reminders'))}</span></label>
        <label class="switch"><input type="checkbox" data-change="p.pref"><span class="track"></span><span>${esc(t('Hospital announcements'))}</span></label></section>
      <button class="btn btn-danger-outline btn-lg btn-block" data-act="p.logout">${icon('logout')}${esc(t('Sign out'))}</button>
    </div>`, profile);
  }
  UI.on('p.pref', (el) => toast(el.checked ? t('Notification turned on.') : t('Notification turned off.'), 'success'));
  UI.on('p.logout', async () => {
    const ok = await UI.confirm({ title: t('Sign out?'), message: t('You can sign in again anytime with your mobile number.'), confirmText: t('Sign out'), icon: 'logout' });
    if (!ok) return; db().session.patientId = null; PS.recordsUnlocked = false; PS.share = null; Store.save(true); toast(t('You have signed out.'), 'success'); Router.go('/patient/home');
  });
  function notifications() {
    const p = me(); if (!p) return signInPrompt('home', t('Sign in to see notifications'), '');
    const list = db().notifications.filter((n) => n.patientId === p.id);
    shell('home', `<div class="stack" style="max-width:720px;margin:0 auto"><div class="row-between"><h1 style="margin:0">${esc(t('Notifications'))}</h1>${list.some((n) => !n.read) ? `<button class="btn btn-ghost btn-sm" data-act="p.readAll">${icon('check')}${esc(t('Mark all as read'))}</button>` : ''}</div>
      ${list.length ? `<div class="card" style="padding:0">${list.map((n) => `<div class="q-row" style="${n.read ? '' : 'background:var(--light-blue)'}"><span class="role-ic" style="width:44px;height:44px;flex:none">${icon('bell')}</span><div class="grow"><b>${esc(n.title)}</b>${n.read ? '' : ` <span class="badge badge-navy">${esc(t('New'))}</span>`}<div class="small">${esc(n.body)}</div><div class="xs muted">${esc(UI.fmtDateTime(n.at))}</div></div></div>`).join('')}</div>` : UI.emptyState('bell', t('No notifications yet'), t('Queue alerts and appointment updates will appear here.'))}</div>`, notifications, true);
  }
  UI.on('p.readAll', () => { db().notifications.forEach((n) => { if (n.patientId === me().id) n.read = true; }); Store.save(true); notifications(); });

  /* ---------- Routes ---------- */
  Router.add('patient', (parts) => {
    const [page, id] = parts;
    switch (page) {
      case 'doctors': return doctors();
      case 'doctor': return doctorProfile(id);
      case 'guide': return guide();
      case 'book': return book();
      case 'queue': return queue();
      case 'appointments': return appointments();
      case 'records': return records();
      case 'record': return recordDetail(id);
      case 'login': if (me()) return Router.go('/patient/home'); return login();
      case 'register': return register();
      case 'profile': return profile();
      case 'notifications': return notifications();
      default: return home();
    }
  });
  window.Patient = { rescheduleModal, rateModal };
})();
