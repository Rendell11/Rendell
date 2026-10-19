/* ==========================================================================
   DYH ERM — MOCK DATA + SIMULATED BACKEND
   --------------------------------------------------------------------------
   Everything in this file is SAMPLE DATA for UI testing. Nothing here is
   live hospital data. The `MockAPI` object stands in for the future backend:
   each method is the seam where a real API call will go. State is kept in
   localStorage so the kiosk, patient app, secretary and doctor screens stay
   in sync across browser tabs on the same device.
   ========================================================================== */
(function () {
  'use strict';
  const { iso, addDays, today, ageFrom, uid, pad } = UI;
  const KEY = 'dyh-erm-demo-v1';

  /* ---------- Reference data ---------- */
  const SPECIALTIES = [
    { id: 'im', en: 'Internal Medicine', fil: 'Internal Medicine (Pang-adulto)', desc: { en: 'Adult illnesses, high blood pressure, diabetes, fever', fil: 'Sakit ng matatanda, altapresyon, diabetes, lagnat' }, kw: ['fever', 'lagnat', 'blood pressure', 'presyon', 'hypertension', 'altapresyon', 'diabetes', 'sugar', 'asukal', 'weak', 'nanghihina', 'stomach', 'tiyan', 'sikmura', 'headache', 'sakit ng ulo', 'dizzy', 'nahihilo', 'check-up', 'checkup'] },
    { id: 'peds', en: 'Pediatrics', fil: 'Pediatrics (Pambata)', desc: { en: 'Babies, children and teenagers', fil: 'Sanggol, bata at kabataan' }, kw: ['child', 'bata', 'baby', 'sanggol', 'anak', 'kid', 'vaccine', 'bakuna'] },
    { id: 'ob', en: 'Obstetrics & Gynecology', fil: 'OB-GYN (Kababaihan at Pagbubuntis)', desc: { en: 'Pregnancy, prenatal check-ups, women’s health', fil: 'Pagbubuntis, prenatal, kalusugan ng kababaihan' }, kw: ['pregnant', 'buntis', 'prenatal', 'menstruation', 'regla', 'period', 'pregnancy', 'pagbubuntis'] },
    { id: 'cardio', en: 'Cardiology', fil: 'Cardiology (Puso)', desc: { en: 'Heart conditions, palpitations, ECG follow-ups', fil: 'Sakit sa puso, palpitasyon, ECG' }, kw: ['heart', 'puso', 'palpitation', 'kabog', 'chest', 'dibdib'] },
    { id: 'eye', en: 'Ophthalmology', fil: 'Ophthalmology (Mata)', desc: { en: 'Eye problems, blurred vision, cataract', fil: 'Problema sa mata, malabong paningin, katarata' }, kw: ['eye', 'mata', 'vision', 'paningin', 'blurry', 'malabo', 'cataract', 'katarata', 'glasses', 'salamin'] },
    { id: 'ortho', en: 'Orthopedics', fil: 'Orthopedics (Buto at Kasukasuan)', desc: { en: 'Bones, joints, back pain, fractures', fil: 'Buto, kasukasuan, sakit ng likod, bali' }, kw: ['bone', 'buto', 'joint', 'kasukasuan', 'knee', 'tuhod', 'back', 'likod', 'balakang', 'fracture', 'bali', 'rayuma', 'arthritis'] },
    { id: 'ent', en: 'ENT (Ear, Nose, Throat)', fil: 'ENT (Tainga, Ilong, Lalamunan)', desc: { en: 'Ear, nose, throat and sinus problems', fil: 'Problema sa tainga, ilong, lalamunan at sinus' }, kw: ['ear', 'tainga', 'tenga', 'nose', 'ilong', 'throat', 'lalamunan', 'sinus', 'hearing', 'pandinig', 'tonsil'] },
    { id: 'fm', en: 'Family Medicine', fil: 'Family Medicine (Pampamilya)', desc: { en: 'General check-ups for the whole family', fil: 'Pangkalahatang check-up para sa buong pamilya' }, kw: ['general', 'family', 'pamilya', 'medical certificate', 'cough', 'ubo', 'sipon', 'cold', 'flu', 'trangkaso'] },
    { id: 'derm', en: 'Dermatology', fil: 'Dermatology (Balat)', desc: { en: 'Skin, hair and nail concerns', fil: 'Balat, buhok at kuko' }, kw: ['skin', 'balat', 'rash', 'pantal', 'itch', 'kati', 'makati', 'acne', 'tagihawat', 'hair', 'buhok'] },
    { id: 'surg', en: 'General Surgery', fil: 'General Surgery (Operasyon)', desc: { en: 'Surgical consultations, lumps, hernia', fil: 'Konsulta para sa operasyon, bukol, luslos' }, kw: ['surgery', 'opera', 'operasyon', 'lump', 'bukol', 'hernia', 'luslos', 'wound', 'sugat'] },
    { id: 'pulmo', en: 'Pulmonology', fil: 'Pulmonology (Baga)', desc: { en: 'Lungs, asthma, chronic cough, shortness of breath', fil: 'Baga, hika, matagal na ubo, hirap huminga' }, kw: ['lung', 'baga', 'asthma', 'hika', 'breath', 'hinga', 'chronic cough', 'matagal na ubo', 'tb'] },
  ];
  const EMERGENCY_KW = ['chest pain', 'sakit ng dibdib', 'masakit ang dibdib', 'can\'t breathe', 'cannot breathe', 'hindi makahinga', 'di makahinga', 'unconscious', 'walang malay', 'nawalan ng malay', 'stroke', 'seizure', 'kombulsyon', 'nangingisay', 'heavy bleeding', 'malakas na pagdurugo', 'dumudugo nang malakas', 'poison', 'nalason', 'lason', 'accident', 'aksidente', 'severe pain', 'sobrang sakit', 'heart attack', 'atake sa puso', 'suicide', 'pagpapakamatay', 'overdose', 'naghihingalo', 'paralysis', 'hindi maigalaw', 'fainted', 'nahimatay', 'emergency'];

  /* PSGC — SAMPLE SUBSET ONLY. Production must load the official PSA PSGC dataset. */
  const PSGC = {
    'Region III (Central Luzon)': {
      Bulacan: {
        Bocaue: ['Antipona', 'Bagumbayan', 'Bambang', 'Batia', 'Biñang 1st', 'Biñang 2nd', 'Bolacan', 'Bundukan', 'Bunlo', 'Caingin', 'Duhat', 'Igulot', 'Lolomboy', 'Poblacion', 'Sulucan', 'Taal', 'Tambobong', 'Turo', 'Wakas'],
        'Balagtas': ['Borol 1st', 'Borol 2nd', 'Dalig', 'Longos', 'Panginay', 'Pulong Gubat', 'San Juan', 'Santol', 'Wawa'],
        'City of Malolos': ['Atlag', 'Bagna', 'Barasoain', 'Bulihan', 'Caingin', 'Longos', 'Mojon', 'Santo Rosario', 'Tikay'],
        'City of Meycauayan': ['Bahay Pare', 'Calvario', 'Malhacan', 'Perez', 'Saluysoy', 'Pajo'],
        Marilao: ['Abangan Norte', 'Abangan Sur', 'Lambakin', 'Loma de Gato', 'Poblacion I', 'Saog'],
        'Santa Maria': ['Bagbaguin', 'Caypombo', 'Poblacion', 'Pulong Buhangin', 'Santa Clara'],
        Guiguinto: ['Daungan', 'Ilang-Ilang', 'Poblacion', 'Santa Rita', 'Tabe'],
      },
      Pampanga: {
        'City of San Fernando': ['Dolores', 'San Agustin', 'Santo Niño', 'Sindalan'],
        'Angeles City': ['Balibago', 'Pampang', 'Pulung Maragul'],
      },
    },
    'National Capital Region (NCR)': {
      'Metro Manila': {
        'Quezon City': ['Bagong Pag-asa', 'Batasan Hills', 'Commonwealth', 'Fairview', 'Novaliches Proper'],
        'City of Valenzuela': ['Karuhatan', 'Malinta', 'Marulas', 'Paso de Blas'],
        'City of Caloocan': ['Bagong Silang', 'Camarin', 'Grace Park'],
        'City of Manila': ['Sampaloc', 'Tondo', 'Santa Cruz', 'Ermita'],
      },
    },
  };

  /* ---------- Seed builder (dates are relative to today so the demo stays current) ---------- */
  function seed() {
    const T = today();
    const dow = new Date().getDay();
    const withToday = (days) => Array.from(new Set([...days, dow])).sort();
    const withoutToday = (days) => { const d = days.filter((x) => x !== dow); return d.length ? d : [(dow + 2) % 7]; };

    const doctors = [
      { id: 'd1', name: 'Dr. Maria Lourdes Santos', spec: 'im', room: '201', floor: '2nd Floor, Medical Arts Building', prefix: 'A', days: withToday([1, 3, 5]), start: '08:00', end: '12:00', slot: 20, walkInCap: 20, walkIn: true, bio: { en: 'Adult internal medicine with focus on hypertension and senior care. 18 years in practice.', fil: 'Internal medicine para sa matatanda; pokus sa altapresyon at pangangalaga sa senior. 18 taon nang nagpapraktis.' } },
      { id: 'd2', name: 'Dr. Jose Antonio Reyes', spec: 'peds', room: '105', floor: 'Ground Floor, Main Building', prefix: 'B', days: withToday([1, 2, 3, 4, 5, 6]), start: '09:00', end: '13:00', slot: 15, walkInCap: 24, walkIn: true, bio: { en: 'General pediatrics, newborn care and childhood immunization.', fil: 'Pangkalahatang pediatrics, pangangalaga sa sanggol at bakuna.' } },
      { id: 'd3', name: 'Dr. Ana Patricia Cruz', spec: 'ob', room: '210', floor: '2nd Floor, Medical Arts Building', prefix: 'C', days: withoutToday([2, 4, 6]), start: '13:00', end: '17:00', slot: 20, walkInCap: 15, walkIn: true, bio: { en: 'Prenatal care, high-risk pregnancy and women’s wellness.', fil: 'Prenatal care, maselang pagbubuntis at kalusugan ng kababaihan.' } },
      { id: 'd4', name: 'Dr. Ramon Villanueva', spec: 'cardio', room: '305', floor: '3rd Floor, Medical Arts Building', prefix: 'D', days: withToday([1, 4]), start: '10:00', end: '14:00', slot: 30, walkInCap: 10, walkIn: true, bio: { en: 'Adult cardiology, ECG and 2D-echo interpretation.', fil: 'Cardiology para sa matatanda, pagbasa ng ECG at 2D-echo.' } },
      { id: 'd5', name: 'Dr. Carmela Dizon', spec: 'eye', room: '112', floor: 'Ground Floor, Main Building', prefix: 'E', days: withoutToday([2, 5]), start: '09:00', end: '12:00', slot: 20, walkInCap: 12, walkIn: true, bio: { en: 'Cataract evaluation, glaucoma screening and eye check-ups.', fil: 'Pagsusuri sa katarata, glaucoma at mata.' } },
      { id: 'd6', name: 'Dr. Eduardo Mendoza', spec: 'ortho', room: '118', floor: 'Ground Floor, Main Building', prefix: 'F', days: withToday([2, 4]), start: '13:00', end: '16:00', slot: 20, walkInCap: 12, walkIn: true, bio: { en: 'Joint pain, arthritis, back problems and fracture care.', fil: 'Sakit sa kasukasuan, rayuma, likod at bali.' } },
      { id: 'd7', name: 'Dr. Liza Marie Tan', spec: 'ent', room: '214', floor: '2nd Floor, Medical Arts Building', prefix: 'G', days: withToday([3, 6]), start: '08:00', end: '11:00', slot: 20, walkInCap: 0, walkIn: false, bio: { en: 'Ear, nose and throat; hearing and sinus care. By appointment only.', fil: 'Tainga, ilong at lalamunan; pandinig at sinus. Sa appointment lamang.' } },
      { id: 'd8', name: 'Dr. Benjamin Garcia', spec: 'fm', room: '108', floor: 'Ground Floor, Main Building', prefix: 'H', days: withToday([1, 2, 3, 4, 5]), start: '08:00', end: '15:00', slot: 15, walkInCap: 30, walkIn: true, bio: { en: 'Family medicine, annual physical exams and medical certificates.', fil: 'Family medicine, taunang physical exam at medical certificate.' } },
      { id: 'd9', name: 'Dr. Rosario Bautista', spec: 'derm', room: '220', floor: '2nd Floor, Medical Arts Building', prefix: 'J', days: withoutToday([1, 3]), start: '14:00', end: '17:00', slot: 20, walkInCap: 10, walkIn: true, bio: { en: 'Skin allergies, eczema, acne and skin check-ups.', fil: 'Allergy sa balat, eksema, tagihawat at pagsusuri ng balat.' } },
      { id: 'd10', name: 'Dr. Francisco Aquino', spec: 'surg', room: '310', floor: '3rd Floor, Medical Arts Building', prefix: 'K', days: withToday([2, 5]), start: '09:00', end: '12:00', slot: 20, walkInCap: 6, walkIn: true, bio: { en: 'General surgery consults, hernia, gallbladder and minor procedures.', fil: 'Konsulta sa general surgery, luslos, apdo at maliliit na operasyon.' } },
      { id: 'd11', name: 'Dr. Teresa Navarro', spec: 'im', room: '203', floor: '2nd Floor, Medical Arts Building', prefix: 'L', days: withToday([2, 4, 6]), start: '13:00', end: '17:00', slot: 20, walkInCap: 16, walkIn: true, bio: { en: 'Internal medicine and diabetes care (endocrinology).', fil: 'Internal medicine at pangangalaga sa may diabetes.' } },
      { id: 'd12', name: 'Dr. Miguel Ramos', spec: 'pulmo', room: '307', floor: '3rd Floor, Medical Arts Building', prefix: 'M', days: withoutToday([1, 4]), start: '10:00', end: '13:00', slot: 20, walkInCap: 12, walkIn: true, bio: { en: 'Asthma, COPD, chronic cough and lung function tests.', fil: 'Hika, COPD, matagal na ubo at pagsusuri ng baga.' } },
    ].map((d) => ({ ...d, blocked: [], status: 'active' }));
    // A blocked date example
    doctors[3].blocked.push({ date: addDays(T, 9), reason: 'Medical convention' });
    doctors[0].blocked.push({ date: addDays(T, 14), reason: 'Leave' });

    const P = (id, first, middle, last, bday, sex, mobile, extra) => ({
      id, first, middle, last, bday, sex, mobile, code: 'DYH-' + (104500 + Number(id.slice(1))), verified: true,
      address: { region: 'Region III (Central Luzon)', province: 'Bulacan', city: 'Bocaue', brgy: 'Poblacion', street: '' },
      emergency: { name: '', mobile: '' }, priority: null, createdAt: Date.now() - 86400000 * 400, ...extra,
    });
    const patients = [
      P('p1', 'Lorna', 'Santiago', 'Mercado', '1958-03-14', 'F', '09171234567', { address: { region: 'Region III (Central Luzon)', province: 'Bulacan', city: 'Bocaue', brgy: 'Lolomboy', street: '128 Mabini St.' }, emergency: { name: 'Ramil Mercado (son)', mobile: '09189876543' }, allergies: 'Penicillin', hasAccount: true }),
      P('p2', 'Juan Miguel', 'Lopez', 'Dela Cruz', '1985-07-02', 'M', '09175550111'),
      P('p3', 'Rosalinda', 'Garcia', 'Pascual', '1950-11-21', 'F', '09285550122'),
      P('p4', 'Mark Anthony', 'Cruz', 'Reyes', '1992-01-15', 'M', '09395550133', { priority: 'pwd' }),
      P('p5', 'Kristine Joy', 'Ramos', 'Bautista', '1996-05-30', 'F', '09175550144', { priority: 'pregnant' }),
      P('p6', 'Ernesto', 'Villanueva', 'Lim', '1947-09-09', 'M', '09175550155'),
      P('p7', 'Maricel', 'De Guzman', 'Tolentino', '1989-12-12', 'F', '09275550166'),
      P('p8', 'Jerome', 'Santos', 'Castillo', '2001-04-04', 'M', '09175550177'),
      P('p9', 'Angelita', 'Ramos', 'Domingo', '1955-02-18', 'F', '09185550188'),
      P('p10', 'Paolo', 'Mendoza', 'Fernandez', '1978-08-25', 'M', '09175550199'),
      P('p11', 'Grace Ann', 'Torres', 'Salazar', '1994-10-10', 'F', '09175550200', { priority: 'pregnant' }),
      P('p12', 'Rodrigo', 'Bernal', 'Manalo', '1962-06-06', 'M', '09175550211'),
      P('p13', 'Jasmine Mae', 'Lim', 'Ocampo', '2018-03-03', 'F', '09175550222', { emergency: { name: 'Clarisse Ocampo (mother)', mobile: '09175550223' } }),
      P('p14', 'Victor', 'Hugo', 'Aguilar', '1970-07-07', 'M', '09175550233'),
      P('p15', 'Leticia', 'Valdez', 'Cabrera', '1944-01-28', 'F', '09175550244', { priority: 'pwd' }),
      P('p16', 'Noel', 'Pineda', 'Enriquez', '1983-11-30', 'M', '09175550255'),
      P('p17', 'Carmelita', 'Rivera', 'Soriano', '1959-05-19', 'F', '09175550266'),
      P('p18', 'Bea Marie', 'Cruz', 'Aquino', '2015-09-14', 'F', '09175550277'),
      P('p19', 'Juan', 'L.', 'Dela Cruz', '1985-07-02', 'M', '09998887766', { verified: false, createdAt: Date.now() - 86400000 * 2 }),
      P('p20', 'Ma. Rosalinda', '', 'Pascual', '1950-11-21', 'F', '09285550122', { verified: false, createdAt: Date.now() - 86400000 * 5 }),
    ];
    // Generated walk-in patients so queues look realistic
    const FN = ['Arnel', 'Teresita', 'Dennis', 'Ligaya', 'Ronaldo', 'Marites', 'Efren', 'Josefina', 'Rey', 'Analyn', 'Danilo', 'Corazon', 'Ramil', 'Shiela', 'Bong', 'Imelda', 'Joel', 'Cynthia', 'Allan', 'Nenita', 'Ricky', 'Gemma', 'Edgar', 'Lilibeth', 'Romeo', 'Vilma', 'Ariel', 'Rowena', 'Nestor', 'Divina'];
    const LN = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Andrada', 'Castillo', 'Flores', 'Villanueva', 'Ramos', 'Castro', 'Rivera', 'Aquino', 'Navarro', 'Salvador', 'Mercado'];
    for (let i = 0; i < 30; i++) {
      const y = 1940 + ((i * 7) % 66);
      patients.push(P('p' + (21 + i), FN[i], '', LN[(i * 3) % LN.length], `${y}-${pad(((i * 5) % 12) + 1)}-${pad(((i * 11) % 27) + 1)}`, i % 2 ? 'F' : 'M', '0917' + String(5560000 + i * 37).slice(0, 7), { priority: i % 9 === 4 ? 'pwd' : i % 11 === 6 ? 'pregnant' : null }));
    }

    /* Queue: build today's queue for scheduled doctors */
    const queue = [];
    const now = Date.now();
    const plan = { d1: [8, ['p3', 'p27', 'p4', 'p28', 'p5', 'p29', 'p1', 'p30', 'p31']], d2: [11, ['p13', 'p18', 'p32', 'p33']], d4: [3, ['p6', 'p34', 'p12']], d6: [2, ['p9', 'p35', 'p36']], d8: [14, ['p2', 'p37', 'p38', 'p39', 'p40', 'p41']], d10: [5, ['p42']], d11: [4, ['p17', 'p43', 'p11', 'p44']] };
    let gp = 45;
    Object.entries(plan).forEach(([did, [doneCount, waiting]]) => {
      const d = doctors.find((x) => x.id === did);
      if (!d.days.includes(dow)) return;
      let seq = 0;
      const mk = (pid, status, minsBack, source) => {
        seq++;
        queue.push({ id: uid('q'), doctorId: did, patientId: pid, seq, no: `${d.prefix}-${pad3(seq)}`, date: T, status, source: source || (seq % 3 === 0 ? 'appointment' : seq % 3 === 1 ? 'kiosk' : 'app'), createdAt: now - minsBack * 60000, calledAt: status !== 'waiting' ? now - (minsBack - 5) * 60000 : null, doneAt: status === 'done' ? now - (minsBack - 15) * 60000 : null, front: false });
      };
      for (let i = 0; i < doneCount; i++) mk('p' + (21 + ((gp++) % 30)), 'done', 200 - i * 12);
      mk('p' + (21 + ((gp++) % 30)), 'serving', 70);
      waiting.forEach((pid, i) => mk(pid, 'waiting', 60 - i * 6, pid === 'p1' ? 'app' : undefined));
    });
    // Make Dr. Aquino (d10) full for walk-ins
    const d10 = doctors.find((x) => x.id === 'd10'); if (d10) d10.walkInCap = 3;

    /* Appointments */
    const appointments = [];
    const A = (patientId, doctorId, date, time, status, type, reason, extra) => appointments.push({ id: uid('a'), patientId, doctorId, date, time, status, type: type || 'appointment', reason: reason || '', createdAt: now - 86400000 * 3, ...extra });
    const nextDay = (doc, from, n) => { let d = from, k = 0; for (let i = 1; i < 60; i++) { d = addDays(from, i); if (doc.days.includes(UI.parseISO(d).getDay())) { k++; if (k >= (n || 1)) return d; } } return addDays(from, 7); };
    const D = (id) => doctors.find((x) => x.id === id);
    A('p1', 'd4', nextDay(D('d4'), T, 1), '10:30', 'confirmed', 'appointment', 'Heart check-up / ECG result review');
    A('p1', 'd11', nextDay(D('d11'), T, 3), '13:40', 'booked', 'follow-up', 'Diabetes follow-up (HbA1c result)', { scheduledBy: 'd11' });
    A('p1', 'd5', addDays(T, -40), '09:20', 'completed', 'appointment', 'Blurred vision');
    A('p1', 'd1', addDays(T, -21), '08:40', 'completed', 'appointment', 'Blood pressure check');
    if (D('d1').days.includes(dow)) {
      ['p14', 'p16', 'p7', 'p10', 'p12'].forEach((pid, i) => A(pid, 'd1', T, ['08:20', '09:00', '09:40', '10:20', '11:00'][i], i < 2 ? 'arrived' : 'booked', i === 3 ? 'follow-up' : 'appointment', ['Hypertension follow-up', 'Annual check-up', 'Cough for 2 weeks', 'Lab results review', 'Blood sugar monitoring'][i]));
    }
    if (D('d11').days.includes(dow)) {
      ['p17', 'p9', 'p15'].forEach((pid, i) => A(pid, 'd11', T, ['13:00', '13:20', '14:00'][i], i === 0 ? 'arrived' : 'booked', 'appointment', ['Diabetes follow-up', 'Thyroid check', 'Medication refill review'][i]));
    }
    A('p2', 'd8', addDays(T, 2), '10:15', 'booked', 'appointment', 'Medical certificate');
    A('p3', 'd1', nextDay(D('d1'), T, 1), '09:00', 'booked', 'follow-up', 'BP follow-up', { scheduledBy: 'd1' });
    A('p6', 'd1', nextDay(D('d1'), T, 1), '09:20', 'confirmed');
    A('p12', 'd1', nextDay(D('d1'), T, 2), '10:00', 'booked');

    /* Medical records (signed consultations) */
    const records = [];
    const R = (patientId, doctorId, date, o) => records.push({ id: uid('r'), patientId, doctorId, date, status: 'signed', signedAt: UI.parseISO(date).getTime() + 3600000 * 11, vitals: {}, labs: [], rx: [], notes: '', ...o });
    R('p1', 'd1', addDays(T, -21), { complaint: 'Occasional headache and dizziness in the morning', vitals: { bp: '150/95', hr: '82', temp: '36.6', wt: '64' }, findings: 'Alert, not in distress. BP elevated on two readings. Heart regular rate and rhythm. No edema.', diagnosis: ['Essential hypertension, uncontrolled'], rx: [{ drug: 'Amlodipine 5 mg tablet', dose: '1 tablet', freq: 'Once a day in the morning', dur: '30 days' }, { drug: 'Losartan 50 mg tablet', dose: '1 tablet', freq: 'Once a day', dur: '30 days' }], labs: ['Lipid profile', 'Serum creatinine', '12-lead ECG'], notes: 'Low-salt diet advised. Home BP monitoring twice daily. Referred to cardiology for ECG review.' });
    R('p1', 'd5', addDays(T, -40), { complaint: 'Blurred vision, left eye, 3 months', vitals: { bp: '140/90' }, findings: 'Visual acuity OS 20/60. Early lens opacity noted, left eye.', diagnosis: ['Senile cataract, early, left eye'], rx: [{ drug: 'Artificial tears eye drops', dose: '1–2 drops', freq: '4 times a day', dur: 'As needed' }], labs: [], notes: 'Re-evaluate in 6 months. Not yet for surgery.' });
    R('p1', 'd11', addDays(T, -75), { complaint: 'Follow-up for blood sugar', vitals: { bp: '138/88', hr: '78', wt: '65' }, findings: 'FBS 142 mg/dL (prior). No neuropathy symptoms.', diagnosis: ['Type 2 diabetes mellitus without complications'], rx: [{ drug: 'Metformin 500 mg tablet', dose: '1 tablet', freq: 'Twice a day after meals', dur: '90 days' }], labs: ['HbA1c', 'Fasting blood sugar'], notes: 'Diet counseling given. Follow-up with HbA1c result.' });
    R('p3', 'd1', addDays(T, -30), { complaint: 'BP monitoring', vitals: { bp: '135/85' }, findings: 'Stable.', diagnosis: ['Essential hypertension, controlled'], rx: [{ drug: 'Losartan 50 mg tablet', dose: '1 tablet', freq: 'Once a day', dur: '30 days' }], labs: [], notes: '' });
    R('p14', 'd1', addDays(T, -60), { complaint: 'Chest heaviness on exertion', vitals: { bp: '145/92' }, findings: 'Referred for stress test.', diagnosis: ['Essential hypertension', 'Rule out ischemic heart disease'], rx: [{ drug: 'Amlodipine 10 mg tablet', dose: '1 tablet', freq: 'Once a day', dur: '30 days' }], labs: ['12-lead ECG', 'Treadmill stress test'], notes: '' });
    R('p7', 'd1', addDays(T, -14), { complaint: 'Cough and colds, 5 days', vitals: { temp: '37.9' }, findings: 'Mild pharyngeal erythema. Clear breath sounds.', diagnosis: ['Acute upper respiratory infection'], rx: [{ drug: 'Paracetamol 500 mg tablet', dose: '1 tablet', freq: 'Every 4 hours if fever', dur: '3 days' }], labs: [], notes: 'Return if symptoms persist beyond 2 weeks.' });
    R('p12', 'd1', addDays(T, -45), { complaint: 'Blood sugar monitoring', vitals: { bp: '130/80' }, findings: 'FBS 160 mg/dL.', diagnosis: ['Type 2 diabetes mellitus without complications'], rx: [{ drug: 'Metformin 500 mg tablet', dose: '1 tablet', freq: 'Twice a day', dur: '30 days' }], labs: ['HbA1c'], notes: '' });
    R('p10', 'd1', addDays(T, -7), { complaint: 'Annual physical exam', vitals: { bp: '120/80' }, findings: 'Unremarkable.', diagnosis: ['General medical examination'], rx: [], labs: ['CBC', 'Urinalysis', 'Chest X-ray PA'], notes: 'Return for results review.' });

    const accounts = [
      { id: 'u1', name: 'Dr. Maria Lourdes Santos', role: 'doctor', doctorId: 'd1', username: 'msantos', email: 'msantos@dyh.example', status: 'active', lastLogin: now - 3600000 },
      { id: 'u2', name: 'Dr. Teresa Navarro', role: 'doctor', doctorId: 'd11', username: 'tnavarro', email: 'tnavarro@dyh.example', status: 'active', lastLogin: now - 86400000 },
      { id: 'u3', name: 'Dr. Jose Antonio Reyes', role: 'doctor', doctorId: 'd2', username: 'jreyes', email: 'jreyes@dyh.example', status: 'active', lastLogin: now - 7200000 },
      { id: 'u4', name: 'Ella Mae Gonzales', role: 'secretary', doctorIds: ['d1', 'd11'], username: 'egonzales', email: 'egonzales@dyh.example', status: 'active', lastLogin: now - 1800000 },
      { id: 'u5', name: 'Patricia Lim', role: 'secretary', doctorIds: ['d2', 'd8'], username: 'plim', email: 'plim@dyh.example', status: 'active', lastLogin: now - 5400000 },
      { id: 'u6', name: 'Rogelio Manansala', role: 'frontdesk', username: 'rmanansala', email: 'rmanansala@dyh.example', status: 'active', lastLogin: now - 900000 },
      { id: 'u7', name: 'Joy Villareal', role: 'frontdesk', username: 'jvillareal', email: 'jvillareal@dyh.example', status: 'inactive', lastLogin: now - 86400000 * 40 },
      { id: 'u8', name: 'Admin — Carlo Bernardo', role: 'admin', username: 'cbernardo', email: 'it@dyh.example', status: 'active', lastLogin: now - 600000 },
      { id: 'u9', name: 'Dr. Ramon Villanueva', role: 'doctor', doctorId: 'd4', username: 'rvillanueva', email: 'rvillanueva@dyh.example', status: 'active', lastLogin: now - 86400000 * 2 },
      { id: 'u10', name: 'Dr. Benjamin Garcia', role: 'doctor', doctorId: 'd8', username: 'bgarcia', email: 'bgarcia@dyh.example', status: 'active', lastLogin: now - 4000000 },
    ];

    const clinics = SPECIALTIES.map((s, i) => ({ id: 'c' + (i + 1), name: s.en, spec: s.id, location: doctors.filter((d) => d.spec === s.id).map((d) => 'Room ' + d.room).join(', ') || 'TBA', status: 'active' }));

    const announcements = [
      { id: 'n1', kind: 'announcement', audience: 'all', active: true, title: { en: 'Free blood pressure screening every Saturday', fil: 'Libreng BP screening tuwing Sabado' }, body: { en: 'Visit the OPD lobby from 8:00 AM to 12:00 NN. No appointment needed.', fil: 'Pumunta sa OPD lobby mula 8:00 AM hanggang 12:00 NN. Hindi kailangan ng appointment.' }, start: addDays(T, -10), end: addDays(T, 30), icon: 'heartPulse' },
      { id: 'n2', kind: 'announcement', audience: 'all', active: true, title: { en: 'Senior citizens & PWDs: use the priority lane', fil: 'Senior citizen at PWD: gamitin ang priority lane' }, body: { en: 'Present your OSCA or PWD ID at the front desk for priority queueing.', fil: 'Ipakita ang inyong OSCA o PWD ID sa front desk para sa priority na pila.' }, start: addDays(T, -60), end: addDays(T, 120), icon: 'accessibility' },
      { id: 'n3', kind: 'ad', audience: 'kiosk', active: true, title: { en: 'Executive Check-up Package', fil: 'Executive Check-up Package' }, body: { en: 'Complete blood tests, ECG and chest X-ray. Ask the front desk for details.', fil: 'Kumpletong blood test, ECG at chest X-ray. Magtanong sa front desk.' }, start: addDays(T, -5), end: addDays(T, 60), icon: 'clipboard' },
      { id: 'n4', kind: 'announcement', audience: 'app', active: true, title: { en: 'Online booking is now open', fil: 'Bukas na ang online booking' }, body: { en: 'Book your clinic visit in advance. Consultations still happen in person at the hospital.', fil: 'Mag-book ng pagbisita nang maaga. Ang konsultasyon ay gagawin pa rin nang personal sa ospital.' }, start: addDays(T, -3), end: addDays(T, 90), icon: 'calendar' },
      { id: 'n5', kind: 'announcement', audience: 'all', active: false, title: { en: 'Flu vaccine available', fil: 'May bakuna laban sa trangkaso' }, body: { en: 'Available at the Pharmacy, Ground Floor.', fil: 'Mabibili sa Pharmacy, Ground Floor.' }, start: addDays(T, -90), end: addDays(T, -1), icon: 'pill' },
    ];

    const feedback = [];
    const comments = ['Very accommodating staff.', 'Mabilis ang pila ngayon, salamat!', 'Waiting time was long but the doctor was thorough.', 'Malinis ang ospital.', 'Kiosk was easy to use even for my lola.', 'Please add more chairs in the waiting area.', 'Sana mas malinaw ang signage papunta sa Room 305.', 'Doctor explained everything clearly.', 'Parking is difficult.', ''];
    for (let i = 0; i < 46; i++) feedback.push({ id: uid('f'), rating: [5, 4, 5, 3, 4, 5, 2, 4, 5, 4][i % 10], comment: comments[i % comments.length], source: i % 3 ? 'kiosk' : 'app', at: now - i * 3600000 * 7, doctorId: i % 4 === 0 ? doctors[i % 12].id : null });

    const sms = { credits: 4280, log: [] };
    const smsMsgs = ['Your queue no. is near. Please proceed to Room 201.', 'Reminder: Appointment tomorrow 10:30 AM with Dr. Villanueva.', 'Your DYH verification code is ******.', 'Your appointment has been confirmed.', 'You are next. Please proceed to the clinic room.'];
    for (let i = 0; i < 28; i++) sms.log.push({ id: uid('s'), at: now - i * 1700000, to: patients[i % 20].mobile, msg: smsMsgs[i % smsMsgs.length], status: i % 13 === 5 ? 'failed' : i < 2 ? 'queued' : 'delivered', type: ['queue', 'reminder', 'otp', 'booking', 'queue'][i % 5] });

    const audit = [];
    const AU = (mins, user, role, action, target) => audit.push({ id: uid('l'), at: now - mins * 60000, user, role, action, target });
    AU(3, 'Ella Mae Gonzales', 'secretary', 'Called next patient', 'Queue A-009');
    AU(12, 'Dr. Maria Lourdes Santos', 'doctor', 'Signed consultation record', 'Patient DYH-104527');
    AU(25, 'Kiosk 1 (Lobby)', 'kiosk', 'Registered walk-in to queue', 'Dr. Benjamin Garcia');
    AU(42, 'Admin — Carlo Bernardo', 'admin', 'Updated system setting', 'Kiosk inactivity timeout');
    AU(55, 'Dr. Maria Lourdes Santos', 'doctor', 'Viewed medical record', 'Patient DYH-104501');
    AU(80, 'Rogelio Manansala', 'frontdesk', 'Verified patient account', 'Patient DYH-104510');
    AU(130, 'Admin — Carlo Bernardo', 'admin', 'Published announcement', 'Free blood pressure screening');
    AU(200, 'Patricia Lim', 'secretary', 'Rescheduled appointment', 'Patient DYH-104502');
    AU(600, 'System', 'system', 'Sent appointment reminders (SMS)', '38 recipients');

    const verifications = [
      { id: 'v1', patientId: 'p19', type: 'link', requestedCode: 'DYH-104502', note: 'App user wants to link to existing record', at: now - 86400000 * 2, status: 'pending' },
      { id: 'v2', patientId: 'p20', type: 'new', requestedCode: '', note: 'New app registration — mobile verified', at: now - 86400000 * 5, status: 'pending' },
    ];
    const duplicates = [
      { id: 'x1', a: 'p2', b: 'p19', reasons: ['Same last name and birthday', 'Similar first name'], score: 92, status: 'open' },
      { id: 'x2', a: 'p3', b: 'p20', reasons: ['Same birthday', 'Same mobile number', 'Similar name'], score: 96, status: 'open' },
    ];

    const templates = {
      d1: {
        dx: ['Essential hypertension, controlled', 'Essential hypertension, uncontrolled', 'Type 2 diabetes mellitus without complications', 'Acute upper respiratory infection', 'Dyslipidemia', 'Acute gastroenteritis', 'Urinary tract infection'],
        rx: [
          { name: 'Hypertension starter', items: [{ drug: 'Amlodipine 5 mg tablet', dose: '1 tablet', freq: 'Once a day', dur: '30 days' }] },
          { name: 'URI symptomatic', items: [{ drug: 'Paracetamol 500 mg tablet', dose: '1 tablet', freq: 'Every 4 hours if fever', dur: '3 days' }, { drug: 'Cetirizine 10 mg tablet', dose: '1 tablet', freq: 'Once a day at bedtime', dur: '5 days' }] },
          { name: 'Diabetes maintenance', items: [{ drug: 'Metformin 500 mg tablet', dose: '1 tablet', freq: 'Twice a day after meals', dur: '30 days' }] },
        ],
      },
    };

    const notifications = [
      { id: uid('nt'), patientId: 'p1', at: now - 3600000 * 20, title: 'Appointment confirmed', body: 'Your appointment with Dr. Ramon Villanueva is confirmed.', read: true },
      { id: uid('nt'), patientId: 'p1', at: now - 3600000 * 50, title: 'Follow-up scheduled by your doctor', body: 'Dr. Teresa Navarro scheduled a follow-up for you. Please bring your HbA1c result.', read: false },
    ];

    // A secretary-prepared draft waiting for the doctor's review
    const qd = queue.find((q) => q.doctorId === 'd1' && q.patientId === 'p3' && q.status === 'waiting');
    const drafts = qd ? [{ id: uid('dr'), queueId: qd.id, patientId: 'p3', doctorId: 'd1', complaint: 'Follow-up for blood pressure; occasional nape pain', vitals: { bp: '148/92', hr: '84', temp: '36.5', wt: '58' }, notes: 'Brought home BP log', by: 'Ella Mae Gonzales', at: now - 1200000, status: 'pending' }] : [];

    return {
      version: 1, seededOn: T, doctors, patients, queue, appointments, records, accounts, clinics, announcements, feedback, sms, audit, verifications, duplicates, templates, notifications, drafts,
      settings: { bookingWindowDays: 30, cancelCutoffHours: 2, maxActiveAppts: 3, sameDayBooking: true, kioskTimeout: 90, kioskWarn: 15, reminderHours: 24, approachingAt: 3, queueMode: 'priorityFirst', avgConsultMins: 12, smsEnabled: true, smsLowCredit: 500, feedbackOnKiosk: true },
      session: { patientId: null, staff: {} },
    };
  }
  function pad3(n) { return String(n).padStart(3, '0'); }

  /* ---------- Store ---------- */
  let DB = null;
  const listeners = new Set();
  function load() {
    try {
      const raw = localStorage.getItem(KEY);
      if (raw) { const d = JSON.parse(raw); if (d && d.version === 1 && d.seededOn === today()) { DB = d; return; } }
    } catch (e) { /* corrupt or unavailable */ }
    DB = seed(); save(true);
  }
  function save(silent) {
    try { localStorage.setItem(KEY, JSON.stringify(DB)); } catch (e) { /* storage full/blocked: keep in memory */ }
    if (!silent) listeners.forEach((fn) => fn());
  }
  function reset() { DB = seed(); save(); }
  window.addEventListener('storage', (e) => {
    if (e.key !== KEY || !e.newValue) return;
    try { DB = JSON.parse(e.newValue); listeners.forEach((fn) => fn('remote')); } catch (err) { /* ignore */ }
  });

  /* ---------- Query helpers ---------- */
  const doctor = (id) => DB.doctors.find((d) => d.id === id);
  const patient = (id) => DB.patients.find((p) => p.id === id);
  const spec = (id) => SPECIALTIES.find((s) => s.id === id);
  const specName = (id) => I18N.pick(spec(id) ? { en: spec(id).en, fil: spec(id).fil } : '');
  const fullName = (p) => p ? [p.first, p.middle, p.last].filter(Boolean).join(' ') : '';
  const shortName = (p) => p ? `${p.first} ${p.last}` : '';
  function priorityOf(p) { if (!p) return null; if (p.priority) return p.priority; const a = ageFrom(p.bday); return a != null && a >= 60 ? 'senior' : null; }
  const dayNames = (days) => days.map((d) => t(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d])).join(', ');
  function scheduleText(d) { return `${dayNames(d.days)} · ${UI.fmtTime(d.start)}–${UI.fmtTime(d.end)}`; }
  function isClinicDay(d, date) { return d.days.includes(UI.parseISO(date).getDay()) && !d.blocked.some((b) => b.date === date) && d.status === 'active'; }

  function todaysQueue(doctorId) { return DB.queue.filter((q) => q.doctorId === doctorId && q.date === today()); }
  function waitingOrdered(doctorId) {
    const w = todaysQueue(doctorId).filter((q) => q.status === 'waiting');
    const prio = (q) => (DB.settings.queueMode === 'priorityFirst' && priorityOf(patient(q.patientId)) ? 0 : 1);
    return w.sort((a, b) => (b.front - a.front) || (prio(a) - prio(b)) || (a.seq - b.seq));
  }
  function serving(doctorId) { return todaysQueue(doctorId).find((q) => q.status === 'serving'); }
  function lastCalled(doctorId) {
    const s = serving(doctorId); if (s) return s;
    return todaysQueue(doctorId).filter((q) => q.status === 'done').sort((a, b) => b.doneAt - a.doneAt)[0];
  }
  function walkInStatus(d) {
    const T = today();
    const q = todaysQueue(d.id);
    const walkIns = q.filter((x) => x.source !== 'appointment' && x.status !== 'cancelled').length;
    const left = Math.max(0, d.walkInCap - walkIns);
    const s = serving(d.id);
    const base = { servingNo: s ? s.no : '—', waiting: q.filter((x) => x.status === 'waiting').length, left };
    if (!isClinicDay(d, T)) {
      const blocked = d.blocked.find((b) => b.date === T);
      return { ...base, state: 'closed', reason: blocked ? t('On leave today') : t('No clinic today'), left: 0 };
    }
    if (!d.walkIn) return { ...base, state: 'closed', reason: t('By appointment only'), left: 0 };
    if (left <= 0) return { ...base, state: 'full', reason: t('All walk-in slots for today are taken') };
    return { ...base, state: 'open', reason: '' };
  }
  function nextClinicDays(d, from, count) {
    const out = []; let cur = from;
    for (let i = 0; i < DB.settings.bookingWindowDays + 1 && out.length < count; i++) { cur = addDays(from, i); if (isClinicDay(d, cur)) out.push(cur); }
    return out;
  }
  function slotsFor(doctorId, date) {
    const d = doctor(doctorId);
    const [sh, sm] = d.start.split(':').map(Number), [eh, em] = d.end.split(':').map(Number);
    const out = [];
    const taken = new Set(DB.appointments.filter((a) => a.doctorId === doctorId && a.date === date && !['cancelled'].includes(a.status)).map((a) => a.time));
    const nowMins = new Date().getHours() * 60 + new Date().getMinutes();
    for (let m = sh * 60 + sm; m + d.slot <= eh * 60 + em; m += d.slot) {
      const time = `${pad(Math.floor(m / 60))}:${pad(m % 60)}`;
      const past = date === today() && m <= nowMins + 30;
      out.push({ time, taken: taken.has(time) || past });
    }
    return out;
  }
  function positionOf(entry) {
    if (!entry) return null;
    if (entry.status === 'serving') return { ahead: 0, serving: true };
    const w = waitingOrdered(entry.doctorId);
    const idx = w.findIndex((q) => q.id === entry.id);
    const s = serving(entry.doctorId);
    return { ahead: idx < 0 ? 0 : idx, serving: false, nowServing: s ? s.no : '—', etaMins: Math.round((idx + (s ? 0.5 : 0)) * DB.settings.avgConsultMins) };
  }

  /* ---------- Side effects ---------- */
  function audit(user, role, action, target) { DB.audit.unshift({ id: uid('l'), at: Date.now(), user, role, action, target }); }
  function notify(patientId, title, body, viaSms) {
    const p = patient(patientId); if (!p) return;
    DB.notifications.unshift({ id: uid('nt'), patientId, at: Date.now(), title, body, read: false });
    if (viaSms && DB.settings.smsEnabled && p.mobile) {
      DB.sms.log.unshift({ id: uid('s'), at: Date.now(), to: p.mobile, msg: body, status: DB.sms.credits > 0 ? 'delivered' : 'failed', type: 'queue', simulated: true });
      if (DB.sms.credits > 0) DB.sms.credits--;
    }
  }
  function notifyApproaching(doctorId) {
    const w = waitingOrdered(doctorId);
    const d = doctor(doctorId);
    w.forEach((q, i) => {
      if (i === 0 && !q.notifiedNext) { q.notifiedNext = true; notify(q.patientId, t('You are next'), t('You are next for {doc}. Please stay near Room {room}.', { doc: d.name, room: d.room }), true); }
      else if (i < DB.settings.approachingAt && !q.notifiedNear) { q.notifiedNear = true; notify(q.patientId, t('Your turn is approaching'), t('{n} patient(s) ahead of you for {doc}, Room {room}.', { n: i, doc: d.name, room: d.room }), true); }
    });
  }

  /* ---------- Simulated backend API ---------- */
  const MockAPI = {
    addToQueue({ doctorId, patientId, source, by }) {
      const d = doctor(doctorId);
      const existing = todaysQueue(doctorId).find((q) => q.patientId === patientId && ['waiting', 'serving'].includes(q.status));
      if (existing) return { error: 'already', entry: existing };
      const seq = todaysQueue(doctorId).reduce((m, q) => Math.max(m, q.seq), 0) + 1;
      const entry = { id: uid('q'), doctorId, patientId, seq, no: `${d.prefix}-${pad3(seq)}`, date: today(), status: 'waiting', source, createdAt: Date.now(), calledAt: null, doneAt: null, front: false };
      DB.queue.push(entry);
      audit(by || 'System', source === 'kiosk' ? 'kiosk' : 'system', 'Added patient to queue', `${entry.no} · ${d.name}`);
      notifyApproaching(doctorId);
      save();
      return { entry };
    },
    /** Finish whoever is in consultation and call the next waiting patient. */
    callNext(doctorId, by) {
      const s = serving(doctorId);
      if (s) { s.status = 'done'; s.doneAt = Date.now(); }
      const next = waitingOrdered(doctorId)[0];
      if (next) { next.status = 'serving'; next.calledAt = Date.now(); next.front = false; notify(next.patientId, t('It is your turn'), t('Please proceed to Room {room} now.', { room: doctor(doctorId).room }), true); }
      notifyApproaching(doctorId);
      audit(by, 'secretary', 'Called next patient', next ? `Queue ${next.no}` : 'Queue empty');
      save();
      return next;
    },
    startConsult(entryId, by) {
      const q = DB.queue.find((x) => x.id === entryId); if (!q) return;
      const s = serving(q.doctorId); if (s && s.id !== q.id) { s.status = 'waiting'; s.front = true; }
      q.status = 'serving'; q.calledAt = Date.now(); q.front = false;
      notify(q.patientId, t('It is your turn'), t('Please proceed to Room {room} now.', { room: doctor(q.doctorId).room }), true);
      notifyApproaching(q.doctorId);
      audit(by, 'secretary', 'Started consultation', `Queue ${q.no}`); save();
    },
    markDone(entryId, by) {
      const q = DB.queue.find((x) => x.id === entryId); if (!q) return;
      q.status = 'done'; q.doneAt = Date.now(); audit(by, 'staff', 'Marked consultation done', `Queue ${q.no}`); save();
    },
    skip(entryId, by) {
      const q = DB.queue.find((x) => x.id === entryId); if (!q) return;
      q.status = 'skipped'; q.skippedAt = Date.now(); q.front = false;
      notify(q.patientId, t('You missed your call'), t('Your number {no} was called but you were not present. Please approach the clinic secretary.', { no: q.no }), true);
      notifyApproaching(q.doctorId);
      audit(by, 'secretary', 'Skipped patient (not present)', `Queue ${q.no}`); save();
    },
    callBack(entryId, by) {
      const q = DB.queue.find((x) => x.id === entryId); if (!q) return;
      q.status = 'waiting'; q.front = true; q.notifiedNext = false;
      notifyApproaching(q.doctorId);
      audit(by, 'secretary', 'Called back skipped patient', `Queue ${q.no}`); save();
    },
    cancelQueue(entryId, by) {
      const q = DB.queue.find((x) => x.id === entryId); if (!q) return;
      q.status = 'cancelled'; audit(by, 'patient', 'Left the queue', `Queue ${q.no}`); notifyApproaching(q.doctorId); save();
    },
    book({ patientId, doctorId, date, time, reason, type, by, scheduledBy }) {
      const active = DB.appointments.filter((a) => a.patientId === patientId && ['booked', 'confirmed'].includes(a.status) && a.date >= today());
      if (!scheduledBy && active.length >= DB.settings.maxActiveAppts) return { error: t('You already have {n} upcoming appointments. Please cancel one before booking another.', { n: active.length }) };
      if (DB.appointments.some((a) => a.doctorId === doctorId && a.date === date && a.time === time && a.status !== 'cancelled')) return { error: t('Sorry, this time was just taken. Please choose another time.') };
      const a = { id: uid('a'), patientId, doctorId, date, time, status: 'booked', type: type || 'appointment', reason: reason || '', createdAt: Date.now(), scheduledBy };
      DB.appointments.push(a);
      const d = doctor(doctorId);
      notify(patientId, scheduledBy ? t('Follow-up scheduled by your doctor') : t('Appointment booked'), t('{doc} on {date} at {time}, Room {room}.', { doc: d.name, date: UI.fmtDate(date), time: UI.fmtTime(time), room: d.room }), true);
      audit(by || 'Patient app', scheduledBy ? 'doctor' : 'patient', scheduledBy ? 'Scheduled follow-up' : 'Booked appointment', `${d.name} · ${date} ${time}`);
      save(); return { appt: a };
    },
    cancelAppt(id, by, role) {
      const a = DB.appointments.find((x) => x.id === id); if (!a) return;
      a.status = 'cancelled';
      notify(a.patientId, t('Appointment cancelled'), t('Your appointment on {date} at {time} was cancelled.', { date: UI.fmtDate(a.date), time: UI.fmtTime(a.time) }), role !== 'patient');
      audit(by, role || 'patient', 'Cancelled appointment', `${doctor(a.doctorId).name} · ${a.date}`); save();
    },
    reschedule(id, date, time, by, role) {
      const a = DB.appointments.find((x) => x.id === id); if (!a) return {};
      if (DB.appointments.some((x) => x.id !== id && x.doctorId === a.doctorId && x.date === date && x.time === time && x.status !== 'cancelled')) return { error: t('Sorry, this time was just taken. Please choose another time.') };
      a.date = date; a.time = time; a.status = 'booked';
      notify(a.patientId, t('Appointment rescheduled'), t('New schedule: {date} at {time}.', { date: UI.fmtDate(date), time: UI.fmtTime(time) }), true);
      audit(by, role || 'patient', 'Rescheduled appointment', `${doctor(a.doctorId).name} · ${date} ${time}`); save(); return { appt: a };
    },
    confirmAppt(id) { const a = DB.appointments.find((x) => x.id === id); if (a) { a.status = 'confirmed'; audit('Patient app', 'patient', 'Confirmed appointment', a.date); save(); } },
    markArrived(id, by) {
      const a = DB.appointments.find((x) => x.id === id); if (!a) return {};
      a.status = 'arrived';
      const r = MockAPI.addToQueue({ doctorId: a.doctorId, patientId: a.patientId, source: 'appointment', by });
      audit(by, 'secretary', 'Marked appointment as arrived', `${shortName(patient(a.patientId))}`); save();
      return r;
    },
    registerPatient(data, by) {
      const id = 'p' + (DB.patients.length + 1 + Math.floor(Math.random() * 1000));
      const code = 'DYH-' + (105000 + DB.patients.length + Math.floor(Math.random() * 900));
      const p = { id, code, verified: by !== 'Patient app', createdAt: Date.now(), priority: null, ...data };
      DB.patients.push(p);
      audit(by || 'Kiosk 1 (Lobby)', by === 'Patient app' ? 'patient' : 'kiosk', 'Registered new patient', code);
      save(); return p;
    },
    saveRecord(rec, by) {
      const i = DB.records.findIndex((r) => r.id === rec.id);
      if (i >= 0) DB.records[i] = rec; else DB.records.push(rec);
      if (rec.status === 'signed') audit(by, 'doctor', 'Signed consultation record', `Patient ${patient(rec.patientId).code}`);
      else audit(by, 'doctor', 'Saved consultation draft', `Patient ${patient(rec.patientId).code}`);
      save();
    },
    feedback(rating, comment, source) { DB.feedback.unshift({ id: uid('f'), rating, comment, source, at: Date.now(), doctorId: null }); save(); },
    log: audit,
    notify,
  };

  window.Store = {
    get db() { return DB; }, load, save, reset, on(fn) { listeners.add(fn); return () => listeners.delete(fn); },
  };
  window.Q = { doctor, patient, spec, specName, fullName, shortName, priorityOf, scheduleText, dayNames, isClinicDay, todaysQueue, waitingOrdered, serving, lastCalled, walkInStatus, nextClinicDays, slotsFor, positionOf, SPECIALTIES, EMERGENCY_KW, PSGC };
  window.MockAPI = MockAPI;
  load();
})();
