import { useEffect, useRef, useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import Logo from '../components/brand/Logo'
import { useAuth } from '../context/useAuth'
import { EMAIL_VERIFICATION_COOLDOWN_SECONDS } from '../utils/emailVerification'

function cooldownStorageKey(uid) {
  return `dersrotasi:email-verification-cooldown:${uid}`
}

function readCooldown(uid) {
  try {
    return Number(window.localStorage.getItem(cooldownStorageKey(uid))) || 0
  } catch {
    return 0
  }
}

function EmailVerificationPage() {
  const location = useLocation()
  const navigate = useNavigate()
  const {
    authLoading,
    emailVerificationRequired,
    error,
    logout,
    refreshEmailVerification,
    resendEmailVerification,
    user,
  } = useAuth()
  const [busy, setBusy] = useState('')
  const actionInFlight = useRef(false)
  const [message, setMessage] = useState('')
  const [cooldownUntil, setCooldownUntil] = useState(() => {
    if (!user) return 0
    return readCooldown(user.uid)
  })
  const [now, setNow] = useState(Date.now())
  const cooldownRemaining = Math.max(0, Math.ceil((cooldownUntil - now) / 1000))

  useEffect(() => {
    if (!user) return undefined
    const sentFromRegistration = location.state?.verificationSent === true
    const storedCooldown = readCooldown(user.uid)
    const nextCooldown = sentFromRegistration
      ? Math.max(storedCooldown, Date.now() + EMAIL_VERIFICATION_COOLDOWN_SECONDS * 1000)
      : storedCooldown
    if (sentFromRegistration) {
      try {
        window.localStorage.setItem(cooldownStorageKey(user.uid), String(nextCooldown))
      } catch {
        /* Cooldown remains available for this page session. */
      }
      setMessage('Doğrulama bağlantısını e-posta adresine gönderdik.')
      navigate(location.pathname, { replace: true, state: null })
    }
    setCooldownUntil(nextCooldown)
  }, [location.pathname, location.state, navigate, user])

  useEffect(() => {
    if (cooldownRemaining === 0) return undefined
    const interval = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(interval)
  }, [cooldownRemaining])

  if (authLoading) return <div className="auth-loading"><p>Oturumun doğrulanıyor...</p></div>
  if (!user) return <Navigate replace to="/giris" />
  if (!emailVerificationRequired) return <Navigate replace to="/" />

  async function resend() {
    if (actionInFlight.current || busy || cooldownRemaining > 0) return
    actionInFlight.current = true
    setBusy('resend')
    setMessage('')
    try {
      await resendEmailVerification()
      const nextCooldown = Date.now() + EMAIL_VERIFICATION_COOLDOWN_SECONDS * 1000
      try {
        window.localStorage.setItem(cooldownStorageKey(user.uid), String(nextCooldown))
      } catch {
        /* Firebase also rate-limits verification sends. */
      }
      setCooldownUntil(nextCooldown)
      setNow(Date.now())
      setMessage('Yeni doğrulama bağlantısı gönderildi.')
    } catch {
      /* Auth context supplies the user-safe message. */
    } finally {
      actionInFlight.current = false
      setBusy('')
    }
  }

  async function checkVerification() {
    if (actionInFlight.current || busy) return
    actionInFlight.current = true
    setBusy('check')
    setMessage('')
    try {
      const verified = await refreshEmailVerification()
      if (verified) {
        navigate('/', { replace: true })
        return
      }
      setMessage('E-posta adresin henüz doğrulanmamış. Bağlantıyı açtıktan sonra tekrar kontrol et.')
    } catch {
      /* Auth context supplies the user-safe message. */
    } finally {
      actionInFlight.current = false
      setBusy('')
    }
  }

  async function signOut() {
    if (actionInFlight.current || busy) return
    actionInFlight.current = true
    setBusy('logout')
    try {
      await logout()
      navigate('/giris', { replace: true })
    } finally {
      actionInFlight.current = false
      setBusy('')
    }
  }

  return (
    <main className="auth-page">
      <section className="auth-card auth-card--compact" aria-labelledby="email-verification-title">
        <div className="auth-brand"><Logo to={null} /></div>
        <div className="auth-heading">
          <h1 id="email-verification-title">E-postanı doğrula</h1>
          <p><strong>{user.email}</strong> adresine doğrulama bağlantısını gönderdik.</p>
          <p>Spam / Gereksiz klasörünü de kontrol et.</p>
        </div>
        {error ? <div className="form-alert" role="alert"><p>{error}</p></div> : null}
        {message ? <div className="success-alert" role="status"><p>{message}</p></div> : null}
        <div className="auth-form">
          <button className="auth-submit" disabled={Boolean(busy)} onClick={checkVerification} type="button">
            {busy === 'check' ? 'Kontrol ediliyor...' : 'E-postamı doğruladım, kontrol et'}
          </button>
          <button className="button button--secondary" disabled={Boolean(busy) || cooldownRemaining > 0} onClick={resend} type="button">
            {busy === 'resend' ? 'Gönderiliyor...' : cooldownRemaining > 0 ? `Tekrar gönder (${cooldownRemaining} sn)` : 'Tekrar gönder'}
          </button>
          <button className="auth-link-button" disabled={Boolean(busy)} onClick={signOut} type="button">
            Çıkış yap
          </button>
        </div>
      </section>
    </main>
  )
}

export default EmailVerificationPage
