import {
  onAuthStateChanged,
  signInWithPopup,
  signOut,
} from 'firebase/auth'
import { useEffect, useMemo, useState } from 'react'
import { AuthContext } from './AuthContextObject'
import {
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

  return 'İşlem sırasında bir hata oluştu. Lütfen tekrar dene.'
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!auth) {
      setLoading(false)
      return undefined
    }

    const unsubscribe = onAuthStateChanged(
      auth,
      (currentUser) => {
        setUser(currentUser)
        setLoading(false)
      },
      () => {
        setError('Oturum bilgisi alınamadı. Lütfen sayfayı yenileyip tekrar dene.')
        setLoading(false)
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
      error,
      isFirebaseConfigured,
      isAuthenticated: Boolean(user),
      loading,
      loginWithGoogle,
      logout,
      user,
    }),
    [error, loading, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
