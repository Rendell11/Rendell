// GENERATED from the l10n string table — edit both languages together.
// ignore_for_file: lines_longer_than_80_chars
import 'app_text.dart';

class AppTextFil extends AppText {
  const AppTextFil();

  // ── General
  @override
  String get appTitle => 'Barangay Biñang 2nd — Resident Portal';
  @override
  String get appName => 'Resident Portal ng Barangay Biñang 2nd';
  @override
  String get residentPortal => 'Resident Portal';
  @override
  String get cancel => 'Kanselahin';
  @override
  String get ok => 'OK';
  @override
  String get save => 'I-save';
  @override
  String get reset => 'I-reset';
  @override
  String get close => 'Isara';
  @override
  String get required => 'Kailangan.';
  @override
  String get optional => 'Opsyonal';
  @override
  String get backToLogin => 'Bumalik sa Login';
  @override
  String comingSoon(String feature) => '$feature — malapit nang idagdag.';
  @override
  List<String> get monthsShort => const [
        'Ene',
        'Peb',
        'Mar',
        'Abr',
        'May',
        'Hun',
        'Hul',
        'Ago',
        'Set',
        'Okt',
        'Nob',
        'Dis'
      ];
  @override
  String get refresh => 'I-refresh';

  // ── Errors / API
  @override
  String get errTimeout => 'Nag-timeout ang server. Subukan muli.';
  @override
  String get errNoConnection =>
      'Hindi makakonekta sa server. Tingnan ang koneksyon at API base URL.';
  @override
  String errGeneric(String error) => 'Nagkaproblema: $error';
  @override
  String get errBadResponse => 'Hindi wastong sagot ng server.';
  @override
  String get requestSubmitted =>
      'Naisumite ang request. Maghintay ng approval.';
  @override
  String get requestSubmitFailed => 'Hindi naisumite ang request.';
  @override
  String get requestNotFound => 'Walang nahanap na request.';
  @override
  String get passwordSet => 'Naitakda ang password. Maaari ka nang mag-login.';
  @override
  String get passwordSetFailed => 'Hindi naitakda ang password.';
  @override
  String get loginFailed => 'Maling email/contact o password.';
  @override
  String get chatLoadFailed => 'Hindi ma-load ang chat.';
  @override
  String get chatSendFailed => 'Hindi naipadala.';
  @override
  String get chatEndFailed => 'Hindi naisara.';

