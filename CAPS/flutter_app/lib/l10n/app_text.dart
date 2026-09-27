// GENERATED from the l10n string table — edit both languages together.
import '../settings/app_settings.dart';

/// Every user-facing string in the app, in English and Filipino.
///
/// Screens use the global [tr] getter (`Text(tr.signIn)`), which follows the
/// language chosen in Settings → Language. Add a string here, then implement
/// it in BOTH app_text_en.dart and app_text_fil.dart (the compiler enforces it).
abstract class AppText {
  const AppText();

  // ── General ─────────────────────────────────────────────────────
  String get appTitle;
  String get appName;
  String get residentPortal;
  String get cancel;
  String get ok;
  String get save;
  String get reset;
  String get close;
  String get required;
  String get optional;
  String get backToLogin;
  String comingSoon(String feature);
  List<String> get monthsShort;
  String get refresh;

  // ── Errors / API ────────────────────────────────────────────────
  String get errTimeout;
  String get errNoConnection;
  String errGeneric(String error);
  String get errBadResponse;
  String get requestSubmitted;
  String get requestSubmitFailed;
  String get requestNotFound;
  String get passwordSet;
  String get passwordSetFailed;
  String get loginFailed;
  String get chatLoadFailed;
  String get chatSendFailed;
  String get chatEndFailed;

  // ── Settings ────────────────────────────────────────────────────
  String get settings;
  String get settingsSubtitle;
  String get residentCode;
  String get appearance;
  String get theme;
  String get themeLight;
  String get themeDark;
  String get themeSystem;
  String get themeFollowsBarangay;
  String get accentColor;
  String get accentBarangayDefault;
  String get accentCustom;
  String get textSize;
  String get textSmall;
  String get textNormal;
  String get textLarge;
  String get previewTitle;
  String get previewBody;
  String get previewButton;
  String get language;
  String get languageEnglishSub;
  String get languageFilipinoSub;
  String get security;
  String get changePassword;
  String get changePasswordSub;
  String get changePasswordIntro;
  String get currentPassword;
  String get currentPasswordHint;
  String get newPassword;
  String get confirmPassword;
  String get retypePassword;
  String get atLeast8;
  String get atLeast8Error;
  String get newPasswordSame;
  String get passwordsDontMatch;
  String get savePassword;
  String get passwordChanged;
  String get passwordChangeFailed;
  String get changePin;
  String get setUpPin;
  String get changePinSub;
  String get enterCurrentPin;
  String get enterCurrentPinSub;
  String get createPin;
  String get createPinSub;
  String get confirmPin;
  String get confirmPinSub;
  String get wrongPin;
  String get pinMismatch;
  String get pinChanged;
  String get pinCreated;
  String get biometricUnlock;
  String get biometricUnlockSub;
  String get biometricConfirmReason;
  String get stayedSignedIn;
  String sessionUntil(String date);
  String get sessionNotRemembered;
  String get about;
  String get version;
  String get needHelp;
  String get needHelpSub;
  String get resetAppearanceTitle;
  String get resetAppearanceSub;
  String get resetAppearanceBody;
  String get resetAppearanceDone;
  String get logout;
  String get logoutConfirmTitle;
  String get logoutConfirmBody;

  // ── Access request status ───────────────────────────────────────
  String get statusPendingDesc;
  String get statusApprovedDesc;
  String get statusProfilingDesc;
  String get statusCorrectionDesc;
  String get statusRejectedDesc;

  // ── Lock / PIN ──────────────────────────────────────────────────
  String get locked;
  String get secureAccess;
  String helloName(String name);
  String get enterPinToContinue;
  String get notYouLogin;
  String get enableBiometrics;
  String get enableBiometricsSub;

  // ── Login ───────────────────────────────────────────────────────
  String get residentLogin;
  String get signInSubtitle;
  String get emailAddress;
  String get enterEmailHint;
  String get password;
  String get enterPasswordHint;
  String get rememberMe7Days;
  String get forgotPasswordQ;
  String get signIn;
  String get secureLoginSystem;
  String get noAccountYet;
  String get requestAccess;
  String get haveAccessToken;
  String get setPassword;
  String get trackRequestStatus;
  String get trackRequestSub;
  String get check;
  String get enterEmailAndPassword;
  String get enterEmailAddress;
  String get adminLabel;
  String accessStatusLabel(String status);

  // ── Forgot / set password / status ──────────────────────────────
  String get enterValidEmail;
  String get forgotPassword;
  String get forgotPasswordSub;
  String get enterRegisteredEmail;
  String get resetLinkExpiry;
  String get sendResetLink;
  String get checkYourEmail;
  String get checkYourEmailSub;
  String get setPasswordSub;
  String get accessToken;
  String get pasteToken;
  String get enterEmailOrContact;
  String get requestStatus;
  String get emailOrContact;
  String get checkStatus;
  String get adminNote;

  // ── Landing / home ──────────────────────────────────────────────
  String get selfServiceAccess;
  String get setPasswordWithToken;
  String get accountActive;

