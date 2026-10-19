/* ==========================================================================
   DYH ERM — UI kit: icons, escaping, formatting, toasts, modals, router,
   event delegation, small charts. Plain scripts (no build step) so the
   prototype opens straight from the file system.
   ========================================================================== */
(function () {
  'use strict';

  /* ---------- Icons (24px stroke set) ---------- */
  const P = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    calendar: '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
    calendarPlus: '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4M12 13v5M9.5 15.5h5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
    userPlus: '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M19 8v6M16 11h6"/>',
    userCheck: '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 11l2 2 4-4"/>',
    file: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h6"/>',
    clipboard: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h3"/>',
    bell: '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    checkCircle: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    xCircle: '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
    chevronLeft: '<path d="m15 18-6-6 6-6"/>',
    chevronRight: '<path d="m9 18 6-6-6-6"/>',
    chevronDown: '<path d="m6 9 6 6 6-6"/>',
    arrowRight: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    arrowLeft: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    help: '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
    pin: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
    door: '<path d="M4 21h16M6 21V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17"/><path d="M14 12h.01"/>',
    heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>',
    heartPulse: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/><path d="M3.5 12h5l1.5-3 3 6 1.5-3h6"/>',
    star: '<path d="m12 2.5 2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z"/>',
    activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    stethoscope: '<path d="M5 3v6a5 5 0 0 0 10 0V3"/><path d="M10 14v2a5 5 0 0 0 10 0v-2"/><circle cx="20" cy="12" r="2"/>',
    mic: '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4M8 22h8"/>',
    camera: '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
    qr: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/>',
    message: '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 21l2-5.2A8.4 8.4 0 1 1 21 11.5z"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
    chart: '<path d="M3 3v18h18"/><path d="M8 17V11M13 17V7M18 17v-4"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    grid: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
    menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    printer: '<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
    monitor: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    send: '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
    filter: '<path d="M22 3H2l8 9.5V19l4 2v-8.5z"/>',
    skip: '<path d="m5 4 10 8-10 8zM19 5v14"/>',
    undo: '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/>',
    play: '<path d="m6 4 14 8-14 8z"/>',
    megaphone: '<path d="M3 11v3a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15 8a5 5 0 0 1 0 8M18 5a9 9 0 0 1 0 14"/>',
    smartphone: '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
    database: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
    sparkles: '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2 2.2.8-2.2.8L19 21l-.8-2.2L16 18l2.2-.8zM5 3l.6 1.4L7 5l-1.4.6L5 7l-.6-1.4L3 5l1.4-.6z"/>',
    layers: '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
    building: '<rect x="4" y="2" width="16" height="20" rx="1"/><path d="M9 22v-4h6v4M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
    hand: '<path d="M18 11V6a2 2 0 0 0-4 0v5M14 10V4a2 2 0 0 0-4 0v6M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.9-5.9-2.3l-3.6-3.6a2 2 0 0 1 2.8-2.8L7 15"/>',
    accessibility: '<circle cx="12" cy="4" r="2"/><path d="M5 8l7 1 7-1M12 9v5l-3 7M12 14l3 7"/>',
    baby: '<circle cx="12" cy="7" r="4"/><path d="M9 7h.01M15 7h.01M10 9.2a2.5 2.5 0 0 0 4 0"/><path d="M6 21v-3a6 6 0 0 1 12 0v3"/>',
    senior: '<circle cx="11" cy="4" r="2"/><path d="M11 7v7l-3 7M11 14l3 7M8 10l3-2 3 3 3 1M18 12v9"/>',
    pill: '<path d="M10.5 20.5a5 5 0 0 1-7-7l10-10a5 5 0 0 1 7 7z"/><path d="m8.5 8.5 7 7"/>',
    flask: '<path d="M9 3h6M10 3v6L4 19a2 2 0 0 0 1.7 3h12.6A2 2 0 0 0 20 19l-6-10V3"/><path d="M7 15h10"/>',
    signature: '<path d="M3 17c3-3 4-9 6-9s-1 9 2 9 3-5 5-5 1 5 3 5h2"/><path d="M3 21h18"/>',
    bookmark: '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
    scan: '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 12h10"/>',
    keypad: '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M12 18h.01"/>',
    type: '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
    smile: '<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
    textSize: '<path d="M3 20 8 6l5 14M4.8 15h6.4M15 20l3-8 3 8M15.9 17.5h4.2"/>',
    ban: '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
    merge: '<path d="M6 3v6a6 6 0 0 0 6 6h6M18 21v-6"/><path d="m15 12 3 3-3 3"/><circle cx="6" cy="3" r="0.5"/>',
    key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3M16 7l3 3M18 5l2 2"/>',
  };
  function icon(name, cls) {
    return `<svg class="icon ${cls || ''}" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${P[name] || P.info}</svg>`;
  }

  /* ---------- Escaping & formatting ---------- */
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ESC[c]);
  const attr = esc;

  const pad = (n) => String(n).padStart(2, '0');
  function iso(d) { d = d || new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }
  function parseISO(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); }
  function addDays(s, n) { const d = parseISO(s); d.setDate(d.getDate() + n); return iso(d); }
  function today() { return iso(new Date()); }
  function locale() { return window.I18N && I18N.lang === 'fil' ? 'fil-PH' : 'en-PH'; }
  function fmtDate(s, opts) {
    if (!s) return '';
    const d = typeof s === 'string' && s.length === 10 ? parseISO(s) : new Date(s);
    return d.toLocaleDateString(locale(), opts || { month: 'long', day: 'numeric', year: 'numeric' });
  }
  function fmtDateShort(s) { return fmtDate(s, { month: 'short', day: 'numeric' }); }
  function fmtDay(s) { return fmtDate(s, { weekday: 'long', month: 'long', day: 'numeric' }); }
  function fmtTime(hhmm) {
    if (!hhmm) return '';
    const [h, m] = hhmm.split(':').map(Number);
    const ap = h >= 12 ? 'PM' : 'AM'; const h12 = ((h + 11) % 12) + 1;
    return `${h12}:${pad(m)} ${ap}`;
  }
  function fmtDateTime(ts) {
    const d = new Date(ts);
    return d.toLocaleString(locale(), { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function fmtClock(ts) { return new Date(ts).toLocaleTimeString(locale(), { hour: 'numeric', minute: '2-digit' }); }
  function ageFrom(bday) {
    if (!bday) return null;
    const b = parseISO(bday), n = new Date();
    let a = n.getFullYear() - b.getFullYear();
    if (n.getMonth() < b.getMonth() || (n.getMonth() === b.getMonth() && n.getDate() < b.getDate())) a--;
    return a;
  }
  function minsAgo(ts) { return Math.max(0, Math.round((Date.now() - ts) / 60000)); }
  function maskName(first, last) { return `${first} ${last ? last[0] + '.' : ''}`; }
  function maskMobile(m) { return m ? m.slice(0, 4) + '•••' + m.slice(-4) : ''; }
  const uid = (p) => (p || 'id') + '_' + Math.random().toString(36).slice(2, 9);

  /* ---------- Avatars (generated initials; swap for real photos via doctor.photo) ---------- */
  const AV_TONES = [['#DCE7F5', '#0B2E59'], ['#E3EDF8', '#174A7E'], ['#E6EEF2', '#244B5E'], ['#ECE8F4', '#3E3570'], ['#E5EFEA', '#1F5B44'], ['#F3ECE3', '#6A4A23']];
  function avatar(person, size) {
    size = size || 48;
    const name = person.name || `${person.first || ''} ${person.last || ''}`;
    if (person.photo) return `<span class="avatar" style="width:${size}px;height:${size}px"><img src="${attr(person.photo)}" alt=""></span>`;
    const parts = name.replace(/^Dr\.?\s+/i, '').split(/\s+/).filter(Boolean);
    const initials = ((parts[0] || '')[0] || '') + ((parts[parts.length - 1] || '')[0] || '');
    let h = 0; for (const c of name) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    const [bg, fg] = AV_TONES[h % AV_TONES.length];
    return `<span class="avatar" style="width:${size}px;height:${size}px" aria-hidden="true"><svg viewBox="0 0 64 64"><rect width="64" height="64" fill="${bg}"/><circle cx="32" cy="70" r="26" fill="${fg}" opacity=".12"/><text x="32" y="39" text-anchor="middle" font-family="Inter,Segoe UI,Arial,sans-serif" font-size="22" font-weight="700" fill="${fg}">${esc(initials.toUpperCase())}</text></svg></span>`;
  }

  /* ---------- Status chips (icon + text) ---------- */
  const STATUS = {
    waiting: ['warning', 'clock', 'Waiting'],
    serving: ['info', 'stethoscope', 'In consultation'],
    done: ['success', 'checkCircle', 'Done'],
    skipped: ['neutral', 'skip', 'Skipped'],
    cancelled: ['error', 'xCircle', 'Cancelled'],
    booked: ['info', 'calendar', 'Booked'],
    confirmed: ['success', 'checkCircle', 'Confirmed'],
    arrived: ['info', 'userCheck', 'Arrived'],
    completed: ['success', 'checkCircle', 'Completed'],
    'no-show': ['error', 'ban', 'No-show'],
    pending: ['warning', 'clock', 'Pending'],
    active: ['success', 'checkCircle', 'Active'],
    inactive: ['neutral', 'ban', 'Inactive'],
    draft: ['warning', 'edit', 'Draft'],
    signed: ['success', 'signature', 'Signed'],
    delivered: ['success', 'checkCircle', 'Delivered'],
    failed: ['error', 'xCircle', 'Failed'],
    queued: ['warning', 'clock', 'Queued'],
    approved: ['success', 'checkCircle', 'Approved'],
    rejected: ['error', 'xCircle', 'Rejected'],
    open: ['success', 'checkCircle', 'Accepting walk-ins'],
    closed: ['neutral', 'ban', 'Not accepting walk-ins'],
    full: ['error', 'ban', 'Walk-in slots full'],
  };
  function statusBadge(key, extraCls) {
    const s = STATUS[key] || ['neutral', 'info', key];
    return `<span class="badge badge-${s[0]} ${extraCls || ''}">${icon(s[1])}${esc(t(s[2]))}</span>`;
  }
  const PRIORITY = { senior: ['senior', 'Senior Citizen'], pwd: ['accessibility', 'PWD'], pregnant: ['baby', 'Pregnant'] };
  function priorityBadge(p, lg) {
    if (!p || !PRIORITY[p]) return '';
    return `<span class="badge badge-priority ${lg ? 'badge-lg' : ''}">${icon(PRIORITY[p][0])}${esc(t('Priority'))}: ${esc(t(PRIORITY[p][1]))}</span>`;
  }

  /* ---------- Toasts ---------- */
  function toast(msg, type, title) {
    let host = document.querySelector('.toasts');
    if (!host) { host = document.createElement('div'); host.className = 'toasts'; host.setAttribute('role', 'status'); host.setAttribute('aria-live', 'polite'); document.body.appendChild(host); }
    const el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    const ic = { success: 'checkCircle', error: 'alert', warning: 'alert' }[type] || 'info';
    el.innerHTML = `${icon(ic)}<div class="grow">${title ? `<div class="toast-title">${esc(title)}</div>` : ''}<div>${esc(msg)}</div></div>`;
    host.appendChild(el);
    setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, type === 'error' ? 5200 : 3800);
  }

  /* ---------- Modals ---------- */
  const modalStack = [];
  function openModal({ title, body, footer, size, onClose, dismissible = true, labelledIcon }) {
    const back = document.createElement('div');
    back.className = 'modal-backdrop';
    const id = uid('m');
    back.innerHTML = `<div class="modal ${size ? 'modal-' + size : ''}" role="dialog" aria-modal="true" aria-labelledby="${id}">
      <div class="modal-hd"><div class="row-nowrap">${labelledIcon || ''}<h2 id="${id}">${esc(title || '')}</h2></div>${dismissible ? `<button class="btn btn-ghost btn-icon" data-act="modal.close" aria-label="${attr(t('Close'))}">${icon('x')}</button>` : ''}</div>
      <div class="modal-bd">${body || ''}</div>
      ${footer ? `<div class="modal-ft">${footer}</div>` : ''}</div>`;
    const prevFocus = document.activeElement;
    back.addEventListener('mousedown', (e) => { if (e.target === back && dismissible) closeModal(); });
    document.body.appendChild(back);
    const entry = { el: back, onClose, prevFocus, dismissible };
    modalStack.push(entry);
    const focusable = back.querySelector('input:not([type=hidden]), select, textarea, .modal-ft .btn-primary, .modal-ft .btn, button');
    setTimeout(() => focusable && focusable.focus(), 30);
    return back;
  }
  function closeModal(result) {
    const m = modalStack.pop(); if (!m) return;
    m.el.remove();
    if (m.onClose) m.onClose(result);
    if (m.prevFocus && m.prevFocus.focus && document.contains(m.prevFocus)) m.prevFocus.focus();
  }
  function topModal() { return modalStack.length ? modalStack[modalStack.length - 1].el : null; }
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modalStack.length && modalStack[modalStack.length - 1].dismissible) closeModal();
  });

  function confirmDialog({ title, message, confirmText, cancelText, danger, icon: ic, details }) {
    return new Promise((resolve) => {
      let answered = false;
      openModal({
        title, size: 'sm',
        labelledIcon: ic ? `<span style="color:${danger ? 'var(--error)' : 'var(--navy)'}">${icon(ic, 'icon-lg')}</span>` : '',
        body: `<p>${esc(message)}</p>${details || ''}`,
        footer: `<button class="btn btn-secondary" data-act="confirm.no">${esc(cancelText || t('Go back'))}</button>
                 <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-act="confirm.yes">${esc(confirmText || t('Confirm'))}</button>`,
        onClose: () => { if (!answered) resolve(false); },
      });
      Actions['confirm.yes'] = () => { answered = true; closeModal(); resolve(true); };
      Actions['confirm.no'] = () => { answered = true; closeModal(); resolve(false); };
    });
  }

  /* ---------- Event delegation ---------- */
  const Actions = {};
  function on(name, fn) { Actions[name] = fn; }
  Actions['modal.close'] = () => closeModal();
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-act]');
    if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
    const fn = Actions[el.dataset.act];
    if (fn) { e.preventDefault(); fn(el, e); }
  });
  ['input', 'change'].forEach((type) => {
    document.addEventListener(type, (e) => {
      const el = e.target.closest(`[data-${type}]`);
      if (!el) return;
      const fn = Actions[el.dataset[type]];
      if (fn) fn(el, e);
    });
  });
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-submit]');
    if (!f) return;
    e.preventDefault();
    const fn = Actions[f.dataset.submit];
    if (fn) fn(f, e);
  });
  function formData(form) {
    const o = {};
    form.querySelectorAll('[name]').forEach((el) => {
      if (el.type === 'checkbox') o[el.name] = el.checked;
      else if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; }
      else o[el.name] = el.value.trim();
    });
    return o;
  }

  /* ---------- Validation ---------- */
  function setErrors(scope, errors) {
    scope.querySelectorAll('.field').forEach((f) => { f.classList.remove('has-error'); const e = f.querySelector('.field-error'); if (e) e.remove(); });
    let first = null;
    Object.entries(errors).forEach(([name, msg]) => {
      const input = scope.querySelector(`[name="${name}"]`);
      const field = input && input.closest('.field');
      if (!field) return;
      field.classList.add('has-error');
      input.setAttribute('aria-invalid', 'true');
      const m = document.createElement('div');
      m.className = 'field-error'; m.id = 'err-' + name;
      input.setAttribute('aria-describedby', m.id);
      m.innerHTML = icon('alert') + `<span>${esc(msg)}</span>`;
      field.appendChild(m);
      if (!first) first = input;
    });
    if (first) first.focus();
    return Object.keys(errors).length === 0;
  }
  const validMobile = (m) => /^09\d{9}$/.test((m || '').replace(/[\s-]/g, ''));

  /* ---------- Field builders ---------- */
  function field({ name, label, type = 'text', value = '', required, hint, placeholder, options, attrs = '', span2, autocomplete, inputmode }) {
    const id = 'f_' + name + '_' + Math.random().toString(36).slice(2, 6);
    let control;
    if (type === 'select') {
      control = `<select class="select" id="${id}" name="${name}" ${attrs}>${(options || []).map((o) => {
        const [v, l] = Array.isArray(o) ? o : [o, o];
        return `<option value="${attr(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(l)}</option>`;
      }).join('')}</select>`;
    } else if (type === 'textarea') {
      control = `<textarea class="textarea" id="${id}" name="${name}" placeholder="${attr(placeholder || '')}" ${attrs}>${esc(value)}</textarea>`;
    } else {
      control = `<input class="input" id="${id}" name="${name}" type="${type}" value="${attr(value)}" placeholder="${attr(placeholder || '')}" ${autocomplete ? `autocomplete="${autocomplete}"` : ''} ${inputmode ? `inputmode="${inputmode}"` : ''} ${attrs}>`;
    }
    return `<div class="field ${span2 ? 'span-2' : ''}"><label for="${id}">${esc(label)}${required ? `<span class="req" aria-hidden="true">*</span><span class="sr-only"> (${esc(t('required'))})</span>` : ''}</label>${hint ? `<div class="hint">${esc(hint)}</div>` : ''}${control}</div>`;
  }

  function emptyState(ic, title, text, action) {
    return `<div class="empty"><div class="empty-icon">${icon(ic, 'icon-lg')}</div><h3>${esc(title)}</h3><p>${esc(text || '')}</p>${action || ''}</div>`;
  }
  function loading(text) { return `<div class="loading-block"><div class="spinner" role="status" aria-label="${attr(t('Loading'))}"></div><div>${esc(text || t('Loading…'))}</div></div>`; }
  function wait(ms) { return new Promise((r) => setTimeout(r, ms)); }
  /** Puts a button into a loading state while an async action runs. */
  async function busy(btn, fn, label) {
    if (!btn) return fn();
    const html = btn.innerHTML; btn.disabled = true;
    btn.innerHTML = `<span class="spinner"></span>${esc(label || t('Please wait…'))}`;
    try { return await fn(); } finally { if (document.contains(btn)) { btn.disabled = false; btn.innerHTML = html; } }
  }

  /* ---------- Charts: single-series bars & line, with hover tooltips ---------- */
  function barChart(data, { height = 200, unit = '', label = '' } = {}) {
    const W = 600, H = height, padL = 34, padB = 26, padT = 10;
    const max = Math.max(1, ...data.map((d) => d.v));
    const niceMax = Math.ceil(max / 5) * 5 || 5;
    const bw = (W - padL) / data.length;
    const ticks = [0, niceMax / 2, niceMax];
    const y = (v) => H - padB - (v / niceMax) * (H - padB - padT);
    const bars = data.map((d, i) => {
      const x = padL + i * bw + bw * 0.2, w = bw * 0.6, yy = y(d.v), h = H - padB - yy;
      const r = Math.min(4, w / 2, h);
      const path = h > 0 ? `M${x},${H - padB} V${yy + r} Q${x},${yy} ${x + r},${yy} H${x + w - r} Q${x + w},${yy} ${x + w},${yy + r} V${H - padB} Z` : '';
      return `<g data-tip="${attr(`${d.l}: ${d.v}${unit}`)}"><rect class="bar-hit" x="${padL + i * bw}" y="${padT}" width="${bw}" height="${H - padB - padT}"/><path class="bar" d="${path}"/><text x="${x + w / 2}" y="${H - 8}" text-anchor="middle" class="axis-t" fill="#56657A" font-size="12">${esc(d.l)}</text></g>`;
    }).join('');
    return `<div class="chart" data-chart role="img" aria-label="${attr(label)}"><svg viewBox="0 0 ${W} ${H}">
      <g class="grid">${ticks.map((tk) => `<line x1="${padL}" x2="${W}" y1="${y(tk)}" y2="${y(tk)}"/><text x="${padL - 6}" y="${y(tk) + 4}" text-anchor="end" fill="#56657A" font-size="12">${tk}</text>`).join('')}</g>
      ${bars}<line class="baseline" x1="${padL}" x2="${W}" y1="${H - padB}" y2="${H - padB}"/></svg></div>`;
  }
  function lineChart(data, { height = 200, unit = '', label = '' } = {}) {
    const W = 600, H = height, padL = 34, padB = 26, padT = 12;
    const max = Math.max(1, ...data.map((d) => d.v));
    const niceMax = Math.ceil(max / 5) * 5 || 5;
    const step = (W - padL - 10) / Math.max(1, data.length - 1);
    const x = (i) => padL + i * step;
    const y = (v) => H - padB - (v / niceMax) * (H - padB - padT);
    const pts = data.map((d, i) => `${x(i)},${y(d.v)}`).join(' ');
    const area = `M${x(0)},${H - padB} L${pts.replace(/ /g, ' L')} L${x(data.length - 1)},${H - padB} Z`;
    const ticks = [0, niceMax / 2, niceMax];
    const labelEvery = Math.ceil(data.length / 8);
    return `<div class="chart" data-chart role="img" aria-label="${attr(label)}"><svg viewBox="0 0 ${W} ${H}">
      <g class="grid">${ticks.map((tk) => `<line x1="${padL}" x2="${W}" y1="${y(tk)}" y2="${y(tk)}"/><text x="${padL - 6}" y="${y(tk) + 4}" text-anchor="end" fill="#56657A" font-size="12">${tk}</text>`).join('')}</g>
      <path class="area" d="${area}" opacity=".7"/><polyline class="line" points="${pts}"/>
      ${data.map((d, i) => `<g data-tip="${attr(`${d.l}: ${d.v}${unit}`)}"><rect class="bar-hit" x="${x(i) - step / 2}" y="${padT}" width="${step}" height="${H - padB - padT}"/><circle class="dot" cx="${x(i)}" cy="${y(d.v)}" r="4.5"/>${i % labelEvery === 0 ? `<text x="${x(i)}" y="${H - 8}" text-anchor="middle" fill="#56657A" font-size="12">${esc(d.l)}</text>` : ''}</g>`).join('')}
      <line class="baseline" x1="${padL}" x2="${W}" y1="${H - padB}" y2="${H - padB}"/></svg></div>`;
  }
  function hbars(data, unit) {
    const max = Math.max(1, ...data.map((d) => d.v));
    return data.map((d) => `<div class="hbar" title="${attr(`${d.l}: ${d.v}${unit || ''}`)}"><span class="small">${esc(d.l)}</span><div class="hbar-track"><span style="width:${(d.v / max) * 100}%"></span></div><b class="small">${d.v}${esc(unit || '')}</b></div>`).join('');
  }
  document.addEventListener('mouseover', (e) => {
    const g = e.target.closest('[data-chart] [data-tip]');
    const chart = e.target.closest('[data-chart]');
    document.querySelectorAll('.chart-tip').forEach((x) => x.remove());
    document.querySelectorAll('[data-chart] g.on').forEach((x) => x.classList.remove('on'));
    if (!g || !chart) return;
    g.classList.add('on');
    const tip = document.createElement('div'); tip.className = 'chart-tip'; tip.textContent = g.dataset.tip;
    const cr = chart.getBoundingClientRect(), gr = g.getBoundingClientRect();
    tip.style.left = (gr.left - cr.left + gr.width / 2) + 'px';
    const mark = g.querySelector('.bar, .dot');
    tip.style.top = ((mark ? mark.getBoundingClientRect().top : gr.top) - cr.top) + 'px';
    chart.appendChild(tip);
  });

  /* ---------- CSV export (real download of the displayed demo data) ---------- */
  function downloadCSV(filename, rows) {
    const csv = rows.map((r) => r.map((c) => `"${String(c == null ? '' : c).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename;
    document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 100);
  }

  /* ---------- Demo QR-style pattern (NOT a scannable QR) ---------- */
  function demoQR(text, size) {
    size = size || 180; const n = 25; const c = size / n;
    let h = 2166136261; const bits = [];
    for (let i = 0; i < n * n; i++) { h ^= (text.charCodeAt(i % text.length) + i); h = Math.imul(h, 16777619) >>> 0; bits.push(h & 1); }
    const finder = (x, y) => (x < 7 && y < 7) || (x >= n - 7 && y < 7) || (x < 7 && y >= n - 7);
    let rects = '';
    for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) { if (!finder(x, y) && bits[y * n + x]) rects += `<rect x="${x * c}" y="${y * c}" width="${c}" height="${c}"/>`; }
    const f = (x, y) => `<rect x="${x * c}" y="${y * c}" width="${7 * c}" height="${7 * c}" fill="#0B2E59"/><rect x="${(x + 1) * c}" y="${(y + 1) * c}" width="${5 * c}" height="${5 * c}" fill="#fff"/><rect x="${(x + 2) * c}" y="${(y + 2) * c}" width="${3 * c}" height="${3 * c}" fill="#0B2E59"/>`;
    return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" role="img" aria-label="${attr(t('Patient QR code (demo)'))}"><rect width="${size}" height="${size}" fill="#fff"/><g fill="#0B2E59">${rects}</g>${f(0, 0)}${f(n - 7, 0)}${f(0, n - 7)}</svg>`;
  }

  /* ---------- Router ---------- */
  const routes = [];
  const Router = {
    add(prefix, render) { routes.push({ prefix, render }); },
    go(path) { if (location.hash !== '#' + path) location.hash = path; else Router.render(); },
    path() { return (location.hash || '#/').slice(1) || '/'; },
    render() {
      const path = Router.path();
      const parts = path.split('?')[0].split('/').filter(Boolean);
      const r = routes.find((x) => x.prefix === (parts[0] || '')) || routes.find((x) => x.prefix === '');
      document.querySelectorAll('.chart-tip').forEach((x) => x.remove());
      r.render(parts.slice(1), path);
    },
  };
  window.addEventListener('hashchange', () => { while (modalStack.length) closeModal(); Router.render(); window.scrollTo(0, 0); });

  window.UI = {
    icon, esc, attr, iso, parseISO, addDays, today, fmtDate, fmtDateShort, fmtDay, fmtTime, fmtDateTime, fmtClock, ageFrom, minsAgo,
    maskName, maskMobile, uid, avatar, statusBadge, priorityBadge, toast, openModal, closeModal, topModal, confirm: confirmDialog,
    on, Actions, formData, setErrors, validMobile, field, emptyState, loading, wait, busy, barChart, lineChart, hbars, downloadCSV, demoQR, pad,
  };
  window.Router = Router;
})();