  // ── Settings
  @override
  String get settings => 'Settings';
  @override
  String get settingsSubtitle => 'Itsura, wika at seguridad';
  @override
  String get residentCode => 'Resident Code';
  @override
  String get appearance => 'Itsura';
  @override
  String get theme => 'Tema';
  @override
  String get themeLight => 'Light';
  @override
  String get themeDark => 'Dark';
  @override
  String get themeSystem => 'Auto';
  @override
  String get themeFollowsBarangay =>
      'Sinusunod ang default ng barangay. Ang "Auto" ay sumusunod sa phone mo.';
  @override
  String get accentColor => 'Kulay ng app';
  @override
  String get accentBarangayDefault => 'Default na kulay ng barangay';
  @override
  String get accentCustom => 'Sariling kulay mo';
  @override
  String get textSize => 'Laki ng text';
  @override
  String get textSmall => 'Maliit';
  @override
  String get textNormal => 'Normal';
  @override
  String get textLarge => 'Malaki';
  @override
  String get previewTitle => 'Preview';
  @override
  String get previewBody => 'Ganito ang magiging itsura ng app.';
  @override
  String get previewButton => 'Button';
  @override
  String get language => 'Wika';
  @override
  String get languageEnglishSub => 'Gamitin ang English sa buong app';
  @override
  String get languageFilipinoSub => 'Gamitin ang Filipino sa buong app';
  @override
  String get security => 'Seguridad';
  @override
  String get changePassword => 'Palitan ang password';
  @override
  String get changePasswordSub =>
      'Palitan ang password na ginagamit sa pag-login';
  @override
  String get changePasswordIntro =>
      'Ilagay ang kasalukuyang password, tapos pumili ng bago (hindi bababa sa 8 karakter).';
  @override
  String get currentPassword => 'Kasalukuyang password';
  @override
  String get currentPasswordHint => 'Ilagay ang kasalukuyang password';
  @override
  String get newPassword => 'Bagong password';
  @override
  String get confirmPassword => 'Kumpirmahin ang password';
  @override
  String get retypePassword => 'I-type muli ang password';
  @override
  String get atLeast8 => 'Hindi bababa sa 8 karakter';
  @override
  String get atLeast8Error => 'Gumamit ng hindi bababa sa 8 karakter.';
  @override
  String get newPasswordSame => 'Dapat iba ang bagong password.';
  @override
  String get passwordsDontMatch => 'Hindi magkatugma ang password.';
  @override
  String get savePassword => 'I-save ang password';
  @override
  String get passwordChanged => 'Napalitan ang password.';
  @override
  String get passwordChangeFailed => 'Hindi napalitan ang password.';
  @override
  String get changePin => 'Palitan ang PIN';
  @override
  String get setUpPin => 'Gumawa ng PIN';
  @override
  String get changePinSub => '6-digit PIN para sa mabilis na pagbukas';
  @override
  String get enterCurrentPin => 'Kasalukuyang PIN';
  @override
  String get enterCurrentPinSub => 'Ilagay ang kasalukuyang 6-digit PIN mo.';
  @override
  String get createPin => 'Gumawa ng PIN';
  @override
  String get createPinSub =>
      'Pumili ng 6-digit PIN para sa mabilis na pag-login.';
  @override
  String get confirmPin => 'Kumpirmahin ang PIN';
  @override
  String get confirmPinSub => 'Ilagay muli ang 6-digit PIN mo.';
  @override
  String get wrongPin => 'Maling PIN. Subukan ulit.';
  @override
  String get pinMismatch => 'Hindi magkatugma ang PIN. Subukan ulit.';
  @override
  String get pinChanged => 'Napalitan ang PIN.';
  @override
  String get pinCreated => 'Nagawa ang PIN.';
  @override
  String get biometricUnlock => 'Fingerprint / face unlock';
  @override
  String get biometricUnlockSub =>
      'Buksan ang app nang hindi tina-type ang PIN';
  @override
  String get biometricConfirmReason =>
      'Kumpirmahin para buksan ang iyong account';
  @override
  String get stayedSignedIn => 'Manatiling naka-login';
  @override
  String sessionUntil(String date) => 'Hanggang $date';
  @override
  String get sessionNotRemembered =>
      'Hindi naka-save sa device na ito — mag-login ka tuwing bubuksan.';
  @override
  String get about => 'Tungkol sa app';
  @override
  String get version => 'Bersyon';
  @override
  String get needHelp => 'Kailangan ng tulong?';
  @override
  String get needHelpSub =>
      'Pumunta sa Barangay Hall o tumawag sa (044) 123-4567, Lun–Biy 8 AM–5 PM.';
  @override
  String get resetAppearanceTitle => 'I-reset ang itsura';
  @override
  String get resetAppearanceSub =>
      'Ibalik sa default na tema, kulay at laki ng text ng barangay';
  @override
  String get resetAppearanceBody =>
      'Babalik sa default ng barangay ang tema, kulay at laki ng text. Hindi magbabago ang wika.';
  @override
  String get resetAppearanceDone => 'Na-reset ang itsura.';
  @override
  String get logout => 'Mag-logout';
  @override
  String get logoutConfirmTitle => 'Mag-logout?';
  @override
  String get logoutConfirmBody =>
      'Kakailanganin mong mag-login ulit gamit ang email at password.';

