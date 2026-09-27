// GENERATED from the l10n string table — edit both languages together.
// ignore_for_file: lines_longer_than_80_chars
import 'app_text.dart';

class AppTextEn extends AppText {
  const AppTextEn();

  // ── General
  @override
  String get appTitle => 'Barangay Biñang 2nd — Resident Portal';
  @override
  String get appName => 'Barangay Biñang 2nd Resident Portal';
  @override
  String get residentPortal => 'Resident Portal';
  @override
  String get cancel => 'Cancel';
  @override
  String get ok => 'OK';
  @override
  String get save => 'Save';
  @override
  String get reset => 'Reset';
  @override
  String get close => 'Close';
  @override
  String get required => 'Required.';
  @override
  String get optional => 'Optional';
  @override
  String get backToLogin => 'Back to Login';
  @override
  String comingSoon(String feature) => '$feature — coming soon.';
  @override
  List<String> get monthsShort => const [
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'May',
        'Jun',
        'Jul',
        'Aug',
        'Sep',
        'Oct',
        'Nov',
        'Dec'
      ];
  @override
  String get refresh => 'Refresh';

  // ── Errors / API
  @override
  String get errTimeout => 'The server timed out. Please try again.';
  @override
  String get errNoConnection =>
      'Cannot reach the server. Check your connection and the API base URL.';
  @override
  String errGeneric(String error) => 'Something went wrong: $error';
  @override
  String get errBadResponse => 'Invalid response from the server.';
  @override
  String get requestSubmitted => 'Request submitted. Please wait for approval.';
  @override
  String get requestSubmitFailed => 'The request was not submitted.';
  @override
  String get requestNotFound => 'No request found.';
  @override
  String get passwordSet => 'Password set. You can now log in.';
  @override
  String get passwordSetFailed => 'The password was not set.';
  @override
  String get loginFailed => 'Wrong email/contact or password.';
  @override
  String get chatLoadFailed => 'Could not load the chat.';
  @override
  String get chatSendFailed => 'Message not sent.';
  @override
  String get chatEndFailed => 'Could not close the conversation.';

  // ── Settings
  @override
  String get settings => 'Settings';
  @override
  String get settingsSubtitle => 'Appearance, language and security';
  @override
  String get residentCode => 'Resident Code';
  @override
  String get appearance => 'Appearance';
  @override
  String get theme => 'Theme';
  @override
  String get themeLight => 'Light';
  @override
  String get themeDark => 'Dark';
  @override
  String get themeSystem => 'Auto';
  @override
  String get themeFollowsBarangay =>
      'Following the barangay default. "Auto" follows your phone.';
  @override
  String get accentColor => 'App color';
  @override
  String get accentBarangayDefault => 'Barangay default color';
  @override
  String get accentCustom => 'Your own color';
  @override
  String get textSize => 'Text size';
  @override
  String get textSmall => 'Small';
  @override
  String get textNormal => 'Normal';
  @override
  String get textLarge => 'Large';
  @override
  String get previewTitle => 'Preview';
  @override
  String get previewBody => 'This is how the app will look.';
  @override
  String get previewButton => 'Button';
  @override
  String get language => 'Language';
  @override
  String get languageEnglishSub => 'Use English in the whole app';
  @override
  String get languageFilipinoSub => 'Use Filipino in the whole app';
  @override
  String get security => 'Security';
  @override
  String get changePassword => 'Change password';
  @override
  String get changePasswordSub => 'Update the password you use to log in';
  @override
  String get changePasswordIntro =>
      'Enter your current password, then choose a new one (at least 8 characters).';
  @override
  String get currentPassword => 'Current password';
  @override
  String get currentPasswordHint => 'Enter your current password';
  @override
  String get newPassword => 'New password';
  @override
  String get confirmPassword => 'Confirm password';
  @override
  String get retypePassword => 'Re-type the password';
  @override
  String get atLeast8 => 'At least 8 characters';
  @override
  String get atLeast8Error => 'Use at least 8 characters.';
  @override
  String get newPasswordSame => 'The new password must be different.';
  @override
  String get passwordsDontMatch => 'Passwords do not match.';
  @override
  String get savePassword => 'Save password';
  @override
  String get passwordChanged => 'Password changed.';
  @override
  String get passwordChangeFailed => 'The password was not changed.';
  @override
  String get changePin => 'Change PIN';
  @override
  String get setUpPin => 'Set up a PIN';
  @override
  String get changePinSub => '6-digit PIN for quick unlock';
  @override
  String get enterCurrentPin => 'Current PIN';
  @override
  String get enterCurrentPinSub => 'Enter your current 6-digit PIN.';
  @override
  String get createPin => 'Create a PIN';
  @override
  String get createPinSub => 'Choose a 6-digit PIN for quick login.';
  @override
  String get confirmPin => 'Confirm PIN';
  @override
  String get confirmPinSub => 'Enter your 6-digit PIN again.';
  @override
  String get wrongPin => 'Wrong PIN. Try again.';
  @override
  String get pinMismatch => 'The PINs don\'t match. Try again.';
  @override
  String get pinChanged => 'PIN changed.';
  @override
  String get pinCreated => 'PIN created.';
  @override
  String get biometricUnlock => 'Fingerprint / face unlock';
  @override
  String get biometricUnlockSub => 'Open the app without typing your PIN';
  @override
  String get biometricConfirmReason => 'Confirm to open your account';
  @override
  String get stayedSignedIn => 'Stay signed in';
  @override
  String sessionUntil(String date) => 'Until $date';
  @override
  String get sessionNotRemembered =>
      'Not remembered on this device — you log in each time.';
  @override
  String get about => 'About';
  @override
  String get version => 'Version';
  @override
  String get needHelp => 'Need help?';
  @override
  String get needHelpSub =>
      'Visit the Barangay Hall or call (044) 123-4567, Mon–Fri 8 AM–5 PM.';
  @override
  String get resetAppearanceTitle => 'Reset appearance';
  @override
  String get resetAppearanceSub =>
      'Back to the barangay default theme, color and text size';
  @override
  String get resetAppearanceBody =>
      'Your theme, color and text size will go back to the barangay defaults. Your language stays the same.';
  @override
  String get resetAppearanceDone => 'Appearance reset.';
  @override
  String get logout => 'Log out';
  @override
  String get logoutConfirmTitle => 'Log out?';
  @override
  String get logoutConfirmBody =>
      'You will need to log in again with your email and password.';

