import { useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { saveUsername } from '../api/client'
import Logo from '../components/brand/Logo'
import { useAuth } from '../context/useAuth'

const usernamePattern = /^[a-z0-9_]{3,24}$/

function Register() {
  const { authLoading, error, loginWithApple, loginWithGoogle, registerWithEmail, user } = useAuth()
  const [form, setForm] = useState({ username: '', email: '', password: '', passwordAgain: '' })
  const [busy, setBusy] = useState('')
  const [localError, setLocalError] = useState('')

  if (authLoading) return <div className="auth-loading"><p>Oturumun doğrulanıyor...</p></div>
  if (user && busy !== 'email') return <Navigate replace to="/kullanici-adi" />

  function update(name, value) { setForm((current) => ({ ...current, [name]: value })) }
  async function social(action, name) {
    setBusy(name); setLocalError('')
    try { await action() } catch (requestError) { setLocalError(requestError.message) } finally { setBusy('') }
  }
  async function handleSubmit(event) {
    event.preventDefault(); setLocalError('')
    const username = form.username.trim().toLowerCase()
    if (!usernamePattern.test(username)) { setLocalError('Kullanıcı adı 3-24 karakter olmalı; küçük harf, rakam veya alt çizgi kullan.'); return }
    if (form.password !== form.passwordAgain) { setLocalError('Şifreler birbiriyle eşleşmiyor.'); return }
    setBusy('email')
    try {
      const createdUser = await registerWithEmail(form.email, form.password, username)
      await saveUsername(createdUser, username)
    } catch (requestError) { setLocalError(requestError.message) } finally { setBusy('') }
  }

  return (
    <main className="auth-page">
      <section className="auth-card" aria-labelledby="register-title">
        <Link className="auth-brand" to="/" aria-label="Dersrotası ana sayfa"><Logo to={null} /></Link>
        <div className="auth-heading"><h1 id="register-title">Hesabını oluştur</h1><p>Ders çalışma rotanı tek yerde planla.</p></div>
        {localError || error ? <div className="form-alert" role="alert"><p>{localError || error}</p></div> : null}
        <form className="auth-form" onSubmit={handleSubmit}>
          <label><span>Kullanıcı adı</span><input autoCapitalize="none" autoComplete="username" pattern="[a-z0-9_]{3,24}" required value={form.username} onChange={(event) => update('username', event.target.value.toLowerCase())} /></label>
          <label><span>E-posta</span><input autoComplete="email" required type="email" value={form.email} onChange={(event) => update('email', event.target.value)} /></label>
          <label><span>Şifre</span><input autoComplete="new-password" minLength="6" required type="password" value={form.password} onChange={(event) => update('password', event.target.value)} /></label>
          <label><span>Şifre tekrar</span><input autoComplete="new-password" minLength="6" required type="password" value={form.passwordAgain} onChange={(event) => update('passwordAgain', event.target.value)} /></label>
          <button className="auth-submit" disabled={Boolean(busy)} type="submit">{busy === 'email' ? 'Hesap oluşturuluyor...' : 'Hesap Oluştur'}</button>
        </form>
        <div className="auth-divider"><span>veya</span></div>
        <div className="auth-socials">
          <button disabled={Boolean(busy)} onClick={() => social(loginWithGoogle, 'google')} type="button"><span className="auth-provider auth-provider--google">G</span>Google ile kayıt</button>
          <button disabled={Boolean(busy)} onClick={() => social(loginWithApple, 'apple')} type="button"><span className="auth-provider auth-provider--apple">●</span>Apple ile kayıt</button>
        </div>
        <p className="auth-switch">Zaten hesabın var mı? <Link to="/giris">Giriş yap</Link></p>
      </section>
    </main>
  )
}

export default Register
