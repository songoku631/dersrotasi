import { ArrowLeft, ShieldCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import GoogleLoginButton from '../components/auth/GoogleLoginButton'
import Logo from '../components/brand/Logo'
import Container from '../components/Container'
import { useAuth } from '../context/useAuth'

function Login() {
  const { error, isAuthenticated, loading, loginWithGoogle } = useAuth()
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [localError, setLocalError] = useState('')
  const location = useLocation()
  const navigate = useNavigate()
  const from = location.state?.from?.pathname || '/profil'

  useEffect(() => {
    setLocalError('')
  }, [location.key])

  if (!loading && isAuthenticated) {
    return <Navigate to="/profil" replace />
  }

  async function handleLogin() {
    setIsSubmitting(true)
    setLocalError('')

    try {
      await loginWithGoogle()
      navigate(from, { replace: true })
    } catch (loginError) {
      setLocalError(loginError.message)
    } finally {
      setIsSubmitting(false)
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
          <GoogleLoginButton isLoading={isSubmitting || loading} onClick={handleLogin} />
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
