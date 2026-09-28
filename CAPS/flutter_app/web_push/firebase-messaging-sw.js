// Only for the WEB build (Chrome). Copy this file into flutter_app/web/
// and fill in the same Firebase values you pass with --dart-define.
// Android does not need it.
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: 'FIREBASE_API_KEY',
  appId: 'FIREBASE_APP_ID',
  messagingSenderId: 'FIREBASE_SENDER_ID',
  projectId: 'FIREBASE_PROJECT_ID',
});

// Shows the notification when the app tab is closed / in the background.
firebase.messaging();