  // ── Access request status
  @override
  String get statusPendingDesc =>
      'Nasa admin pa ang request mo. Maghintay ng abiso.';
  @override
  String get statusApprovedDesc =>
      'Aprubado! Maaari mo nang i-set ang password mo.';
  @override
  String get statusProfilingDesc =>
      'Kailangan mo munang kumpletuhin ang profile mo.';
  @override
  String get statusCorrectionDesc =>
      'May kailangang itama sa request mo. Tingnan ang paalala ng admin.';
  @override
  String get statusRejectedDesc =>
      'Hindi naaprubahan ang request. Tingnan ang dahilan ng admin.';

  // ── Lock / PIN
  @override
  String get locked => 'Naka-lock';
  @override
  String get secureAccess => 'Secure Access';
  @override
  String helloName(String name) => 'Kumusta, $name!';
  @override
  String get enterPinToContinue => 'Ilagay ang PIN para magpatuloy';
  @override
  String get notYouLogin => 'Hindi ikaw? Mag-log in gamit ang password';
  @override
  String get enableBiometrics => 'I-enable ang biometrics';
  @override
  String get enableBiometricsSub => 'Buksan gamit ang fingerprint o face';

  // ── Login
  @override
  String get residentLogin => 'Resident Login';
  @override
  String get signInSubtitle => 'Mag-sign in sa iyong resident account';
  @override
  String get emailAddress => 'Email Address';
  @override
  String get enterEmailHint => 'Ilagay ang iyong email address';
  @override
  String get password => 'Password';
  @override
  String get enterPasswordHint => 'Ilagay ang iyong password';
  @override
  String get rememberMe7Days => 'Tandaan ako sa loob ng 7 araw';
  @override
  String get forgotPasswordQ => 'Nakalimutan ang password?';
  @override
  String get signIn => 'Mag-sign In';
  @override
  String get secureLoginSystem => 'Ligtas na Resident Login System';
  @override
  String get noAccountYet => 'Wala ka pang account?';
  @override
  String get requestAccess => 'Mag-request ng Access';
  @override
  String get haveAccessToken => 'Aprubado na at may access token?';
  @override
  String get setPassword => 'I-set ang Password';
  @override
  String get trackRequestStatus => 'Tingnan ang Status ng Request';
  @override
  String get trackRequestSub =>
      'Ilagay ang email mo para makita ang status ng isinumite mong request.';
  @override
  String get check => 'Tingnan';
  @override
  String get enterEmailAndPassword => 'Ilagay ang email at password.';
  @override
  String get enterEmailAddress => 'Ilagay ang email address.';
  @override
  String get adminLabel => 'Admin';
  @override
  String accessStatusLabel(String status) => switch (status) {
        'Pending' => 'Nakabinbin',
        'Approved' => 'Aprubado',
        'Disapproved' => 'Hindi aprubado',
        'Matched' => 'Aprubado',
        'For Profiling' => 'Para sa Profiling',
        'For Correction' => 'Para Itama',
        'Rejected' => 'Tinanggihan',
        _ => status
      };

  // ── Forgot / set password / status
  @override
  String get enterValidEmail => 'Ilagay ang wastong email address.';
  @override
  String get forgotPassword => 'Nakalimutan ang Password';
  @override
  String get forgotPasswordSub =>
      'Ilagay ang nakarehistrong email mo at magpapadala kami ng reset link.';
  @override
  String get enterRegisteredEmail => 'Ilagay ang nakarehistrong email address';
  @override
  String get resetLinkExpiry =>
      'Mag-e-expire ang reset link sa loob ng 1 oras. Tingnan ang spam folder kung wala sa inbox.';
  @override
  String get sendResetLink => 'Ipadala ang Reset Link';
  @override
  String get checkYourEmail => 'Tingnan ang Email Mo';
  @override
  String get checkYourEmailSub =>
      'Kung nakarehistro ang email, makakatanggap ka ng password reset link. Tingnan ang inbox (at spam folder).';
  @override
  String get setPasswordSub =>
      'Ilagay ang access token mula sa barangay, tapos pumili ng bagong password.';
  @override
  String get accessToken => 'Access Token';
  @override
  String get pasteToken => 'I-paste ang token mo';
  @override
  String get enterEmailOrContact => 'Ilagay ang email o contact number.';
  @override
  String get requestStatus => 'Status ng Request';
  @override
  String get emailOrContact => 'Email o contact number';
  @override
  String get checkStatus => 'Tingnan ang Status';
  @override
  String get adminNote => 'Paalala ng admin';

