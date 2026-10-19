/* ==========================================================================
   DYH ERM — App core: shared shells, language/text-size controls, launcher,
   design-system reference page.
   ========================================================================== */
(function () {
  'use strict';
  const { icon, esc, attr } = UI;
  const root = () => document.getElementById('app');

  const App = {
    current: null,     // function that re-renders the current view
    live: false,       // whether the current view should refresh on remote changes
    mount(html, rerender, live) {
      root().innerHTML = html;
      App.current = rerender || null;
      App.live = !!live;
    },
    refresh() { if (App.current) { const y = window.scrollY; App.current(); window.scrollTo(0, y); } },
    /** Re-render for remote (other tab) changes, unless the user is mid-typing or in a dialog. */
    liveRefresh() {
      if (!App.live || UI.topModal()) return;
      const a = document.activeElement;
      if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && root().contains(a)) return;
      App.refresh();
    },
    ribbon() {
      return `<div class="demo-ribbon" role="note">${icon('info')}<span><b>${esc(t('Demo prototype'))}</b> — ${esc(t('all names, schedules, queues and records are sample data, not live hospital data.'))}</span>
        <button data-act="app.reset">${esc(t('Reset demo data'))}</button><a href="#/">${esc(t('Switch role'))}</a></div>`;
    },
    langToggle(cls) {
      return `<div class="seg ${cls || ''}" role="group" aria-label="${attr(t('Language'))}">
        <button data-act="app.lang" data-lang="en" aria-pressed="${I18N.lang === 'en'}">English</button>
        <button data-act="app.lang" data-lang="fil" aria-pressed="${I18N.lang === 'fil'}">Filipino</button></div>`;
    },
    textSize() {
      const cur = document.documentElement.dataset.text || 'md';
      return `<div class="seg" role="group" aria-label="${attr(t('Text size'))}">
        ${[['md', 'A'], ['lg', 'A+'], ['xl', 'A++']].map(([k, l]) => `<button data-act="app.text" data-size="${k}" aria-pressed="${cur === k}" aria-label="${attr(t('Text size') + ' ' + l)}">${l}</button>`).join('')}</div>`;
    },
    brand(sub) {
      return `<a class="brand" href="#/"><img src="assets/dyh-logo.svg" alt=""><span><span class="brand-name">Dr. Yanga's Hospital</span><br><span class="brand-sub">${esc(sub || 'Electronic Records Management')}</span></span></a>`;
    },

    /* Staff shell: sidebar + topbar, role-specific navigation */
    staffShell({ role, roleLabel, user, nav, active, title, topActions, content }) {
      const links = nav.map((g) => `${g.group ? `<div class="side-group">${esc(t(g.group))}</div>` : ''}${g.items.map((it) => `<a href="${it.href}" ${active === it.href ? 'aria-current="page"' : ''}>${icon(it.icon)}<span>${esc(t(it.label))}</span>${it.count ? `<span class="count">${it.count}</span>` : ''}</a>`).join('')}`).join('');
      return `${App.ribbon()}<div class="shell-staff" id="staff-shell">
        <aside class="sidebar" aria-label="${attr(t('Main navigation'))}">
          ${App.brand(roleLabel)}
          <div class="side-role">${UI.avatar({ name: user.name }, 40)}<div><b>${esc(user.name)}</b><span>${esc(user.sub || '')}</span></div></div>
          <nav class="side-nav">${links}</nav>
          <div class="side-foot"><button data-act="app.staffLogout" data-role="${role}">${icon('logout')}${esc(t('Sign out'))}</button></div>
        </aside>
        <div class="scrim" data-act="app.closeNav"></div>
        <div class="s-main">
          <header class="topbar">
            <button class="btn btn-ghost btn-icon menu-btn" data-act="app.openNav" aria-label="${attr(t('Open menu'))}">${icon('menu')}</button>
            <h1 class="grow">${esc(title)}</h1>
            ${topActions || ''}
            ${App.langToggle()}
          </header>
          <main class="s-content fade-in" id="main">${content}</main>
        </div></div>`;
    },
    /** Simple staff login screen shared by doctor / secretary / admin (credentials are demo-only). */
    staffLogin({ role, title, username, onLogin }) {
      UI.on('app.staffLoginSubmit', async (form) => {
        const d = UI.formData(form);
        const errs = {};
        if (!d.username) errs.username = t('Please enter your username.');
        if (!d.password) errs.password = t('Please enter your password.');
        if (!UI.setErrors(form, errs)) return;
        await UI.busy(form.querySelector('button[type=submit]'), () => UI.wait(600), t('Signing in…'));
        if (d.username !== username || d.password !== 'demo1234') {
          UI.setErrors(form, { password: t('Username or password is incorrect. Please try again.') });
          return;
        }
        onLogin();
      });
      App.mount(`${App.ribbon()}<div class="launcher" style="display:flex;align-items:center;justify-content:center;padding:24px 16px;min-height:calc(100vh - 34px)">
        <div class="card" style="max-width:440px;width:100%;padding:32px">
          <div class="row-between" style="margin-bottom:20px">${App.brand(title)}</div>
          <h1>${esc(t('Sign in'))}</h1>
          <p class="muted">${esc(t('For authorized DYH staff only. Activity is recorded in the audit log.'))}</p>
          <div class="alert alert-warning" style="margin-bottom:16px">${icon('key')}<div><div class="alert-title">${esc(t('Demo credentials'))}</div>${esc(t('Username'))}: <b class="mono">${esc(username)}</b> · ${esc(t('Password'))}: <b class="mono">demo1234</b></div></div>
          <form data-submit="app.staffLoginSubmit" class="stack" novalidate>
            ${UI.field({ name: 'username', label: t('Username'), value: username, required: true, autocomplete: 'username' })}
            ${UI.field({ name: 'password', label: t('Password'), type: 'password', value: 'demo1234', required: true, autocomplete: 'current-password' })}
            <button class="btn btn-primary btn-block btn-lg" type="submit">${icon('lock')}${esc(t('Sign in'))}</button>
          </form>
          <div class="row" style="justify-content:space-between;margin-top:16px">${App.langToggle()}<a href="#/" class="small">${esc(t('Back to role selection'))}</a></div>
        </div></div>`, () => App.staffLogin({ role, title, username, onLogin }));
    },
  };

  /* ---------- Global actions ---------- */
  UI.on('app.lang', (el) => { I18N.set(el.dataset.lang); App.refresh(); });
  UI.on('app.text', (el) => {
    const s = el.dataset.size;
    if (s === 'md') delete document.documentElement.dataset.text; else document.documentElement.dataset.text = s;
    try { localStorage.setItem('dyh-erm-text', s); } catch (e) { /* ignore */ }
    App.refresh();
  });
  UI.on('app.reset', async () => {
    const ok = await UI.confirm({ title: t('Reset demo data?'), message: t('This restores all sample doctors, patients, queues and appointments to their starting state. Any changes you made in this demo will be lost.'), confirmText: t('Reset demo data'), danger: true, icon: 'refresh' });
    if (!ok) return;
    Store.reset(); UI.toast(t('Demo data has been reset.'), 'success'); App.refresh();
  });
  UI.on('app.openNav', () => document.getElementById('staff-shell').classList.add('nav-open'));
  UI.on('app.closeNav', () => document.getElementById('staff-shell').classList.remove('nav-open'));
  UI.on('app.staffLogout', async (el) => {
    const ok = await UI.confirm({ title: t('Sign out?'), message: t('You will need to sign in again to access this workspace.'), confirmText: t('Sign out'), icon: 'logout' });
    if (!ok) return;
    const s = Store.db.session; delete s.staff[el.dataset.role]; MockAPI.log('Staff', el.dataset.role, 'Signed out', ''); Store.save(true);
    UI.toast(t('You have signed out.'), 'success');
    Router.go('/');
  });
  try { const ts = localStorage.getItem('dyh-erm-text'); if (ts && ts !== 'md') document.documentElement.dataset.text = ts; } catch (e) { /* ignore */ }

  /* ---------- Launcher ---------- */
  function launcher() {
    const roles = [
      ['#/patient', 'smartphone', 'Patient App', 'Browse doctors, book a clinic visit, check your queue number and view your medical records.'],
      ['#/kiosk', 'monitor', 'Hospital Kiosk', 'Walk-in registration at the front desk: register, choose a doctor and get a queue number.'],
      ['#/secretary', 'clipboard', 'Secretary', 'Run the clinic queue, mark arrivals, manage appointments and prepare drafts for the doctor.'],
      ['#/doctor', 'stethoscope', 'Doctor', 'Today’s queue, patient records, consultation notes with Save & Sign, schedules and reports.'],
      ['#/admin', 'settings', 'System Admin', 'Accounts, clinics, announcements, reports, audit logs, SMS monitoring and system settings.'],
      ['#/design', 'layers', 'Design System', 'Color tokens, typography, components and accessibility standards used across every screen.'],
    ];
    App.mount(`${App.ribbon()}<div class="launcher">
      <section class="launcher-hero"><div class="inner">
        <div class="row-between">${App.brand('Electronic Records Management')}${App.langToggle()}</div>
        <h1>${esc(t('Choose an interface to open'))}</h1>
        <p>${esc(t('One connected system for patients, doctors, secretaries, the front-desk kiosk and administrators. Consultations always happen in person at the hospital — this system supports booking, queueing and records only.'))}</p>
      </div></section>
      <div class="launcher-body"><div class="grid-3">
        ${roles.map(([href, ic, title, desc]) => `<a class="role-card" href="${href}"><div class="role-ic">${icon(ic, 'icon-lg')}</div><h2>${esc(t(title))}</h2><p>${esc(t(desc))}</p><span class="go">${esc(t('Open'))} ${icon('arrowRight')}</span></a>`).join('')}
      </div>
      <div class="card-info" style="margin-top:24px"><div class="row-nowrap" style="align-items:flex-start">${icon('info', 'icon-lg')}<div><b>${esc(t('Tip: try the connected flow'))}</b><p class="small" style="margin:4px 0 0">${esc(t('Open the Kiosk and the Secretary screen in two browser tabs. A walk-in registered at the kiosk appears in the secretary and doctor queues right away, and pressing “Done / Next” updates the patient app’s queue status.'))}</p></div></div></div>
      </div></div>`, launcher);
  }
  Router.add('', launcher);

  /* ---------- Design system reference ---------- */
  function design() {
    const sw = [['Primary Navy', '#0B2E59', 'Navigation, headers, primary buttons'], ['Secondary Blue', '#174A7E', 'Selected states, links, charts'], ['Light Blue', '#EAF2FA', 'Info panels, highlights'], ['White', '#FFFFFF', 'Primary background'], ['Soft Gray', '#F4F6F8', 'Secondary backgrounds'], ['Dark Text', '#243447', 'Body text & headings'], ['Success Green', '#218653', 'Confirmed / completed'], ['Warning Amber', '#E5A000', 'Pending / reminders'], ['Error Red', '#C93636', 'Errors, cancellations, emergency']];
    App.mount(`${App.ribbon()}<div style="background:var(--gray-50);min-height:100vh"><div class="p-main stack-lg" style="max-width:1100px">
      <div class="row-between">${App.brand('Design System')}<a class="btn btn-secondary" href="#/">${icon('arrowLeft')}${esc(t('Back'))}</a></div>
      <div><h1>${esc(t('DYH ERM Design System'))}</h1><p class="muted">${esc(t('Navy and white are dominant. Accent colors only communicate status, and every status also has an icon and a text label.'))}</p></div>
      <section class="card"><h2>${esc(t('Color tokens'))}</h2><div class="grid-3">${sw.map(([n, h, u]) => `<div class="row-nowrap"><span style="width:56px;height:56px;border-radius:10px;background:${h};border:1px solid var(--border);flex:none"></span><div><b>${n}</b><div class="mono small">${h}</div><div class="xs muted">${esc(u)}</div></div></div>`).join('')}</div></section>
      <section class="card"><h2>${esc(t('Typography & accessibility'))}</h2><div class="grid-2">
        <div class="stack-sm"><div style="font-size:1.75em;font-weight:700;color:var(--navy)">Heading 1 · 28–32px</div><div style="font-size:1.375em;font-weight:700;color:var(--navy)">Heading 2 · 22px</div><div style="font-size:1.15rem">Patient body text · 18–20px</div><div>Staff body text · 16px</div><div class="small muted">Helper text · 14px (never smaller for content)</div></div>
        <ul class="small" style="margin:0;padding-left:20px"><li>Minimum touch target 48px (56–80px on the kiosk)</li><li>Text contrast ≥ 4.5:1 on all surfaces</li><li>Statuses always show icon + text, never color alone</li><li>Visible labels on every field; plain-language error messages</li><li>Text size control (A / A+ / A++) on patient screens</li><li>English and Filipino on every patient and kiosk screen</li><li>Visible focus ring for keyboard users; reduced-motion respected</li></ul></div></section>
      <section class="card"><h2>${esc(t('Buttons'))}</h2><div class="row"><button class="btn btn-primary">Primary</button><button class="btn btn-secondary">Secondary</button><button class="btn btn-soft">Soft</button><button class="btn btn-ghost">Ghost</button><button class="btn btn-success">${icon('check')}Confirm</button><button class="btn btn-danger">${icon('trash')}Destructive</button><button class="btn btn-primary" disabled>Disabled</button></div>
        <div class="row" style="margin-top:12px"><button class="btn btn-primary btn-sm">Small</button><button class="btn btn-primary">Default</button><button class="btn btn-primary btn-lg">Large</button><button class="btn btn-primary btn-xl">Kiosk XL</button></div></section>
      <section class="card"><h2>${esc(t('Status & priority labels'))}</h2><div class="row">${['waiting', 'serving', 'done', 'skipped', 'cancelled', 'booked', 'confirmed', 'arrived', 'open', 'closed', 'full', 'draft', 'signed'].map((k) => UI.statusBadge(k)).join('')}${UI.priorityBadge('senior')}${UI.priorityBadge('pwd')}${UI.priorityBadge('pregnant')}</div></section>
      <section class="card"><h2>${esc(t('Form fields & alerts'))}</h2><div class="form-grid">
        ${UI.field({ name: 'ds1', label: 'First name', required: true, hint: 'As shown on your valid ID' })}
        <div class="field has-error"><label>Mobile number<span class="req">*</span></label><input class="input" value="0917123" aria-invalid="true"><div class="field-error">${icon('alert')}<span>Please enter an 11-digit mobile number starting with 09.</span></div></div>
        ${UI.field({ name: 'ds3', label: 'Specialty', type: 'select', options: ['Internal Medicine', 'Pediatrics'] })}
        <div class="field"><label>Notifications</label><label class="switch"><input type="checkbox" checked><span class="track"></span><span>SMS reminders</span></label></div>
      </div><div class="stack-sm" style="margin-top:16px">
        <div class="alert alert-info">${icon('info')}<div><div class="alert-title">Information</div>Helpful instruction before an important action.</div></div>
        <div class="alert alert-success">${icon('checkCircle')}<div><div class="alert-title">Success</div>Your appointment is booked. Here is what happens next…</div></div>
        <div class="alert alert-warning">${icon('clock')}<div><div class="alert-title">Reminder</div>Your turn is approaching.</div></div>
        <div class="alert alert-emergency">${icon('alert')}<div><div class="alert-title">Emergency</div>Go directly to the Emergency Room.</div></div></div></section>
      <section class="card"><h2>${esc(t('States'))}</h2><div class="grid-3"><div class="card-flat">${UI.loading('Loading doctors…')}</div><div class="card-flat">${UI.emptyState('calendar', 'No appointments yet', 'Book a clinic visit to see it here.')}</div><div class="card-flat stack-sm"><div class="skeleton" style="height:20px"></div><div class="skeleton" style="height:20px;width:70%"></div><div class="skeleton" style="height:80px"></div></div></div></section>
      <section class="card"><h2>${esc(t('Role navigation map'))}</h2><div class="grid-3 small">
        <div><b>Patient</b><br>Home · Doctors · AI Guide · Book · My Queue · Appointments · Records · Profile</div>
        <div><b>Kiosk</b><br>Welcome → New/Existing → Register or Scan → Choose doctor → Confirm → Queue number</div>
        <div><b>Secretary</b><br>Queue control · Appointments · Walk-ins · Notifications · Drafts</div>
        <div><b>Doctor</b><br>Dashboard · Queue · Consultation · Patients · Schedule · Templates · Reports</div>
        <div><b>Admin</b><br>Overview · Queue monitor · Accounts · Clinics · Announcements · Master lists · Verification · Reports · AI analytics · Feedback · Audit · SMS · Settings</div></div></section>
    </div></div>`, design);
  }
  Router.add('design', design);

  Store.on((src) => { if (src === 'remote') App.liveRefresh(); });
  setInterval(() => App.liveRefresh(), 20000);
  window.App = App;
  window.addEventListener('DOMContentLoaded', () => Router.render());
})();
