/* ==========================================================================
   DYH ERM — Hospital Kiosk (walk-in registration and queueing)
   Full-screen, oversized controls, one task per screen. Personal data is
   cleared after every completed or abandoned session. No clinical data is
   ever shown on this public screen.
   ========================================================================== */
(function () {
  'use strict';
  const { icon, esc, attr, avatar, statusBadge, toast } = UI;
  const db = () => Store.db;

  let KS = fresh();
  let slideIdx = 0, slideTimer = null, idleTimer = null, warnOpen = false, lastActivity = Date.now(), ticketTimer = null;
  function fresh() { return { step: 'welcome', patient: null, isNew: false, data: { first: '', middle: '', last: '', bm: '', bd: '', by: '', sex: '', mobile: '', noMobile: false, ecName: '', ecMobile: '', region: '', province: '', city: '', brgy: '', street: '', pwd: false, pregnant: false, consent: false }, code: '', spec: '', q: '', entry: null }; }
  const onKiosk = () => location.hash.startsWith('#/kiosk');

  /* ---------- Session reset & inactivity ---------- */
  function resetSession(msg) {
    KS = fresh();
    while (UI.topModal()) UI.closeModal();
    warnOpen = false; clearInterval(ticketTimer);
    if (onKiosk()) render();
    if (msg) toast(msg, 'warning');
  }
  ['pointerdown', 'keydown', 'input'].forEach((ev) => document.addEventListener(ev, () => { lastActivity = Date.now(); }, true));
  function startIdleWatch() {
    clearInterval(idleTimer);
    idleTimer = setInterval(() => {
      if (!onKiosk()) { clearInterval(idleTimer); return; }
      if (KS.step === 'welcome' || KS.step === 'ticket') return;
      const s = db().settings; const idle = (Date.now() - lastActivity) / 1000;
      if (idle >= s.kioskTimeout) { resetSession(t('Session ended due to inactivity. Your information was cleared.')); return; }
      if (idle >= s.kioskTimeout - s.kioskWarn && !warnOpen) showIdleWarning();
      if (warnOpen) { const el = document.getElementById('idle-count'); if (el) el.textContent = Math.max(0, Math.ceil(s.kioskTimeout - idle)); }
    }, 500);
  }
  function showIdleWarning() {
    warnOpen = true;
    UI.openModal({ title: t('Are you still there?'), size: 'sm', dismissible: false,
      labelledIcon: `<span style="color:var(--warning)">${icon('clock', 'icon-xl')}</span>`,
      body: `<p style="font-size:1.1em">${esc(t('For your privacy, this screen will restart in'))}</p><div style="font-size:3.5em;font-weight:800;color:var(--navy);text-align:center" id="idle-count">${db().settings.kioskWarn}</div><p class="center">${esc(t('seconds'))}</p>`,
      footer: `<button class="btn btn-secondary btn-xl" data-act="k.idleEnd">${esc(t('Start over'))}</button><button class="btn btn-primary btn-xl" data-act="k.idleStay">${esc(t('I’m still here'))}</button>`,
      onClose: () => { warnOpen = false; } });
  }
  UI.on('k.idleStay', () => { lastActivity = Date.now(); UI.closeModal(); });
  UI.on('k.idleEnd', () => resetSession());

  /* ---------- Shell ---------- */
  const FLOW = ['Identify', 'Details', 'Choose doctor', 'Queue number'];
  function flowIndex() {
    if (['type', 'existing', 'scan', 'keypad', 'confirmMe'].includes(KS.step)) return 0;
    if (KS.step.startsWith('new')) return 1;
    if (KS.step === 'doctor') return 2;
    return 3;
  }
  function shell(content, opts) {
    opts = opts || {};
    App.mount(`${App.ribbon()}<div class="shell-kiosk">
      <header class="k-header">${App.brand(t('Patient Kiosk'))}<div class="grow"></div>
        <div class="k-lang" role="group" aria-label="${attr(t('Language'))}"><button data-act="k.lang" data-lang="en" aria-pressed="${I18N.lang === 'en'}">English</button><button data-act="k.lang" data-lang="fil" aria-pressed="${I18N.lang === 'fil'}">Filipino</button></div>
        ${KS.step !== 'welcome' ? `<button class="btn btn-outline-on-navy btn-lg" style="color:#fff;border-color:rgba(255,255,255,.5);background:transparent" data-act="k.restart">${icon('refresh')}${esc(t('Start over'))}</button>` : ''}
        <button class="btn btn-lg k-assist" data-act="k.assist">${icon('help')}${esc(t('Need assistance?'))}</button>
      </header>
      ${opts.raw ? content : `<main class="k-body fade-in" id="main">
        ${opts.noSteps ? '' : `<div class="k-steps" aria-label="${attr(t('Progress'))}">${FLOW.map((s, i) => `<div class="${i < flowIndex() ? 'done' : i === flowIndex() ? 'on' : ''}">${esc(t(s))}</div>`).join('')}</div>`}
        ${content}</main>`}
    </div>`, render);
  }

  /* ---------- Screens ---------- */
  function render() {
    clearInterval(slideTimer);
    startIdleWatch();
    const s = KS.step;
    if (s === 'welcome') return welcome();
    if (s === 'type') return chooseType();
    if (s === 'existing') return existing();
    if (s === 'scan') return scan();
    if (s === 'keypad') return keypad();
    if (s === 'confirmMe') return confirmMe();
    if (s.startsWith('new')) return newPatient();
    if (s === 'doctor') return chooseDoctor();
    if (s === 'ticket') return ticket();
  }

  function welcome() {
    const slides = db().announcements.filter((a) => a.active && (a.audience === 'all' || a.audience === 'kiosk'));
    const sl = slides[slideIdx % Math.max(1, slides.length)];
    shell(`<div class="k-emergency" role="alert">${icon('alert')}<div>${esc(t('EMERGENCY? Do not use this kiosk. Go directly to the Emergency Room or tell any staff member now.'))}</div></div>
      <div class="welcome" data-act="k.start" role="button" tabindex="0" aria-label="${attr(t('Tap anywhere to start'))}">
        <div class="welcome-left">
          <img class="logo" src="assets/dyh-logo.svg" alt="Dr. Yanga's Hospital">
          <div><h1>${esc(t('Welcome to Dr. Yanga’s Hospital'))}</h1><p style="font-size:1em;color:var(--text-muted);margin-top:12px">${esc(t('Register and get your queue number here.'))}</p></div>
          <span class="tap">${icon('hand', 'icon-lg')}${esc(t('Tap anywhere to start'))}</span>
          <div class="row" style="font-size:.7em">
            <span class="muted strong">${esc(t('Choose language:'))}</span>
            <button class="btn btn-secondary btn-lg" data-act="k.lang" data-lang="en" aria-pressed="${I18N.lang === 'en'}">${I18N.lang === 'en' ? icon('check') : ''}English</button>
            <button class="btn btn-secondary btn-lg" data-act="k.lang" data-lang="fil" aria-pressed="${I18N.lang === 'fil'}">${I18N.lang === 'fil' ? icon('check') : ''}Filipino</button>
          </div>
          ${db().settings.feedbackOnKiosk ? `<button class="btn btn-ghost" style="align-self:flex-start;font-size:.7em" data-act="k.rate">${icon('star')}${esc(t('Rate your hospital experience'))}</button>` : ''}
        </div>
        <div class="welcome-right">${sl ? `<div class="slide-art">${icon(sl.icon || 'megaphone')}</div><div class="slide" aria-live="polite"><span class="badge badge-navy" style="background:rgba(255,255,255,.14);margin-bottom:12px">${esc(sl.kind === 'ad' ? t('Featured') : t('Announcement'))}</span><h2>${esc(I18N.pick(sl.title))}</h2><p>${esc(I18N.pick(sl.body))}</p></div><div class="dots">${slides.map((_, i) => `<span class="${i === slideIdx % slides.length ? 'on' : ''}"></span>`).join('')}</div>` : ''}</div>
      </div>`, { raw: true });
    if (slides.length > 1) slideTimer = setInterval(() => { if (!onKiosk() || KS.step !== 'welcome') return clearInterval(slideTimer); slideIdx++; welcome(); }, 6000);
  }

  function nav(back, next, nextLabel, nextDisabled) {
    return `<div class="k-foot">${back ? `<button class="btn btn-secondary" data-act="k.go" data-step="${back}">${icon('arrowLeft', 'icon-lg')}${esc(t('Back'))}</button>` : '<span></span>'}
      ${next ? `<button class="btn btn-primary" data-act="${next}" ${nextDisabled ? 'disabled' : ''}>${esc(nextLabel || t('Next'))}${icon('arrowRight', 'icon-lg')}</button>` : ''}</div>`;
  }

  function chooseType() {
    shell(`<h1 class="k-title">${esc(t('Have you visited DYH before?'))}</h1><p class="k-sub">${esc(t('Tap one of the choices below.'))}</p>
      <div class="grid-2">
        <button class="k-choice" data-act="k.new"><span class="ic">${icon('userPlus')}</span>${esc(t('New Patient'))}<small>${esc(t('First time at DYH'))}</small></button>
        <button class="k-choice" data-act="k.go" data-step="existing"><span class="ic">${icon('userCheck')}</span>${esc(t('Existing Patient'))}<small>${esc(t('I have a DYH patient code or QR'))}</small></button>
      </div>${nav('welcome')}`);
  }

  function existing() {
    shell(`<h1 class="k-title">${esc(t('How would you like to find your record?'))}</h1><p class="k-sub">${esc(t('Your QR code is on your DYH patient card or in the Patient App.'))}</p>
      <div class="grid-2">
        <button class="k-choice" data-act="k.go" data-step="scan"><span class="ic">${icon('qr')}</span>${esc(t('Scan my QR code'))}<small>${esc(t('Hold your card or phone to the camera'))}</small></button>
        <button class="k-choice" data-act="k.go" data-step="keypad"><span class="ic">${icon('keypad')}</span>${esc(t('Type my patient code'))}<small>${esc(t('Use the number pad on screen'))}</small></button>
      </div>${nav('type')}`);
  }

  function scan() {
    shell(`<h1 class="k-title">${esc(t('Scan your QR code'))}</h1><p class="k-sub">${esc(t('Hold your QR code in front of the camera above the screen.'))}</p>
      <div style="display:flex;justify-content:center"><div style="width:360px;max-width:100%;aspect-ratio:1;border-radius:24px;background:#0E1A2B;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center">
        <div style="position:absolute;inset:40px;border:4px solid rgba(255,255,255,.85);border-radius:16px"></div>
        <div style="position:absolute;left:40px;right:40px;height:3px;background:#5FB3FF;box-shadow:0 0 12px #5FB3FF;animation:scanline 2s ease-in-out infinite alternate;top:40px"></div>
        <span style="color:#9FB5CF;font-size:.7em;text-align:center;padding:0 60px">${icon('camera', 'icon-xl')}<br>${esc(t('Looking for a QR code…'))}</span></div></div>
      <style>@keyframes scanline{to{top:calc(100% - 44px)}}</style>
      <div class="center" style="margin-top:20px"><span class="demo-tag" style="font-size:.6em">${esc(t('Demo: camera scanning is simulated'))}</span><br><button class="btn btn-soft btn-lg" style="margin-top:10px" data-act="k.simScan">${icon('scan')}${esc(t('Simulate a successful scan'))}</button></div>
      ${nav('existing')}`);
  }

  function keypad() {
    const code = KS.code;
    shell(`<h1 class="k-title">${esc(t('Type your patient code'))}</h1><p class="k-sub">${esc(t('Enter the 6 numbers after “DYH-”. Example: DYH-104501.'))}</p>
      <div class="grid-2" style="align-items:start">
        <div class="stack"><div class="k-display" aria-live="polite">DYH-${esc(code.padEnd(6, '•'))}</div><div id="kp-err"></div>
          <p class="small muted" style="font-size:.65em">${esc(t('Demo: try 104501'))}</p></div>
        <div class="keypad" role="group" aria-label="${attr(t('Number pad'))}">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map((n) => `<button data-act="k.key" data-k="${n}">${n}</button>`).join('')}<button data-act="k.key" data-k="clear" aria-label="${attr(t('Clear'))}" style="font-size:.8em">${esc(t('Clear'))}</button><button data-act="k.key" data-k="0">0</button><button data-act="k.key" data-k="back" aria-label="${attr(t('Delete'))}">⌫</button></div>
      </div>${nav('existing', 'k.findCode', t('Find my record'), code.length !== 6)}`);
  }
  UI.on('k.key', (el) => {
    const k = el.dataset.k;
    if (k === 'clear') KS.code = ''; else if (k === 'back') KS.code = KS.code.slice(0, -1); else if (KS.code.length < 6) KS.code += k;
    keypad();
  });
  UI.on('k.findCode', async (el) => {
    await UI.busy(el, () => UI.wait(600), t('Searching…'));
    const p = db().patients.find((x) => x.code === 'DYH-' + KS.code);
    if (!p) { keypad(); document.getElementById('kp-err').innerHTML = `<div class="alert alert-error">${icon('alert')}<div>${esc(t('We could not find this code. Please check the number, or ask the front desk for help.'))}</div></div>`; return; }
    KS.patient = p; KS.step = 'confirmMe'; render();
  });
  UI.on('k.simScan', async (el) => { await UI.busy(el, () => UI.wait(900), t('Reading QR…')); KS.patient = Q.patient('p1'); KS.step = 'confirmMe'; render(); });

  function confirmMe() {
    const p = KS.patient; const pr = Q.priorityOf(p);
    const b = UI.parseISO(p.bday);
    shell(`<h1 class="k-title">${esc(t('Is this you?'))}</h1><p class="k-sub">${esc(t('For your privacy, only part of your information is shown.'))}</p>
      <div class="card row-nowrap" style="padding:32px;gap:28px;max-width:760px">${avatar(p, 120)}<div class="stack-sm">
        <div style="font-size:1.6em;font-weight:800;color:var(--navy)">${esc(UI.maskName(p.first, p.last))}</div>
        <div>${esc(t('Born'))}: ${esc(b.toLocaleDateString(I18N.lang === 'fil' ? 'fil-PH' : 'en-PH', { month: 'long', year: 'numeric' }))}</div>
        <div class="mono">${esc(p.code)}</div>${pr ? UI.priorityBadge(pr, true) : ''}</div></div>
      <div class="k-foot"><button class="btn btn-secondary" data-act="k.notMe">${icon('x', 'icon-lg')}${esc(t('No, this is not me'))}</button><button class="btn btn-success" data-act="k.isMe">${icon('check', 'icon-lg')}${esc(t('Yes, this is me'))}</button></div>`);
  }
  UI.on('k.notMe', () => { KS.patient = null; KS.code = ''; KS.step = 'existing'; render(); toast(t('Please try again or ask the front desk for help.'), 'warning'); });
  UI.on('k.isMe', () => { KS.step = 'doctor'; render(); });

  /* ---------- New patient registration (one topic per screen) ---------- */
  const NEW_STEPS = ['newName', 'newBirth', 'newContact', 'newAddress', 'newPriority', 'newConsent'];
  UI.on('k.new', () => { KS.isNew = true; KS.step = 'newName'; render(); });
  function kField(name, label, opts) { return UI.field({ name, label, value: KS.data[name], ...opts, attrs: `data-input="k.field" ${(opts && opts.attrs) || ''}` }); }
  UI.on('k.field', (el) => { KS.data[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
  function newPatient() {
    const D = KS.data; const i = NEW_STEPS.indexOf(KS.step);
    const back = i === 0 ? 'type' : NEW_STEPS[i - 1];
    const sub = `<p class="small strong" style="color:var(--blue);font-size:.7em;margin:0 0 4px">${esc(t('New patient · Step {a} of {b}', { a: i + 1, b: NEW_STEPS.length }))}</p>`;
    let body = '';
    if (KS.step === 'newName') {
      body = `${sub}<h1 class="k-title">${esc(t('What is your name?'))}</h1><p class="k-sub">${esc(t('Please type your name as it appears on your ID.'))}</p>
        <form class="stack" id="kform" novalidate>${kField('first', t('First name'), { required: true, autocomplete: 'off' })}${kField('middle', t('Middle name'), { hint: t('Leave blank if none'), autocomplete: 'off' })}${kField('last', t('Last name'), { required: true, autocomplete: 'off' })}</form>`;
    } else if (KS.step === 'newBirth') {
      const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
      const yr = new Date().getFullYear();
      const age = birthISO() ? UI.ageFrom(birthISO()) : null;
      body = `${sub}<h1 class="k-title">${esc(t('When is your birthday?'))}</h1><p class="k-sub">${esc(t('Choose the month, day and year.'))}</p>
        <form class="stack" id="kform" novalidate><div class="grid-3">
          ${UI.field({ name: 'bm', label: t('Month'), type: 'select', value: D.bm, required: true, options: [['', t('Month')], ...months.map((m, k) => [String(k + 1), t(m)])], attrs: 'data-change="k.birth"' })}
          ${UI.field({ name: 'bd', label: t('Day'), type: 'select', value: D.bd, required: true, options: [['', t('Day')], ...Array.from({ length: 31 }, (_, k) => String(k + 1))], attrs: 'data-change="k.birth"' })}
          ${UI.field({ name: 'by', label: t('Year'), type: 'select', value: D.by, required: true, options: [['', t('Year')], ...Array.from({ length: 110 }, (_, k) => String(yr - k))], attrs: 'data-change="k.birth"' })}
        </div>
        <div class="card-info row-nowrap" aria-live="polite" id="k-age">${ageBox(age)}</div>
        <div class="field"><label>${esc(t('Sex'))}<span class="req">*</span></label><div class="grid-2">${[['F', 'Female'], ['M', 'Male']].map(([v, l]) => `<button type="button" class="k-choice" style="min-height:110px;font-size:.9em;padding:16px;${D.sex === v ? 'border-color:var(--navy);background:var(--light-blue)' : ''}" data-act="k.sex" data-v="${v}" aria-pressed="${D.sex === v}">${D.sex === v ? icon('checkCircle', 'icon-lg') : ''}${esc(t(l))}</button>`).join('')}</div><input type="hidden" name="sex" value="${attr(D.sex)}"></div></form>`;
    } else if (KS.step === 'newContact') {
      body = `${sub}<h1 class="k-title">${esc(t('How can we contact you?'))}</h1><p class="k-sub">${esc(t('We will text you when your turn is near.'))}</p>
        <form class="stack" id="kform" novalidate>${kField('mobile', t('Your mobile number'), { type: 'tel', inputmode: 'numeric', placeholder: '09XX XXX XXXX', hint: t('11 digits, starting with 09'), attrs: D.noMobile ? 'disabled' : '' })}
          <label class="check"><input type="checkbox" name="noMobile" ${D.noMobile ? 'checked' : ''} data-change="k.noMobile"><span>${esc(t('I do not have a mobile number'))}</span></label>
          <div class="divider"></div><h2 style="font-size:1em">${esc(t('Emergency contact person'))}</h2>
          <div class="grid-2">${kField('ecName', t('Full name and relationship'), { required: true, placeholder: t('e.g. Maria Cruz (daughter)'), autocomplete: 'off' })}${kField('ecMobile', t('Their mobile number'), { type: 'tel', inputmode: 'numeric', required: true, placeholder: '09XX XXX XXXX' })}</div></form>`;
    } else if (KS.step === 'newAddress') {
      const regions = Object.keys(Q.PSGC);
      const provs = D.region ? Object.keys(Q.PSGC[D.region]) : [];
      const cities = D.region && D.province ? Object.keys(Q.PSGC[D.region][D.province]) : [];
      const brgys = D.region && D.province && D.city ? Q.PSGC[D.region][D.province][D.city] : [];
      const sel = (name, label, list, disabled) => UI.field({ name, label, type: 'select', value: D[name], required: true, options: [['', disabled ? t('Choose the item above first') : t('Select…')], ...list], attrs: `data-change="k.addr" ${disabled ? 'disabled' : ''}` });
      body = `${sub}<h1 class="k-title">${esc(t('Where do you live?'))}</h1><p class="k-sub">${esc(t('Choose from the lists, from top to bottom.'))}</p>
        <form class="stack" id="kform" novalidate><div class="grid-2">${sel('region', t('Region'), regions)}${sel('province', t('Province'), provs, !D.region)}${sel('city', t('City / Municipality'), cities, !D.province)}${sel('brgy', t('Barangay'), brgys, !D.city)}</div>
        ${kField('street', t('House no. / Street (optional)'), { autocomplete: 'off' })}
        <p class="xs muted" style="font-size:.6em">${esc(t('Demo uses a sample of the PSGC list (Bulacan, Pampanga, Metro Manila).'))}</p></form>`;
    } else if (KS.step === 'newPriority') {
      const age = UI.ageFrom(birthISO());
      body = `${sub}<h1 class="k-title">${esc(t('Do any of these apply to you?'))}</h1><p class="k-sub">${esc(t('Priority patients are called earlier. Please show your ID to the clinic secretary.'))}</p>
        <div class="stack">
          ${age >= 60 ? `<div class="alert alert-success" style="font-size:.85em">${icon('senior', 'icon-lg')}<div><div class="alert-title">${esc(t('Senior Citizen — added automatically'))}</div>${esc(t('You are {n} years old, so you are a priority patient.', { n: age }))}</div></div>` : ''}
          <div class="grid-2">
            <button class="k-choice" style="min-height:180px;${D.pwd ? 'border-color:var(--priority);background:var(--priority-bg)' : ''}" data-act="k.prio" data-k="pwd" aria-pressed="${D.pwd}"><span class="ic" style="width:80px;height:80px">${icon('accessibility')}</span>${esc(t('Person with Disability (PWD)'))}${D.pwd ? `<small>${icon('checkCircle')} ${esc(t('Selected'))}</small>` : ''}</button>
            ${D.sex === 'F' ? `<button class="k-choice" style="min-height:180px;${D.pregnant ? 'border-color:var(--priority);background:var(--priority-bg)' : ''}" data-act="k.prio" data-k="pregnant" aria-pressed="${D.pregnant}"><span class="ic" style="width:80px;height:80px">${icon('baby')}</span>${esc(t('Pregnant'))}${D.pregnant ? `<small>${icon('checkCircle')} ${esc(t('Selected'))}</small>` : ''}</button>` : ''}
          </div><p class="center muted" style="font-size:.75em">${esc(t('If none apply, just tap Next.'))}</p></div>`;
    } else if (KS.step === 'newConsent') {
      body = `${sub}<h1 class="k-title">${esc(t('Your privacy'))}</h1>
        <div class="card stack-sm" style="font-size:.8em"><p>${esc(t('DYH will use your information only to register you, manage your queue and keep your medical records, as required by the Data Privacy Act of 2012.'))}</p><p style="margin:0">${esc(t('Only authorized hospital staff can see your records. This screen will erase your information when you finish.'))}</p></div>
        <form id="kform" novalidate><div class="field" style="margin-top:16px"><label class="check" style="font-size:.9em;padding:16px;border:3px solid var(--border);border-radius:14px;background:#fff"><input type="checkbox" name="consent" ${D.consent ? 'checked' : ''} data-change="k.consent"><span><b>${esc(t('I agree'))}</b> — ${esc(t('I allow DYH to collect and use my information for my care.'))}</span></label></div></form>`;
    }
    const last = KS.step === 'newConsent';
    shell(`${body}${nav(back, 'k.newNext', last ? t('Register me') : t('Next'))}`);
    const first = document.querySelector('#kform input:not([type=hidden]):not([disabled]), #kform select');
    if (first && KS.step !== 'newConsent') first.focus();
  }
  function birthISO() { const D = KS.data; if (!D.by || !D.bm || !D.bd) return ''; return `${D.by}-${UI.pad(D.bm)}-${UI.pad(D.bd)}`; }
  function ageBox(age) { return `${icon('calendar', 'icon-lg')}<div><div class="small muted strong" style="font-size:.65em">${esc(t('Your age'))}</div><b style="font-size:1.2em;color:var(--navy)">${age != null && age >= 0 ? esc(t('{n} years old', { n: age })) : '—'}</b></div>`; }
  UI.on('k.birth', (el) => { KS.data[el.name] = el.value; const iso = birthISO(); const box = document.getElementById('k-age'); if (box) box.innerHTML = ageBox(iso ? UI.ageFrom(iso) : null); });
  UI.on('k.sex', (el) => { KS.data.sex = el.dataset.v; if (el.dataset.v === 'M') KS.data.pregnant = false; newPatient(); });
  UI.on('k.noMobile', (el) => { KS.data.noMobile = el.checked; if (el.checked) KS.data.mobile = ''; newPatient(); });
  UI.on('k.addr', (el) => {
    const D = KS.data; D[el.name] = el.value;
    const order = ['region', 'province', 'city', 'brgy']; order.slice(order.indexOf(el.name) + 1).forEach((k) => { D[k] = ''; });
    newPatient();
    const nxt = document.querySelector(`[name="${order[order.indexOf(el.name) + 1]}"]`); if (nxt) nxt.focus();
  });
  UI.on('k.prio', (el) => { KS.data[el.dataset.k] = !KS.data[el.dataset.k]; newPatient(); });
  UI.on('k.consent', (el) => { KS.data.consent = el.checked; });
  UI.on('k.newNext', async (el) => {
    const D = KS.data; const form = document.getElementById('kform'); const e = {};
    if (KS.step === 'newName') { if (!D.first.trim()) e.first = t('Please type your first name.'); if (!D.last.trim()) e.last = t('Please type your last name.'); }
    if (KS.step === 'newBirth') {
      if (!D.bm) e.bm = t('Please choose a month.'); if (!D.bd) e.bd = t('Please choose a day.'); if (!D.by) e.by = t('Please choose a year.');
      const iso = birthISO();
      if (iso) { const dt = UI.parseISO(iso); if (dt.getDate() !== Number(D.bd)) e.bd = t('This date does not exist. Please check the day.'); else if (iso > UI.today()) e.by = t('Birthday cannot be in the future.'); }
      if (!D.sex) e.sex = t('Please tap Female or Male.');
    }
    if (KS.step === 'newContact') {
      if (!D.noMobile && !UI.validMobile(D.mobile)) e.mobile = t('Please enter an 11-digit mobile number starting with 09, or tick “I do not have a mobile number”.');
      if (!D.ecName.trim()) e.ecName = t('Please type the name of a person we can contact.');
      if (!UI.validMobile(D.ecMobile)) e.ecMobile = t('Please enter an 11-digit mobile number starting with 09.');
    }
    if (KS.step === 'newAddress') ['region', 'province', 'city', 'brgy'].forEach((k) => { if (!D[k]) e[k] = t('Please choose from the list.'); });
    if (KS.step === 'newConsent' && !D.consent) e.consent = t('Please tick “I agree” to continue. If you have questions, please ask the front desk.');
    if (form && !UI.setErrors(form, e)) return;
    if (Object.keys(e).length) return;
    const i = NEW_STEPS.indexOf(KS.step);
    if (i < NEW_STEPS.length - 1) { KS.step = NEW_STEPS[i + 1]; render(); return; }
    await UI.busy(el, () => UI.wait(900), t('Registering…'));
    const p = MockAPI.registerPatient({ first: D.first.trim(), middle: D.middle.trim(), last: D.last.trim(), bday: birthISO(), sex: D.sex, mobile: D.noMobile ? '' : D.mobile, emergency: { name: D.ecName, mobile: D.ecMobile }, address: { region: D.region, province: D.province, city: D.city, brgy: D.brgy, street: D.street }, priority: D.pwd ? 'pwd' : D.pregnant ? 'pregnant' : null }, 'Kiosk 1 (Lobby)');
    KS.patient = p; KS.step = 'doctor'; render();
    toast(t('Registered! Your patient code is {code}.', { code: p.code }), 'success');
  });

  /* ---------- Doctor selection ---------- */
  function doctorTile(d) {
    const s = Q.walkInStatus(d); const off = s.state !== 'open';
    return `<button class="k-doc" data-act="k.pickDoc" data-id="${d.id}" ${off ? 'aria-disabled="true"' : ''} aria-label="${attr(`${d.name}, ${Q.specName(d.spec)}. ${t(off ? 'Not available for walk-in' : 'Available')}`)}">
      <div class="row-nowrap">${avatar(d, 84)}<div class="grow"><div class="doc-name">${esc(d.name)}</div><div class="muted" style="font-size:.85em">${esc(Q.specName(d.spec))}</div><div class="strong" style="font-size:.85em;margin-top:4px">${icon('door')} ${esc(t('Room'))} ${esc(d.room)}</div></div></div>
      <div>${statusBadge(s.state, 'badge-lg')}</div>
      ${off ? `<div style="font-size:.8em"><b>${esc(s.reason)}</b><br><span class="muted">${esc(t('Clinic schedule'))}: ${esc(Q.scheduleText(d))}</span></div>`
        : `<div class="k-qstats"><div><b>${esc(s.servingNo)}</b><span>${esc(t('Now serving'))}</span></div><div><b>${s.waiting}</b><span>${esc(t('Waiting'))}</span></div><div><b>${s.left}</b><span>${esc(t('Slots left'))}</span></div></div>`}
    </button>`;
  }
  function doctorList() {
    const q = KS.q.toLowerCase();
    const docs = db().doctors.filter((d) => d.status === 'active' && (!KS.spec || d.spec === KS.spec) && (!q || d.name.toLowerCase().includes(q) || Q.specName(d.spec).toLowerCase().includes(q)));
    if (!docs.length) return UI.emptyState('search', t('No doctors found'), t('Try another name or clinic.'));
    const groups = Q.SPECIALTIES.map((s) => ({ s, list: docs.filter((d) => d.spec === s.id).sort((a, b) => (Q.walkInStatus(a).state === 'open' ? 0 : 1) - (Q.walkInStatus(b).state === 'open' ? 0 : 1)) })).filter((g) => g.list.length);
    return groups.map((g) => `<section style="margin-bottom:24px"><h2 style="font-size:1em;margin-bottom:12px">${esc(I18N.pick(g.s))}</h2><div class="grid-3">${g.list.map(doctorTile).join('')}</div></section>`).join('');
  }
  function chooseDoctor() {
    const p = KS.patient;
    shell(`<div class="row-between" style="margin-bottom:8px"><div><h1 class="k-title">${esc(t('Choose your doctor'))}</h1><p class="k-sub" style="margin-bottom:12px">${esc(t('Hello, {name}! Tap a doctor who is accepting walk-ins today.', { name: p.first }))}</p></div></div>
      <div class="input-icon" style="margin-bottom:12px">${icon('search')}<input class="input" type="search" placeholder="${attr(t('Search doctor or clinic…'))}" value="${attr(KS.q)}" data-input="k.search" aria-label="${attr(t('Search doctor or clinic'))}"></div>
      <div class="chips chips-scroll" style="margin-bottom:20px;font-size:.75em">${[['', t('All clinics')], ...Q.SPECIALTIES.map((s) => [s.id, I18N.pick(s)])].map(([id, l]) => `<button class="chip" style="min-height:56px;padding:8px 20px" data-act="k.spec" data-spec="${id}" aria-pressed="${KS.spec === id}">${esc(l)}</button>`).join('')}</div>
      <div id="k-docs">${doctorList()}</div>${nav(KS.isNew ? null : 'confirmMe')}`);
  }
  UI.on('k.search', (el) => { KS.q = el.value; document.getElementById('k-docs').innerHTML = doctorList(); });
  UI.on('k.spec', (el) => { KS.spec = el.dataset.spec; document.querySelectorAll('[data-act="k.spec"]').forEach((b) => b.setAttribute('aria-pressed', b === el)); document.getElementById('k-docs').innerHTML = doctorList(); });
  UI.on('k.pickDoc', (el) => {
    const d = Q.doctor(el.dataset.id); const s = Q.walkInStatus(d);
    if (s.state !== 'open') return;
    UI.openModal({ title: t('Join this doctor’s queue?'), size: 'lg', body: `<div style="font-size:1.25rem" class="stack">
      <div class="row-nowrap">${avatar(d, 96)}<div><div class="doc-name" style="font-size:1.3em">${esc(d.name)}</div><div class="muted">${esc(Q.specName(d.spec))}</div><div class="strong">${icon('door')} ${esc(t('Room'))} ${esc(d.room)} · ${esc(d.floor)}</div></div></div>
      <div class="k-qstats" style="font-size:1.1em"><div><b>${esc(s.servingNo)}</b><span>${esc(t('Now serving'))}</span></div><div><b>${s.waiting}</b><span>${esc(t('Waiting'))}</span></div><div><b>~${Math.round(s.waiting * db().settings.avgConsultMins)}</b><span>${esc(t('Est. minutes'))}</span></div></div>
      <p style="margin:0">${esc(t('Tap “Yes, get my number” to join the queue.'))}</p></div>`,
    footer: `<button class="btn btn-secondary btn-xl" data-act="modal.close">${esc(t('No, go back'))}</button><button class="btn btn-success btn-xl" data-act="k.join" data-id="${d.id}">${icon('check', 'icon-lg')}${esc(t('Yes, get my number'))}</button>` });
  });
  UI.on('k.join', async (el) => {
    await UI.busy(el, () => UI.wait(700), t('Getting your number…'));
    const r = MockAPI.addToQueue({ doctorId: el.dataset.id, patientId: KS.patient.id, source: 'kiosk', by: 'Kiosk 1 (Lobby)' });
    UI.closeModal();
    KS.entry = r.entry; KS.already = r.error === 'already'; KS.step = 'ticket'; render();
  });

  function ticket() {
    const e = KS.entry; const d = Q.doctor(e.doctorId); const p = KS.patient; const pos = Q.positionOf(e); const pr = Q.priorityOf(p);
    let secs = 45;
    shell(`<div class="grid-2" style="align-items:start">
      <div class="stack">
        ${KS.already ? `<div class="alert alert-info">${icon('info')}<div>${esc(t('You are already in this doctor’s queue today. Here is your number.'))}</div></div>` : `<div class="alert alert-success" style="font-size:.85em">${icon('checkCircle', 'icon-lg')}<div><div class="alert-title">${esc(t('You are in the queue!'))}</div>${esc(t('We will also send an SMS when your turn is near.'))}</div></div>`}
        <section class="ticket print-ticket"><div class="ticket-label">${esc(t('Your queue number'))}</div><div class="ticket-no" style="font-size:7rem">${esc(e.no)}</div>
          <div style="font-weight:800;font-size:1.1em">${esc(d.name)}</div><div style="color:#C9D8EA;font-size:.85em">${esc(Q.specName(d.spec))}</div>
          ${pr ? `<div style="margin-top:12px">${UI.priorityBadge(pr, true)}</div>` : ''}
          <div class="ticket-meta" style="font-size:.85em"><div><b>${esc(d.room)}</b><span>${esc(t('Room'))}</span></div><div><b>${pos.ahead}</b><span>${esc(t('Patients ahead'))}</span></div><div><b>~${pos.etaMins}</b><span>${esc(t('Est. minutes'))}</span></div></div>
          ${KS.isNew ? `<div style="margin-top:16px;font-size:.75em;color:#C9D8EA">${esc(t('Your new patient code'))}: <b class="mono" style="color:#fff">${esc(p.code)}</b></div>` : ''}
        </section></div>
      <div class="card stack" style="font-size:.85em"><h2>${esc(t('What to do next'))}</h2>
        <ol class="stack" style="margin:0;padding-left:24px">
          <li>${esc(t('Go to the {floor}, Room {room}.', { floor: d.floor, room: d.room }))}</li>
          <li>${esc(t('Tell the clinic secretary your number: {no}.', { no: e.no }))}</li>
          <li>${esc(t('Wait for your number to be called. Please stay near the clinic.'))}</li>
          ${pr ? `<li>${esc(t('Show your Senior Citizen, PWD ID or proof of pregnancy to the secretary.'))}</li>` : ''}</ol>
        <div class="alert alert-info">${icon('camera')}<div>${esc(t('Tip: take a photo of this screen so you remember your number.'))}</div></div>
        <button class="btn btn-secondary btn-lg" disabled title="${attr(t('Ticket printing will be available in a future update'))}">${icon('printer')}${esc(t('Print ticket (coming soon)'))}</button>
        <button class="btn btn-primary btn-xl" data-act="k.finish">${icon('check', 'icon-lg')}${esc(t('Finish'))}</button>
        <p class="center muted" style="margin:0;font-size:.8em" aria-live="polite">${esc(t('This screen will clear in'))} <b id="tk-count">${secs}</b> ${esc(t('seconds'))}.</p></div></div>`, { });
    clearInterval(ticketTimer);
    ticketTimer = setInterval(() => {
      if (!onKiosk() || KS.step !== 'ticket') return clearInterval(ticketTimer);
      secs--; const el = document.getElementById('tk-count'); if (el) el.textContent = secs;
      if (secs <= 0) { clearInterval(ticketTimer); resetSession(); }
    }, 1000);
  }
  UI.on('k.finish', () => { resetSession(); toast(t('Thank you! Your information has been cleared from this kiosk.'), 'success'); });

  /* ---------- Global kiosk actions ---------- */
  UI.on('k.start', () => { KS.step = 'type'; render(); });
  UI.on('k.go', (el) => { KS.step = el.dataset.step; if (el.dataset.step === 'keypad') KS.code = ''; render(); });
  UI.on('k.lang', (el) => { I18N.set(el.dataset.lang); render(); });
  UI.on('k.restart', async () => {
    const ok = await UI.confirm({ title: t('Start over?'), message: t('Everything you entered will be erased.'), confirmText: t('Yes, start over'), cancelText: t('No, continue'), danger: true });
    if (ok) resetSession();
  });
  UI.on('k.assist', () => {
    MockAPI.log('Kiosk 1 (Lobby)', 'kiosk', 'Assistance requested at kiosk', 'Front desk notified'); Store.save(true);
    UI.openModal({ title: t('Help is on the way'), size: 'sm', labelledIcon: `<span style="color:var(--navy)">${icon('help', 'icon-xl')}</span>`, body: `<div style="font-size:1.2rem" class="stack"><p>${esc(t('We have notified the front desk. A staff member will come to help you.'))}</p><p>${esc(t('You can also walk to the Front Desk beside this kiosk.'))}</p><div class="alert alert-error">${icon('alert')}<div>${esc(t('If this is an emergency, go to the Emergency Room now.'))}</div></div></div>`, footer: `<button class="btn btn-primary btn-xl" data-act="modal.close">${esc(t('OK'))}</button>` });
  });
  UI.on('k.rate', () => {
    let rating = 0;
    const faces = [[1, '😞', 'Very poor'], [2, '🙁', 'Poor'], [3, '😐', 'Okay'], [4, '🙂', 'Good'], [5, '😄', 'Excellent']];
    UI.openModal({ title: t('How was your experience today?'), size: 'lg', body: `<div style="font-size:1.2rem"><div class="grid" style="grid-template-columns:repeat(5,1fr);gap:10px" role="radiogroup">${faces.map(([n, f, l]) => `<button class="k-choice" style="min-height:150px;padding:12px;font-size:.85em" data-act="k.rateSet" data-n="${n}" role="radio" aria-checked="false"><span style="font-size:2.6em" aria-hidden="true">${f}</span>${esc(t(l))}</button>`).join('')}</div><p class="muted" style="margin-top:12px;font-size:.8em">${esc(t('Your rating is anonymous.'))}</p><div id="krate-err"></div></div>`,
      footer: `<button class="btn btn-secondary btn-xl" data-act="modal.close">${esc(t('Not now'))}</button><button class="btn btn-primary btn-xl" data-act="k.rateSend">${esc(t('Send'))}</button>` });
    UI.on('k.rateSet', (el) => { rating = Number(el.dataset.n); UI.topModal().querySelectorAll('[data-act="k.rateSet"]').forEach((b) => { const on = Number(b.dataset.n) === rating; b.setAttribute('aria-checked', on); b.style.borderColor = on ? 'var(--navy)' : ''; b.style.background = on ? 'var(--light-blue)' : ''; }); });
    UI.on('k.rateSend', () => {
      if (!rating) { UI.topModal().querySelector('#krate-err').innerHTML = `<div class="field-error" style="font-size:.8em">${icon('alert')}<span>${esc(t('Please tap a face first.'))}</span></div>`; return; }
      MockAPI.feedback(rating, '', 'kiosk'); UI.closeModal(); toast(t('Thank you for your feedback!'), 'success');
    });
  });

  Router.add('kiosk', () => { lastActivity = Date.now(); render(); });
})();