  // ── Request access ──────────────────────────────────────────────
  String get raPleaseUploadAValid;
  String get raPleaseUploadASelfie;
  String get raEnterAValidDate;
  String get raBarangayAddressIsNot;
  String get raPleaseSelectYourStreet;
  String get raSubmitRegistration;
  String get raRequestPortalAccess;
  String get raFillOutTheForm;
  String get raUploadAValidId;
  String get raPersonalInformation;
  String get raFirstName;
  String get raMiddleName;
  String get raLastName;
  String get raEmailAddress;
  String get raInvalidEmail;
  String get raContactNumber;
  String get raFormat09xxxxxxxxx;
  String get raDateOfBirth;
  String get raPickADate;
  String get raUseMmDdYyyy;
  String get raAddressInformation;
  String get raProvince;
  String get raCityMunicipality;
  String get raHouseLotUnitNumber;
  String get raBuildingName;
  String get raStreet;
  String get raNoStreetsConfigured;
  String get raSelectStreet;
  String get raSubdivisionVillageSitioPurok;
  String get raNoAreasConfigured;
  String get raSelectArea;
  String get raDefaultAddress;
  String get raYouOnlyNeedTo;
  String get raTheBarangayDefaultAddress;
  String get raVerificationRequirement;
  String get raValidIdUpload;
  String get raClickToUploadYour;
  String get raAcceptedJpgPngMax;
  String get raSelfieHoldingYourId;
  String get raClickToUploadYour2;
  String get raJpgOrPngFace;
  String get raWhatHappensNext;
  String get raYourRequestHasBeen;
  String get raTheBarangayStaffWill;
  String get raWhatHappensNext2;
  String get raTapToChange;
  String stepOf(int step, int total);

  // ── Request access (more) ───────────────────────────────────────
  String get region;
  String get zipCode;
  String get raValidIdExamples;
  String get raSelected;
  String get raRequestSubmitted;
  List<List<String>> get raNextSteps;
  List<String> get raSuccessSteps;

  // ── Request access (hint) ───────────────────────────────────────
  String get raHouseHint;

  // ── Dashboard ───────────────────────────────────────────────────
  String moduleLabel(String id);
  String get modules;
  String get notifications;
  String get noNotifications;
  String get profile;
  String get myProfile;
  String get dashboard;
  String get activeResident;
  String get residentAccount;
  String get goodMorning;
  String get goodAfternoon;
  String get goodEvening;
  String get totalRequests;
  String get approved;
  String get pending;
  String get rejected;
  String get reservations;
  String get total;
  String get quickAccess;
  String get latestAnnouncements;
  String get recentRequests;
  String get requestSummary;
  String get noAnnouncements;
  String get noRequests;
  String get makeFirstRequest;
  String get barangayOffice;
  String get needAssistance;
  String get helpBody;
  String get officeHours;

  // ── Chat ────────────────────────────────────────────────────────
  String get endChatTitle;
  String get endChatBody;
  String get chatClosed;
  String get barangayChat;
  String get barangayStaff;
  String chatConversationStatus(String status);
  String get emergencyHotlines;
  String get noChatYet;
  String get noChatYetBody;
  String get you;
  String get typeMessage;
  String get tapToCall;
  String get noHotlines;

  // ── Complaints (list) ───────────────────────────────────────────
  String get complaintsSubtitle;
  String get fileComplaint;
  String get myComplaints;
  String get searchComplaints;
  String get all;
  String get noComplaintsYet;
  String get noComplaintsYetBody;
  String get noMatches;
  String get noMatchesBody;
  String get newReply;
  String get anonymous;
  String get hasAttachment;
  String complaintStatusLabel(String status);
  String priorityLabel(String priority);
  String complaintCategoryLabel(String category);

  // ── Complaints (form) ───────────────────────────────────────────
  String get photoTooLarge;
  String get takePhoto;
  String get chooseFromGallery;
  String get confirmTruthful;
  String get complaintSubmittedTitle;
  String complaintSubmittedBody(String ref);
  String get requiredFieldsNote;
  String get complaintDetails;
  String get placeAndDescription;
  String get attachmentOptional;
  String get complaintTitle;
  String get complaintTitleHint;
  String get enterTitle;
  String get category;
  String get chooseCategoryHint;
  String get chooseCategory;
  String get complaintKind;
  String get complaintKindHint;
  String get enterComplaintKind;
  String get priority;
  String get incidentPlace;
  String get incidentPlaceHint;
  String get enterIncidentPlace;
  String get descriptionLabel;
  String get descriptionHint;
  String get enterDescription;
  String get addPhotoEvidence;
  String get photoLimits;
  String get replace;
  String get remove;
  String get submitAnonymously;
  String get submitAnonymouslySub;
  String get truthfulnessCheck;
  String get submitComplaint;

  // ── Complaints (detail) ─────────────────────────────────────────
  String get status;
  String get barangayReply;
  String get details;
  String get attachment;
  String submittedOn(String date);
  List<String> get complaintSteps;
  String get noReplyYet;
  String get barangayAdmin;
  String get place;
  String get submittedBy;
  String get pdfAttached;
  String get imageLoadFailed;

  // ── Complaints (api) ────────────────────────────────────────────
  String get complaintsLoadFailed;
  String get complaintLoadFailed;
  String get complaintSubmitFailed;
}

/// The strings for the current app language.
AppText get tr => AppSettings.instance.text;