  // ── Landing / home
  @override
  String get selfServiceAccess => 'Self-service na paghiling ng access';
  @override
  String get setPasswordWithToken => 'I-set ang Password (may token)';
  @override
  String get accountActive => 'Aktibo na ang iyong resident portal account.';

  // ── Request access
  @override
  String get raPleaseUploadAValid => 'Mag-upload ng valid ID.';
  @override
  String get raPleaseUploadASelfie => 'Mag-upload ng selfie hawak ang ID.';
  @override
  String get raEnterAValidDate =>
      'Maglagay ng wastong kapanganakan (mm/dd/yyyy).';
  @override
  String get raBarangayAddressIsNot =>
      'Hindi pa naka-set ang barangay address. Makipag-ugnayan sa barangay.';
  @override
  String get raPleaseSelectYourStreet => 'Pumili ng kalye at purok/area.';
  @override
  String get raSubmitRegistration => 'Isumite ang Rehistrasyon';
  @override
  String get raRequestPortalAccess => 'Humiling ng Access';
  @override
  String get raFillOutTheForm =>
      'Punan ang form upang humiling ng access sa Resident Portal.';
  @override
  String get raUploadAValidId =>
      'Mag-upload ng valid ID para patunayan na residente ka ng Barangay Biñang 2nd. May email ka na matatanggap pagkatapos ng review.';
  @override
  String get raPersonalInformation => 'Personal na Impormasyon';
  @override
  String get raFirstName => 'Pangalan';
  @override
  String get raMiddleName => 'Gitnang Pangalan';
  @override
  String get raLastName => 'Apelyido';
  @override
  String get raEmailAddress => 'Email';
  @override
  String get raInvalidEmail => 'Maling email.';
  @override
  String get raContactNumber => 'Contact Number';
  @override
  String get raFormat09xxxxxxxxx => 'Format: 09XXXXXXXXX';
  @override
  String get raDateOfBirth => 'Kapanganakan';
  @override
  String get raPickADate => 'Pumili ng petsa';
  @override
  String get raUseMmDdYyyy => 'Gamitin ang mm/dd/yyyy';
  @override
  String get raAddressInformation => 'IMPORMASYON NG ADDRESS';
  @override
  String get raProvince => 'Probinsya';
  @override
  String get raCityMunicipality => 'Lungsod/Munisipyo';
  @override
  String get raHouseLotUnitNumber => 'House / Lot / Unit';
  @override
  String get raBuildingName => 'Pangalan ng Building';
  @override
  String get raStreet => 'Kalye';
  @override
  String get raNoStreetsConfigured => 'Walang kalye';
  @override
  String get raSelectStreet => 'Pumili ng kalye';
  @override
  String get raSubdivisionVillageSitioPurok =>
      'Subdivision / Village / Sitio / Purok';
  @override
  String get raNoAreasConfigured => 'Walang area';
  @override
  String get raSelectArea => 'Pumili ng area';
  @override
  String get raDefaultAddress => 'Default na address';
  @override
  String get raYouOnlyNeedTo =>
      'Ilagay na lang ang house number, building, kalye at subdivision/sitio/purok.';
  @override
  String get raTheBarangayDefaultAddress =>
      'Hindi pa naka-set ang default na address ng barangay. Makipag-ugnayan sa barangay.';
  @override
  String get raVerificationRequirement => 'Kinakailangan sa Beripikasyon';
  @override
  String get raValidIdUpload => 'Valid ID Upload';
  @override
  String get raClickToUploadYour => 'I-click para mag-upload ng Valid ID';
  @override
  String get raAcceptedJpgPngMax => 'Tinatanggap: JPG, PNG — Max 5MB';
  @override
  String get raSelfieHoldingYourId => 'Selfie Hawak ang ID';
  @override
  String get raClickToUploadYour2 => 'I-click para mag-selfie na hawak ang ID';
  @override
  String get raJpgOrPngFace => 'JPG o PNG · Mukha + ID kitang-kita';
  @override
  String get raWhatHappensNext => 'ANG MGA SUSUNOD';
  @override
  String get raYourRequestHasBeen => 'Natanggap na ang request mo!';
  @override
  String get raTheBarangayStaffWill =>
      'Susuriin ng Barangay Staff ang iyong application at valid ID. May email ka na matatanggap kapag naproseso na.';
  @override
  String get raWhatHappensNext2 => 'Ano ang susunod?';
  @override
  String get raTapToChange => 'I-tap para palitan';
  @override
  String stepOf(int step, int total) => 'Hakbang $step ng $total';

