import {
  createUserWithEmailAndPassword,
  onIdTokenChanged,
  sendPasswordResetEmail,
  signInWithEmailAndPassword,
  signInWithPopup,
  signOut,
  updateProfile,
} from 'firebase/auth'
import { useEffect, useMemo, useState } from 'react'
import { AuthContext } from './AuthContextObject'
import {
  appleProvider,
  auth,
  googleProvider,
  isFirebaseConfigured,
} from '../firebase/firebase'

const missingConfigMessage =
  'Giriş sistemi henüz yapılandırılmamış. Lütfen site yöneticisine bildir.'

function getAuthErrorMessage(error) {
  const code = error?.code

  if (code === 'auth/popup-closed-by-user') {
    return 'Giriş penceresi kapatıldığı için işlem tamamlanamadı.'
  }

  if (code === 'auth/popup-blocked') {
    return 'Tarayıcı giriş penceresini engelledi. Lütfen açılır pencereye izin ver.'
  }

  if (code === 'auth/cancelled-popup-request') {
    return 'Aynı anda birden fazla giriş isteği açıldı. Lütfen tekrar dene.'
  }

  if (code === 'auth/network-request-failed') {
    return 'Ağ bağlantısı nedeniyle giriş tamamlanamadı. Lütfen bağlantını kontrol et.'
  }

  if (code === 'auth/invalid-credential' || code === 'auth/wrong-password' || code === 'auth/user-not-found') {
    return 'E-posta veya şifre hatalı.'
  }

  if (code === 'auth/email-already-in-use') return 'Bu e-posta adresiyle zaten bir hesap var.'
  if (code === 'auth/invalid-email') return 'Geçerli bir e-posta adresi gir.'
  if (code === 'auth/weak-password') return 'Şifre en az 6 karakter olmalıdır.'
  if (code === 'auth/too-many-requests') return 'Çok fazla deneme yapıldı. Lütfen biraz sonra tekrar dene.'
  if (code === 'auth/operation-not-allowed') {
    return 'Bu giriş yöntemi Firebase projesinde henüz etkin değil. Yönetici ayarları tamamlamalı.'
  }
  if (code === 'auth/account-exists-with-different-credential') {
    return 'Bu e-posta başka bir giriş yöntemiyle kayıtlı. Önce o yöntemle giriş yap.'
  }

  return 'İşlem sırasında bir hata oluştu. Lütfen tekrar dene.'
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [authLoading, setAuthLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!auth) {
      setAuthLoading(false)
      return undefined
    }

    const unsubscribe = onIdTokenChanged(
      auth,
      (currentUser) => {
        setUser(currentUser)
        setError('')
        setAuthLoading(false)
      },
      () => {
        setError('Oturum bilgisi alınamadı. Lütfen sayfayı yenileyip tekrar dene.')
        setAuthLoading(false)
      },
    )

    return unsubscribe
  }, [])

  async function loginWithGoogle() {
    setError('')

    if (!auth || !googleProvider) {
      setError(missingConfigMessage)
      throw new Error(missingConfigMessage)
    }

    try {
      const result = await signInWithPopup(auth, googleProvider)
      return result.user
    } catch (authError) {
      const message = getAuthErrorMessage(authError)
      setError(message)
      throw new Error(message)
    }
  }

  async function loginWithApple() {
    setError('')
    if (!auth || !appleProvider) {
      setError(missingConfigMessage)
      throw new Error(missingConfigMessage)
    }
    try {
      return (await signInWithPopup(auth, appleProvider)).user
    } catch (authError) {
      const message = getAuthErrorMessage(authError)
      setError(message)
      throw new Error(message)
    }
  }

  async function loginWithEmail(email, password) {
    setError('')
    if (!auth) throw new Error(missingConfigMessage)
    try {
      return (await signInWithEmailAndPassword(auth, email.trim(), password)).user
    } catch (authError) {
      const message = getAuthErrorMessage(authError)
      setError(message)
      throw new Error(message)
    }
  }

  async function registerWithEmail(email, password, displayName) {
    setError('')
    if (!auth) throw new Error(missingConfigMessage)
    try {
      const result = await createUserWithEmailAndPassword(auth, email.trim(), password)
      await updateProfile(result.user, { displayName: displayName.trim() })
      return result.user
    } catch (authError) {
      const message = getAuthErrorMessage(authError)
      setError(message)
      throw new Error(message)
    }
  }

  async function resetPassword(email) {
    setError('')
    if (!auth) throw new Error(missingConfigMessage)
    try {
      await sendPasswordResetEmail(auth, email.trim())
    } catch (authError) {
      const message = getAuthErrorMessage(authError)
      setError(message)
      throw new Error(message)
    }
  }

  async function logout() {
    setError('')

    if (!auth) {
      setUser(null)
      return
    }

    try {
      await signOut(auth)
    } catch (authError) {
      const message = getAuthErrorMessage(authError)
      setError(message)
      throw new Error(message)
    }
  }

  const value = useMemo(
    () => ({
      authLoading,
      authReady: !authLoading,
      error,
      isFirebaseConfigured,
      isAuthenticated: Boolean(user),
      loginWithApple,
      loginWithEmail,
      loginWithGoogle,
      logout,
      registerWithEmail,
      resetPassword,
      user,
    }),
    [authLoading, error, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
