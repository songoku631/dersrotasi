import { initializeApp } from 'firebase/app'
import { getAuth, GoogleAuthProvider, OAuthProvider } from 'firebase/auth'

const firebaseConfig = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
}

const isFirebaseConfigured = Object.values(firebaseConfig).every(Boolean)

const app = isFirebaseConfigured ? initializeApp(firebaseConfig) : null
const auth = app ? getAuth(app) : null
const googleProvider = auth ? new GoogleAuthProvider() : null
const appleProvider = auth ? new OAuthProvider('apple.com') : null

if (googleProvider) {
  googleProvider.setCustomParameters({
    prompt: 'select_account',
  })
}

if (appleProvider) {
  appleProvider.addScope('email')
  appleProvider.addScope('name')
}

export { app, appleProvider, auth, googleProvider, isFirebaseConfigured }