  // ── Access request status
  @override
  String get statusPendingDesc =>
      'Your request is still with the admin. Please wait for an update.';
  @override
  String get statusApprovedDesc => 'Approved! You can now set your password.';
  @override
  String get statusProfilingDesc => 'You need to complete your profile first.';
  @override
  String get statusCorrectionDesc =>
      'Something in your request needs fixing. See the admin note.';
  @override
  String get statusRejectedDesc =>
      'Your request was not approved. See the admin reason.';

  // ── Lock / PIN
  @override
  String get locked => 'Locked';
  @override
  String get secureAccess => 'Secure Access';
  @override
  String helloName(String name) => 'Hello, $name!';
  @override
  String get enterPinToContinue => 'Enter your PIN to continue';
  @override
  String get notYouLogin => 'Not you? Log in with your password';
  @override
  String get enableBiometrics => 'Enable biometrics';
  @override
  String get enableBiometricsSub => 'Unlock with your fingerprint or face';

  // ── Login
  @override
  String get residentLogin => 'Resident Login';
  @override
  String get signInSubtitle => 'Sign in to your resident account';
  @override
  String get emailAddress => 'Email Address';
  @override
  String get enterEmailHint => 'Enter your email address';
  @override
  String get password => 'Password';
  @override
  String get enterPasswordHint => 'Enter your password';
  @override
  String get rememberMe7Days => 'Remember me for 7 days';
  @override
  String get forgotPasswordQ => 'Forgot password?';
  @override
  String get signIn => 'Sign In';
  @override
  String get secureLoginSystem => 'Secure Resident Login System';
  @override
  String get noAccountYet => 'Don\'t have an account yet?';
  @override
  String get requestAccess => 'Request Access';
  @override
  String get haveAccessToken => 'Approved and have an access token?';
  @override
  String get setPassword => 'Set Password';
  @override
  String get trackRequestStatus => 'Track Request Status';
  @override
  String get trackRequestSub =>
      'Enter your email to check the status of your submitted request.';
  @override
  String get check => 'Check';
  @override
  String get enterEmailAndPassword => 'Enter your email and password.';
  @override
  String get enterEmailAddress => 'Enter your email address.';
  @override
  String get adminLabel => 'Admin';
  @override
  String accessStatusLabel(String status) => status;