  // ── Request access (more)
  @override
  String get region => 'Rehiyon';
  @override
  String get zipCode => 'ZIP Code';
  @override
  String get raValidIdExamples =>
      '(Driver\'s License, PhilSys ID, Voter\'s ID, Passport, atbp.)';
  @override
  String get raSelected => 'Napili';
  @override
  String get raRequestSubmitted => 'Naisumite ang Request';
  @override
  List<List<String>> get raNextSteps => const [
        ['Isumite ang Form', 'Punan at isumite ang form kasama ang valid ID.'],
        [
          'Susuriin ng Staff',
          'Beberipikahin ng barangay staff ang info at ID mo.'
        ],
        ['Approve o Reject', 'Aabisuhan ka sa email ng desisyon.'],
        ['Password Setup Link', 'Kung aprubado, may link na ipapadala.'],
        ['Gawin ang Password', 'Itakda ang password gamit ang link.']
      ];
  @override
  List<String> get raSuccessSteps => const [
        'Susuriin ng staff ang info at valid ID mo',
        'May email ka: Approved o Disapproved',
        'Kung aprubado, may password setup link',
        'Itakda ang password at mag-login'
      ];

  // ── Request access (hint)
  @override
  String get raHouseHint => 'hal. 123, Block 5 Lot 2, Unit 4A';

  // ── Dashboard
  @override
  String moduleLabel(String id) => switch (id) {
        'household' => 'Sambahayan',
        'announcements' => 'Mga Anunsyo',
        'documents' => 'Humiling ng Dokumento',
        'complaints' => 'Mga Reklamo',
        'officials' => 'Mga Opisyal',
        'chat' => 'Chat',
        _ => id
      };
  @override
  String get modules => 'Mga Module';
  @override
  String get notifications => 'Mga Abiso';
  @override
  String get noNotifications => 'Wala pang notifications';
  @override
  String get profile => 'Profile';
  @override
  String get myProfile => 'Aking Profile';
  @override
  String get dashboard => 'Dashboard';
  @override
  String get activeResident => 'Aktibong Residente';
  @override
  String get residentAccount => 'Resident Account';
  @override
  String get goodMorning => 'Magandang umaga';
  @override
  String get goodAfternoon => 'Magandang hapon';
  @override
  String get goodEvening => 'Magandang gabi';
  @override
  String get totalRequests => 'Kabuuang Request';
  @override
  String get approved => 'Aprubado';
  @override
  String get pending => 'Nakabinbin';
  @override
  String get rejected => 'Tinanggihan';
  @override
  String get reservations => 'Mga Reserbasyon';
  @override
  String get total => 'Kabuuan';
  @override
  String get quickAccess => 'Mabilisang Access';
  @override
  String get latestAnnouncements => 'Pinakabagong Anunsyo';
  @override
  String get recentRequests => 'Mga Kamakailang Request';
  @override
  String get requestSummary => 'Buod ng mga Request';
  @override
  String get noAnnouncements => 'Wala pang anunsyo';
  @override
  String get noRequests => 'Wala pang request';
  @override
  String get makeFirstRequest => 'Gawin ang unang request mo';
  @override
  String get barangayOffice => 'Tanggapan ng Barangay';
  @override
  String get needAssistance => 'Kailangan ng Tulong?';
  @override
  String get helpBody =>
      'Bisitahin ang Barangay Hall o tumawag sa opisina para sa tulong sa iyong mga request at dokumento.';
  @override
  String get officeHours => 'Lun – Biy, 8:00 AM – 5:00 PM';

