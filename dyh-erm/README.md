# DYH Hospital ERM — Interactive UI Prototype

Interactive UI for Dr. Yanga's Hospital Inc. Electronic Records Management system:
**Patient App, Hospital Kiosk, Secretary, Doctor and System Admin**, with a shared design system.

> **UI only, with sample data.** Every name, schedule, queue and record is mock data. A "Demo prototype"
> ribbon on every screen says so. No real SMS is sent, the QR codes are demo patterns, and the
> AI/voice/photo features return canned draft text.

## Run it

No build step and no dependencies. Open `index.html` in a browser, or serve the folder:

```bash
cd dyh-erm && python3 -m http.server 8080   # then visit http://localhost:8080
```

All roles share one browser store (`localStorage`). Open the **Kiosk** and the **Secretary**
screen in two tabs. A walk-in registered at the kiosk shows up in the secretary and doctor
queues, and pressing **Done / Next** moves the patient's position in the Patient App.
Use **Reset demo data** in the ribbon to start over. The data also reseeds itself each day so
dates stay current.

| Role | Route | Demo sign-in |
|---|---|---|
| Patient App | `#/patient` | Mobile `09171234567`, code `123456` (Lorna Mercado, senior) |
| Kiosk | `#/kiosk` | No sign-in. Existing-patient code `104501` |
| Secretary | `#/secretary` | `egonzales` / `demo1234` |
| Doctor | `#/doctor` | `msantos` / `demo1234` |
| Admin | `#/admin` | `cbernardo` / `demo1234` |
| Design system | `#/design` | — |

## What's included

- **Patient:** guest browsing, doctor directory with search and specialty filters, doctor profiles
  and schedules, AI Guide (rule-based, no diagnosis, emergency keywords route to the ER),
  registration with OTP and privacy consent, record linking, booking (future appointment vs.
  same-day walk-in), queue ticket with patients ahead and turn alerts, confirm, reschedule and
  cancel, records behind a security code, patient QR, consent-based record sharing, text size
  A/A+/A++, and English/Filipino.
- **Kiosk:** welcome slides, tap to start, emergency banner, assistance button on every screen,
  feedback, new-patient registration one step per screen (PSGC cascading address, automatic age,
  automatic senior priority), QR/keypad lookup with masked identity confirmation, doctors grouped
  by specialty with walk-in availability, confirmation step, a print-ready ticket (printing comes
  later), and an inactivity warning that resets and clears the session.
- **Secretary:** large Done/Next, Skip and Call back controls, a priority-ordered waiting list,
  walk-in add, appointments by date with Arrived, Reschedule and Cancel, SMS announcements to
  waiting patients, and draft vitals/complaint notes for the doctor.
- **Doctor:** dashboard, queue, consultation form (vitals, findings, diagnosis favorites,
  prescription templates, lab requests, follow-up booking), simulated dictation and photo text
  extraction shown as editable drafts, and **Save & Sign** with a required review checkbox.
  Also authorized-only patient list, schedule and blocked dates, templates, and reports with CSV export.
- **Admin:** live overview, queue monitor, staff accounts, clinics, announcements/ads, patient and
  doctor master lists, verification and duplicate merge, report generator, AI analytics (labelled
  simulated), feedback, read-only audit log, SMS monitoring, and system settings. Admin cannot open
  clinical records.

## Structure

```
index.html
css/tokens.css       color, type, spacing tokens (navy/white brand, status colors)
css/components.css   buttons, cards, forms, badges, tables, modals, toasts, charts
css/layouts.css      role shells: launcher, patient, staff sidebar, kiosk
js/i18n.js           language switching; js/i18n-fil.js = Filipino dictionary
js/ui.js             icons, formatting, modal/confirm/toast, router, event delegation, charts
js/data.js           MOCK DATA + MockAPI (simulated backend), the integration seam
js/app.js            shared shells, staff login, launcher, design-system page
js/patient.js · kiosk.js · secretary.js · doctor.js · admin.js
```

`MockAPI` in `js/data.js` is the only place that changes state (queue, bookings, records, SMS,
audit log). Swap its methods for real API calls when the backend is ready.

## Before production

- Replace `assets/dyh-logo.svg` with the official DYH logo. The current file is a placeholder mark.
- Replace the generated initial avatars with real doctor photos (set `photo` on each doctor).
- Load the full PSA PSGC dataset. The demo includes only part of Bulacan, Pampanga and Metro Manila.
- Have a native speaker review the Filipino copy. Some staff-only detail text is still in English.
- Replace the hospital address and hotline, which are marked "sample".