  // ── Forgot / set password / status
  @override
  String get enterValidEmail => 'Enter a valid email address.';
  @override
  String get forgotPassword => 'Forgot Password';
  @override
  String get forgotPasswordSub =>
      'Enter your registered email address and we\'ll send you a reset link.';
  @override
  String get enterRegisteredEmail => 'Enter your registered email address';
  @override
  String get resetLinkExpiry =>
      'The reset link will expire in 1 hour. Check your spam folder if you don\'t see the email.';
  @override
  String get sendResetLink => 'Send Reset Link';
  @override
  String get checkYourEmail => 'Check Your Email';
  @override
  String get checkYourEmailSub =>
      'If that email is registered, you will receive a password reset link shortly. Please check your inbox (and spam folder).';
  @override
  String get setPasswordSub =>
      'Enter the access token from the barangay, then choose a new password.';
  @override
  String get accessToken => 'Access Token';
  @override
  String get pasteToken => 'Paste your token';
  @override
  String get enterEmailOrContact => 'Enter your email or contact number.';
  @override
  String get requestStatus => 'Request Status';
  @override
  String get emailOrContact => 'Email or contact number';
  @override
  String get checkStatus => 'Check Status';
  @override
  String get adminNote => 'Admin note';

  // ── Landing / home
  @override
  String get selfServiceAccess => 'Self-service access request';
  @override
  String get setPasswordWithToken => 'Set password (with token)';
  @override
  String get accountActive => 'Your resident portal account is active.';

  // ── Request access
  @override
  String get raPleaseUploadAValid => 'Please upload a valid ID.';
  @override
  String get raPleaseUploadASelfie => 'Please upload a selfie holding your ID.';
  @override
  String get raEnterAValidDate => 'Enter a valid date of birth (mm/dd/yyyy).';
  @override
  String get raBarangayAddressIsNot =>
      'Barangay address is not configured yet. Contact the barangay.';
  @override
  String get raPleaseSelectYourStreet =>
      'Please select your street and purok/area.';
  @override
  String get raSubmitRegistration => 'Submit Registration';
  @override
  String get raRequestPortalAccess => 'Request Portal Access';
  @override
  String get raFillOutTheForm =>
      'Fill out the form to request access to the Resident Portal.';
  @override
  String get raUploadAValidId =>
      'Upload a valid ID to verify that you are a resident of Barangay Biñang 2nd. You will receive an email notification after the review.';
  @override
  String get raPersonalInformation => 'Personal Information';
  @override
  String get raFirstName => 'First Name';
  @override
  String get raMiddleName => 'Middle Name';
  @override
  String get raLastName => 'Last Name';
  @override
  String get raEmailAddress => 'Email Address';
  @override
  String get raInvalidEmail => 'Invalid email.';
  @override
  String get raContactNumber => 'Contact Number';
  @override
  String get raFormat09xxxxxxxxx => 'Format: 09XXXXXXXXX';
  @override
  String get raDateOfBirth => 'Date of Birth';
  @override
  String get raPickADate => 'Pick a date';
  @override
  String get raUseMmDdYyyy => 'Use mm/dd/yyyy';
  @override
  String get raAddressInformation => 'ADDRESS INFORMATION';
  @override
  String get raProvince => 'Province';
  @override
  String get raCityMunicipality => 'City/Municipality';
  @override
  String get raHouseLotUnitNumber => 'House / Lot / Unit Number';
  @override
  String get raBuildingName => 'Building Name';
  @override
  String get raStreet => 'Street';
  @override
  String get raNoStreetsConfigured => 'No streets configured';
  @override
  String get raSelectStreet => 'Select street';
  @override
  String get raSubdivisionVillageSitioPurok =>
      'Subdivision / Village / Sitio / Purok';
  @override
  String get raNoAreasConfigured => 'No areas configured';
  @override
  String get raSelectArea => 'Select area';
  @override
  String get raDefaultAddress => 'Default address';
  @override
  String get raYouOnlyNeedTo =>
      'You only need to enter your house number, building, street and subdivision/sitio/purok.';
  @override
  String get raTheBarangayDefaultAddress =>
      'The barangay default address is not configured yet. Please contact the barangay office.';
  @override
  String get raVerificationRequirement => 'Verification Requirement';
  @override
  String get raValidIdUpload => 'Valid ID Upload';
  @override
  String get raClickToUploadYour => 'Click to upload your Valid ID';
  @override
  String get raAcceptedJpgPngMax => 'Accepted: JPG, PNG — Max 5MB';
  @override
  String get raSelfieHoldingYourId => 'Selfie Holding Your ID';
  @override
  String get raClickToUploadYour2 => 'Click to upload your selfie with ID';
  @override
  String get raJpgOrPngFace => 'JPG or PNG · Face + ID both visible';
  @override
  String get raWhatHappensNext => 'WHAT HAPPENS NEXT';
  @override
  String get raYourRequestHasBeen => 'Your request has been received!';
  @override
  String get raTheBarangayStaffWill =>
      'The Barangay Staff will review your application and valid ID. You will receive an email once processed.';
  @override
  String get raWhatHappensNext2 => 'What happens next?';
  @override
  String get raTapToChange => 'Tap to change';
  @override
  String stepOf(int step, int total) => 'Step $step of $total';