  // ── Chat
  @override
  String get endChatTitle => 'Isara ang usapan?';
  @override
  String get endChatBody =>
      'Maaari kang magsimula ng bagong mensahe kahit kailan pagkatapos.';
  @override
  String get chatClosed => 'Naisara.';
  @override
  String get barangayChat => 'Barangay Chat';
  @override
  String get barangayStaff => 'Barangay Staff';
  @override
  String chatConversationStatus(String status) => switch (status) {
        'Pending' => 'Hinihintay ang staff',
        'Ongoing' => 'Kasalukuyang usapan',
        _ => 'Usapan: $status'
      };
  @override
  String get emergencyHotlines => 'Emergency Hotlines';
  @override
  String get noChatYet => 'Wala pang usapan';
  @override
  String get noChatYetBody =>
      'Magpadala ng mensahe sa barangay staff. Sasagutin ka nila sa lalong madaling panahon.';
  @override
  String get you => 'Ikaw';
  @override
  String get typeMessage => 'I-type ang iyong mensahe…';
  @override
  String get tapToCall => 'Pindutin para tumawag (sa totoong device).';
  @override
  String get noHotlines => 'Wala pang naka-configure na hotline.';

  // ── Complaints (list)
  @override
  String get complaintsSubtitle => 'Magsumite at subaybayan ang iyong reklamo';
  @override
  String get fileComplaint => 'Magsumite ng Reklamo';
  @override
  String get myComplaints => 'Mga Reklamo Ko';
  @override
  String get searchComplaints => 'Hanapin (ID, pamagat, kategorya)…';
  @override
  String get all => 'Lahat';
  @override
  String get noComplaintsYet => 'Wala ka pang reklamo';
  @override
  String get noComplaintsYetBody =>
      'Pindutin ang "Magsumite ng Reklamo" para magpadala ng reklamo sa barangay.';
  @override
  String get noMatches => 'Walang tugma';
  @override
  String get noMatchesBody =>
      'Walang reklamong tugma sa iyong hinahanap o filter.';
  @override
  String get newReply => 'Bagong sagot';
  @override
  String get anonymous => 'Anonymous';
  @override
  String get hasAttachment => 'May attachment';
  @override
  String complaintStatusLabel(String status) => switch (status) {
        'Pending' => 'Nakabinbin',
        'Ongoing' => 'Inaaksyunan',
        'Resolved' => 'Naresolba',
        _ => status
      };
  @override
  String priorityLabel(String priority) => switch (priority) {
        'Low' => 'Mababa',
        'Medium' => 'Katamtaman',
        'High (Urgent)' => 'Mataas (Urgent)',
        _ => priority
      };
  @override
  String complaintCategoryLabel(String category) => switch (category) {
        'Noise Complaint' => 'Ingay',
        'Garbage/Sanitation' => 'Basura/Kalinisan',
        'Property Dispute' => 'Alitan sa Ari-arian',
        'Harassment' => 'Panliligalig',
        'Domestic Issue' => 'Problema sa Tahanan',
        'Road/Infrastructure' => 'Kalsada/Imprastraktura',
        'Public Safety' => 'Kaligtasang Pampubliko',
        'Other' => 'Iba pa (ilagay sa ibaba)',
        _ => category
      };

