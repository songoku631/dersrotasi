import { useState } from 'react'
import { Link, Navigate, useLocation } from 'react-router-dom'
import Logo from '../components/brand/Logo'
import { useAuth } from '../context/useAuth'

function Login() {
  const location = useLocation()
  const { authLoading, error, loginWithApple, loginWithEmail, loginWithGoogle, resetPassword, user } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState('')
  const [localError, setLocalError] = useState('')
  const [message, setMessage] = useState('')

  if (authLoading) return <div className="auth-loading"><p>Oturumun doğrulanıyor...</p></div>
  if (user) return <Navigate replace to="/kullanici-adi" />

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
          <button disabled={Boolean(busy)} onClick={() => run(loginWithApple, 'apple')} type="button"><span className="auth-provider auth-provider--apple">●</span>{busy === 'apple' ? 'Bağlanıyor...' : 'Apple ile devam et'}</button>
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