  // ── Request access (more)
  @override
  String get region => 'Region';
  @override
  String get zipCode => 'ZIP Code';
  @override
  String get raValidIdExamples =>
      '(Driver\'s License, PhilSys ID, Voter\'s ID, Passport, etc.)';
  @override
  String get raSelected => 'Selected';
  @override
  String get raRequestSubmitted => 'Request Submitted';
  @override
  List<List<String>> get raNextSteps => const [
        [
          'Submit Registration Form',
          'Fill out and submit this form with your valid ID.'
        ],
        [
          'Staff Reviews Application',
          'Barangay staff will verify your information and ID.'
        ],
        [
          'Approve or Reject Request',
          'You will be notified of the decision via email.'
        ],
        [
          'Password Setup Link Sent',
          'If approved, a password setup link will be sent.'
        ],
        [
          'Create Your Password',
          'Set your secure password using the link provided.'
        ]
      ];
  @override
  List<String> get raSuccessSteps => const [
        'Staff reviews your information and valid ID',
        'You receive an email: Approved or Disapproved',
        'If approved, a password setup link will be sent',
        'Set your password and log in to the Resident Portal'
      ];

  // ── Request access (hint)
  @override
  String get raHouseHint => 'e.g. 123, Block 5 Lot 2, Unit 4A';

  // ── Dashboard
  @override
  String moduleLabel(String id) => switch (id) {
        'household' => 'Household',
        'announcements' => 'Announcements',
        'documents' => 'Request Document',
        'complaints' => 'Complaints',
        'officials' => 'Officials',
        'chat' => 'Chat',
        _ => id
      };
  @override
  String get modules => 'Modules';
  @override
  String get notifications => 'Notifications';
  @override
  String get noNotifications => 'No notifications yet';
  @override
  String get profile => 'Profile';
  @override
  String get myProfile => 'My Profile';
  @override
  String get dashboard => 'Dashboard';
  @override
  String get activeResident => 'Active Resident';
  @override
  String get residentAccount => 'Resident Account';
  @override
  String get goodMorning => 'Good morning';
  @override
  String get goodAfternoon => 'Good afternoon';
  @override
  String get goodEvening => 'Good evening';
  @override
  String get totalRequests => 'Total Requests';
  @override
  String get approved => 'Approved';
  @override
  String get pending => 'Pending';
  @override
  String get rejected => 'Rejected';
  @override
  String get reservations => 'Reservations';
  @override
  String get total => 'Total';
  @override
  String get quickAccess => 'Quick Access';
  @override
  String get latestAnnouncements => 'Latest Announcements';
  @override
  String get recentRequests => 'Recent Requests';
  @override
  String get requestSummary => 'Request Summary';
  @override
  String get noAnnouncements => 'No announcements yet';
  @override
  String get noRequests => 'No requests yet';
  @override
  String get makeFirstRequest => 'Make your first request';
  @override
  String get barangayOffice => 'Barangay Office';
  @override
  String get needAssistance => 'Need Assistance?';
  @override
  String get helpBody =>
      'Visit the Barangay Hall or call the office for help with your requests and documents.';
  @override
  String get officeHours => 'Mon – Fri, 8:00 AM – 5:00 PM';

  // ── Chat
  @override
  String get endChatTitle => 'End the conversation?';
  @override
  String get endChatBody => 'You can start a new message any time after.';
  @override
  String get chatClosed => 'Closed.';
  @override
  String get barangayChat => 'Barangay Chat';
  @override
  String get barangayStaff => 'Barangay Staff';
  @override
  String chatConversationStatus(String status) => switch (status) {
        'Pending' => 'Waiting for staff',
        'Ongoing' => 'Ongoing conversation',
        _ => '$status conversation'
      };
  @override
  String get emergencyHotlines => 'Emergency Hotlines';
  @override
  String get noChatYet => 'No conversation yet';
  @override
  String get noChatYetBody =>
      'Send a message to the barangay staff. They will reply as soon as they can.';
  @override
  String get you => 'You';
  @override
  String get typeMessage => 'Type your message…';
  @override
  String get tapToCall => 'Tap to call (on a real device).';
  @override
  String get noHotlines => 'No hotlines configured yet.';

