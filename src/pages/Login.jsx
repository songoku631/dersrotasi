import { ArrowLeft, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import GoogleLoginButton from '../components/auth/GoogleLoginButton'
import Logo from '../components/brand/Logo'
import Container from '../components/Container'
import { useAuth } from '../context/useAuth'

function Login() {
  const { authLoading, error, loginWithGoogle, user } = useAuth()
  const [isSigningIn, setIsSigningIn] = useState(false)
  const [localError, setLocalError] = useState('')

  if (authLoading) {
    return (
      <section className="auth-loading" aria-live="polite">
        <div>
          <span className="auth-loading__mark"></span>
          <p>Oturumun doğrulanıyor...</p>
        </div>
      </section>
    )
  }

  if (user) {
    return <Navigate to="/profil" replace />
  }

  async function handleLogin() {
    setIsSigningIn(true)
    setLocalError('')

    try {
      await loginWithGoogle()
    } catch (loginError) {
      setLocalError(loginError.message)
    } finally {
      setIsSigningIn(false)
    }
  }

  return (
    <section className="login-page">
      <Container className="login-page__grid">
        <div className="login-intro">
          <Link className="back-link" to="/">
            <ArrowLeft aria-hidden="true" size={18} />
            Ana sayfaya dön
          </Link>
          <div className="login-brand">
            <Logo to={null} />
          </div>
          <h1>Google hesabınla güvenli giriş yap.</h1>
          <p>
            Ders Rotası, kullanıcı doğrulamasını Google'ın güvenli giriş sistemi
            üzerinden yapar. Gmail şifren Ders Rotası tarafından görülmez veya
            saklanmaz.
          </p>
        </div>

        <div className="login-panel">
          <ShieldCheck aria-hidden="true" size={34} />
          <h2>Google ile Giriş Yap</h2>
          <p>
            Girişten sonra profilini düzenleyebilir, çalışma planı ve tercih
            sayfalarına erişebilirsin.
          </p>
          {localError || error ? (
            <div className="form-alert" role="alert">
              <p>{localError || error}</p>
            </div>
          ) : null}
          <GoogleLoginButton isLoading={isSigningIn} onClick={handleLogin} />
          <p className="login-panel__note">
            E-posta ve şifre bilgilerin Google tarafından doğrulanır; Ders Rotası
            yalnızca temel profil bilgilerini alır.
          </p>
        </div>
      </Container>
    </section>
  )
}

export default Login
