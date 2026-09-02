import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation } from 'react-router-dom'
import { getProfile } from '../api/client'
import Logo from '../components/brand/Logo'
import { useAuth } from '../context/useAuth'
import { postLoginPath } from '../utils/authRouting'

function Login() {
  const location = useLocation()
  const { authLoading, error, loginWithEmail, loginWithGoogle, resetPassword, user } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState('')
  const [localError, setLocalError] = useState('')
  const [message, setMessage] = useState('')
  const [profileCheck, setProfileCheck] = useState({ destination: '', error: '', loading: false })
  const [profileCheckAttempt, setProfileCheckAttempt] = useState(0)

  useEffect(() => {
    if (!user) {
      setProfileCheck({ destination: '', error: '', loading: false })
      return undefined
    }

    const controller = new AbortController()
    setProfileCheck({ destination: '', error: '', loading: true })
    getProfile(user, controller.signal)
      .then((response) => setProfileCheck({
        destination: postLoginPath(response.profile, location.state?.from?.pathname),
        error: '',
        loading: false,
      }))
      .catch((requestError) => {
        if (requestError.name !== 'AbortError') {
          setProfileCheck({ destination: '', error: requestError.message, loading: false })
        }
      })

    return () => controller.abort()
  }, [location.state, profileCheckAttempt, user])

  if (authLoading) return <div className="auth-loading"><p>Oturumun doğrulanıyor...</p></div>
  if (user && profileCheck.destination) return <Navigate replace state={location.state?.aiPrompt ? { aiPrompt: location.state.aiPrompt } : undefined} to={profileCheck.destination} />
  if (user && (profileCheck.loading || !profileCheck.error)) return <div className="auth-loading"><p>Profilin kontrol ediliyor...</p></div>
  if (user && profileCheck.error) return <main className="auth-page"><section className="auth-card auth-card--compact"><div className="form-alert" role="alert"><p>{profileCheck.error}</p></div><button className="auth-submit" onClick={() => setProfileCheckAttempt((attempt) => attempt + 1)} type="button">Tekrar Dene</button></section></main>

  async function run(action, name) {
    setBusy(name); setLocalError(''); setMessage('')
    try { await action() } catch (requestError) { setLocalError(requestError.message) } finally { setBusy('') }
  }

  function handleSubmit(event) {
    event.preventDefault()
    run(() => loginWithEmail(email, password), 'email')
  }

  async function handleReset() {
    if (!email.trim()) { setLocalError('Şifre sıfırlama bağlantısı için önce e-posta adresini gir.'); return }
    await run(async () => {
      await resetPassword(email)
      setMessage('Şifre sıfırlama bağlantısı e-posta adresine gönderildi.')
    }, 'reset')
  }

  return (
    <main className="auth-page">
      <section className="auth-card" aria-labelledby="login-title">
        <Link className="auth-brand" to="/" aria-label="Dersrotası ana sayfa"><Logo to={null} /></Link>
        <div className="auth-heading"><h1 id="login-title">Dersrotası’na giriş yap</h1><p>Hedeflerine kaldığın yerden devam et.</p></div>
        {location.state?.message ? <div className="success-alert"><p>{location.state.message}</p></div> : null}
        {localError || error ? <div className="form-alert" role="alert"><p>{localError || error}</p></div> : null}
        {message ? <div className="success-alert" role="status"><p>{message}</p></div> : null}
        <div className="auth-socials">
          <button disabled={Boolean(busy)} onClick={() => run(loginWithGoogle, 'google')} type="button"><span className="auth-provider auth-provider--google">G</span>{busy === 'google' ? 'Bağlanıyor...' : 'Google ile devam et'}</button>
        </div>
        <div className="auth-divider"><span>veya</span></div>
        <form className="auth-form" onSubmit={handleSubmit}>
          <label><span>E-posta</span><input autoComplete="email" required type="email" value={email} onChange={(event) => setEmail(event.target.value)} /></label>
          <label><span>Şifre</span><input autoComplete="current-password" minLength="6" required type="password" value={password} onChange={(event) => setPassword(event.target.value)} /></label>
          <button className="auth-submit" disabled={Boolean(busy)} type="submit">{busy === 'email' ? 'Giriş yapılıyor...' : 'Giriş Yap'}</button>
        </form>
        <button className="auth-link-button" disabled={Boolean(busy)} onClick={handleReset} type="button">Şifremi unuttum</button>
        <p className="auth-switch">Hesabın yok mu? <Link to="/kayit">Kayıt ol</Link></p>
      </section>
    </main>
  )
}

export default Login