  // ── Complaints (form)
  @override
  String get photoTooLarge =>
      'Masyadong malaki ang larawan. Hanggang 5 MB lang.';
  @override
  String get takePhoto => 'Kumuha ng larawan';
  @override
  String get chooseFromGallery => 'Pumili mula sa gallery';
  @override
  String get confirmTruthful => 'Pakikumpirma na totoo ang iyong reklamo.';
  @override
  String get complaintSubmittedTitle => 'Naisumite ang reklamo';
  @override
  String complaintSubmittedBody(String ref) =>
      'Reference No: $ref\n\nSusuriin ito ng barangay. Makikita mo rito ang status at sagot ng admin.';
  @override
  String get requiredFieldsNote => 'Lahat ng may * ay kailangan';
  @override
  String get complaintDetails => 'Detalye ng Reklamo';
  @override
  String get placeAndDescription => 'Lugar at Paglalarawan';
  @override
  String get attachmentOptional => 'Attachment (opsyonal)';
  @override
  String get complaintTitle => 'Pamagat';
  @override
  String get complaintTitleHint => 'Maikling pamagat ng reklamo';
  @override
  String get enterTitle => 'Ilagay ang pamagat.';
  @override
  String get category => 'Kategorya';
  @override
  String get chooseCategoryHint => 'Pumili ng kategorya';
  @override
  String get chooseCategory => 'Pumili ng kategorya.';
  @override
  String get complaintKind => 'Uri ng reklamo';
  @override
  String get complaintKindHint => 'Hal. Illegal parking';
  @override
  String get enterComplaintKind => 'Ilagay kung anong uri ng reklamo.';
  @override
  String get priority => 'Priority';
  @override
  String get incidentPlace => 'Lugar ng insidente';
  @override
  String get incidentPlaceHint => 'Saan nangyari?';
  @override
  String get enterIncidentPlace => 'Ilagay ang lugar ng insidente.';
  @override
  String get descriptionLabel => 'Paglalarawan';
  @override
  String get descriptionHint =>
      'Ilarawan nang detalyado ang nangyari (sino, ano, kailan)…';
  @override
  String get enterDescription => 'Ilarawan ang reklamo.';
  @override
  String get addPhotoEvidence => 'Magdagdag ng larawan bilang ebidensya';
  @override
  String get photoLimits => 'JPG / PNG · hanggang 5 MB';
  @override
  String get replace => 'Palitan';
  @override
  String get remove => 'Alisin';
  @override
  String get submitAnonymously => 'Isumite nang anonymous';
  @override
  String get submitAnonymouslySub =>
      'Hindi ipapakita ang iyong pangalan sa admin. Ikaw pa rin ang makakakita ng reklamo at ng sagot dito sa app.';
  @override
  String get truthfulnessCheck =>
      'Pinapatunayan ko na totoo ang impormasyong ito. Ipinagbabawal ng batas ang pagsasampa ng maling reklamo.';
  @override
  String get submitComplaint => 'Isumite ang Reklamo';

  // ── Complaints (detail)
  @override
  String get status => 'Status';
  @override
  String get barangayReply => 'Sagot ng Barangay';
  @override
  String get details => 'Detalye';
  @override
  String get attachment => 'Attachment';
  @override
  String submittedOn(String date) => 'Isinumite $date';
  @override
  List<String> get complaintSteps =>
      const ['Naisumite', 'Inaaksyunan', 'Naresolba'];
  @override
  String get noReplyYet =>
      'Wala pang sagot ang barangay. Ia-update ito kapag nasuri na ang iyong reklamo.';
  @override
  String get barangayAdmin => 'Barangay Admin';
  @override
  String get place => 'Lugar';
  @override
  String get submittedBy => 'Nagsumite';
  @override
  String get pdfAttached => 'May PDF na naka-attach sa reklamong ito.';
  @override
  String get imageLoadFailed => 'Hindi ma-load ang larawan.';