  // ── Complaints (list)
  @override
  String get complaintsSubtitle => 'File and track your complaints';
  @override
  String get fileComplaint => 'File a Complaint';
  @override
  String get myComplaints => 'My Complaints';
  @override
  String get searchComplaints => 'Search (ID, title, category)…';
  @override
  String get all => 'All';
  @override
  String get noComplaintsYet => 'No complaints yet';
  @override
  String get noComplaintsYetBody =>
      'Tap "File a Complaint" to send a complaint to the barangay.';
  @override
  String get noMatches => 'No matches';
  @override
  String get noMatchesBody => 'No complaints match your search or filter.';
  @override
  String get newReply => 'New reply';
  @override
  String get anonymous => 'Anonymous';
  @override
  String get hasAttachment => 'Has attachment';
  @override
  String complaintStatusLabel(String status) => switch (status) {
        'Pending' => 'Pending',
        'Ongoing' => 'Ongoing',
        'Resolved' => 'Resolved',
        _ => status
      };
  @override
  String priorityLabel(String priority) =>
      switch (priority) { 'High (Urgent)' => 'High (Urgent)', _ => priority };
  @override
  String complaintCategoryLabel(String category) =>
      category == 'Other' ? 'Other (specify below)' : category;

  // ── Complaints (form)
  @override
  String get photoTooLarge => 'The photo is too large. 5 MB max.';
  @override
  String get takePhoto => 'Take a photo';
  @override
  String get chooseFromGallery => 'Choose from gallery';
  @override
  String get confirmTruthful => 'Please confirm that your complaint is true.';
  @override
  String get complaintSubmittedTitle => 'Complaint submitted';
  @override
  String complaintSubmittedBody(String ref) =>
      'Reference No: $ref\n\nThe barangay will review it. You can see its status and the admin reply here.';
  @override
  String get requiredFieldsNote => 'Fields marked * are required';
  @override
  String get complaintDetails => 'Complaint Details';
  @override
  String get placeAndDescription => 'Place and Description';
  @override
  String get attachmentOptional => 'Attachment (optional)';
  @override
  String get complaintTitle => 'Title';
  @override
  String get complaintTitleHint => 'Short title of the complaint';
  @override
  String get enterTitle => 'Enter a title.';
  @override
  String get category => 'Category';
  @override
  String get chooseCategoryHint => 'Choose a category';
  @override
  String get chooseCategory => 'Choose a category.';
  @override
  String get complaintKind => 'Kind of complaint';
  @override
  String get complaintKindHint => 'e.g. Illegal parking';
  @override
  String get enterComplaintKind => 'Enter what kind of complaint it is.';
  @override
  String get priority => 'Priority';
  @override
  String get incidentPlace => 'Where it happened';
  @override
  String get incidentPlaceHint => 'Where did it happen?';
  @override
  String get enterIncidentPlace => 'Enter where it happened.';
  @override
  String get descriptionLabel => 'Description';
  @override
  String get descriptionHint =>
      'Describe what happened in detail (who, what, when)…';
  @override
  String get enterDescription => 'Describe the complaint.';
  @override
  String get addPhotoEvidence => 'Add a photo as evidence';
  @override
  String get photoLimits => 'JPG / PNG · up to 5 MB';
  @override
  String get replace => 'Replace';
  @override
  String get remove => 'Remove';
  @override
  String get submitAnonymously => 'Submit anonymously';
  @override
  String get submitAnonymouslySub =>
      'Your name will not be shown to the admin. You can still see the complaint and the reply here in the app.';
  @override
  String get truthfulnessCheck =>
      'I confirm this information is true. Filing a false complaint is against the law.';
  @override
  String get submitComplaint => 'Submit Complaint';

  // ── Complaints (detail)
  @override
  String get status => 'Status';
  @override
  String get barangayReply => 'Barangay\'s Reply';
  @override
  String get details => 'Details';
  @override
  String get attachment => 'Attachment';
  @override
  String submittedOn(String date) => 'Submitted $date';
  @override
  List<String> get complaintSteps =>
      const ['Submitted', 'In progress', 'Resolved'];
  @override
  String get noReplyYet =>
      'The barangay hasn\'t replied yet. This will update once your complaint is reviewed.';
  @override
  String get barangayAdmin => 'Barangay Admin';
  @override
  String get place => 'Place';
  @override
  String get submittedBy => 'Submitted by';
  @override
  String get pdfAttached => 'A PDF is attached to this complaint.';
  @override
  String get imageLoadFailed => 'Could not load the image.';

  // ── Complaints (api)
  @override
  String get complaintsLoadFailed => 'Could not load your complaints.';
  @override
  String get complaintLoadFailed => 'Could not load the complaint.';
  @override
  String get complaintSubmitFailed => 'The complaint was not submitted.';
}