  // ── Complaints (api)
  @override
  String get complaintsLoadFailed => 'Hindi ma-load ang mga reklamo.';
  @override
  String get complaintLoadFailed => 'Hindi ma-load ang reklamo.';
  @override
  String get complaintSubmitFailed => 'Hindi naisumite ang reklamo.';

  // ── Profile
  @override
  String get profileLoadFailed => 'Hindi ma-load ang profile mo.';
  @override
  String get photoUpdated => 'Na-update ang profile picture.';
  @override
  String get photoUploadFailed => 'Hindi na-save ang larawan. Subukan muli.';
  @override
  String get photoRemoved => 'Tinanggal ang profile picture.';
  @override
  String get changePhoto => 'Palitan ang larawan';
  @override
  String get removePhoto => 'Alisin ang larawan';
  @override
  String get removePhotoTitle => 'Alisin ang profile picture?';
  @override
  String get removePhotoBody => 'Ang initials mo ang ipapakita.';
  @override
  String get profileViewOnly =>
      'Makikita lang dito ang iyong mga detalye. Para magpatama, pumunta sa Barangay Hall.';
  @override
  String get personalInformation => 'Personal na Impormasyon';
  @override
  String get fullName => 'Buong pangalan';
  @override
  String get sex => 'Kasarian';
  @override
  String get birthDate => 'Petsa ng kapanganakan';
  @override
  String get age => 'Edad';
  @override
  String get birthPlace => 'Lugar ng kapanganakan';
  @override
  String get civilStatus => 'Katayuang sibil';
  @override
  String get religion => 'Relihiyon';
  @override
  String get nationality => 'Nasyonalidad';
  @override
  String get contactInformation => 'Impormasyon sa Pakikipag-ugnayan';
  @override
  String get contactNumber => 'Contact number';
  @override
  String get addressLabel => 'Address';
  @override
  String get houseAndStreet => 'Bahay / Kalye';
  @override
  String get purokArea => 'Purok / Area';
  @override
  String get household => 'Sambahayan';
  @override
  String get headOfFamily => 'Pinuno ng pamilya';
  @override
  String get relationshipToHead => 'Relasyon sa pinuno';
  @override
  String get householdHead => 'Pinuno ng sambahayan';
  @override
  String get workAndEducation => 'Trabaho at Edukasyon';
  @override
  String get employment => 'Trabaho';
  @override
  String get education => 'Edukasyon';
  @override
  String get householdIncome => 'Kita ng sambahayan';
  @override
  String get sectorsAndBenefits => 'Sektor at Benepisyo';
  @override
  String get registeredVoter => 'Rehistradong botante';
  @override
  String get pwd => 'PWD';
  @override
  String get seniorCitizen => 'Senior citizen';
  @override
  String get soloParent => 'Solo parent';
  @override
  String get account => 'Account';
  @override
  String get memberSince => 'Rehistrado mula';
  @override
  String get yes => 'Oo';
  @override
  String get no => 'Hindi';
  @override
  String get viewProfile => 'Tingnan ang profile';
  @override
  String valueLabel(String value) => switch (value) {
        'Male' => 'Lalaki',
        'Female' => 'Babae',
        'Single' => 'Walang asawa',
        'Married' => 'May asawa',
        'Widowed' => 'Balo',
        'Separated' => 'Hiwalay',
        'Employed' => 'May trabaho',
        'Unemployed' => 'Walang trabaho',
        'Self-Employed' => 'Sariling negosyo',
        'Retired' => 'Retirado',
        'Student' => 'Estudyante',
        'Head of Family' => 'Pinuno ng Pamilya',
        'Father' => 'Ama',
        'Mother' => 'Ina',
        'Son' => 'Anak (lalaki)',
        'Daughter' => 'Anak (babae)',
        'Spouse' => 'Asawa',
        'Filipino' => 'Pilipino',
        _ => value
      };
}
