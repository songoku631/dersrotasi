import { useEffect, useState } from 'react'
import { getCurrentUser } from '../api/client'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import ProfileForm from '../components/profile/ProfileForm'
import UserAvatar from '../components/user/UserAvatar'
import { useAuth } from '../context/useAuth'

function ProfilePage() {
  const { authLoading, user } = useAuth()
  const [sessionLoading, setSessionLoading] = useState(true)
  const [sessionVerified, setSessionVerified] = useState(false)
  const [sessionError, setSessionError] = useState('')
  const [retryCount, setRetryCount] = useState(0)

  useEffect(() => {
    if (authLoading || !user) {
      return undefined
    }

    const controller = new AbortController()
    let active = true

    setSessionLoading(true)
    setSessionVerified(false)
    setSessionError('')

    async function verifySession() {
      try {
        await user.getIdToken()
        await getCurrentUser(user, controller.signal)

        if (active) {
          setSessionVerified(true)
        }
      } catch (error) {
        if (active && error.name !== 'AbortError') {
          setSessionError(error.message)
        }
      } finally {
        if (active) {
          setSessionLoading(false)
        }
      }
    }

    verifySession()

    return () => {
      active = false
      controller.abort()
    }
  }, [authLoading, retryCount, user])

  if (authLoading || sessionLoading) {
    return (
      <section className="auth-loading" aria-live="polite">
        <div>
          <span className="auth-loading__mark"></span>
          <p>Oturumun doğrulanıyor...</p>
        </div>
      </section>
    )
  }

  if (sessionError || !sessionVerified) {
    return (
      <section className="section">
        <Container>
          <div className="profile-session-error">
            <div className="form-alert" role="alert">
              <p>{sessionError || 'Oturum doğrulanamadı. Lütfen tekrar dene.'}</p>
            </div>
            <Button onClick={() => setRetryCount((count) => count + 1)}>
              Tekrar Dene
            </Button>
          </div>
        </Container>
      </section>
    )
  }

  return (
    <>
      <PageHeader
        title="Profilim"
        description="Google hesabından gelen bilgilerini gör ve YKS hedeflerini Ders Rotası içinde kişiselleştir."
      />
      <section className="section">
        <Container>
          <div className="profile-layout">
            <aside className="profile-summary" aria-label="Google profil bilgileri">
              <UserAvatar className="profile-avatar" user={user} size={96} />
              <h2>{user.displayName || 'Ders Rotası kullanıcısı'}</h2>
              <p>{user.email}</p>
              <span>Google hesabı ile doğrulandı</span>
            </aside>
            <div className="profile-panel">
              <div className="section-heading">
                <p className="eyebrow">Kişisel hedefler</p>
                <h2>YKS rotanı kaydet</h2>
                <p>
                  Profil bilgilerin hesabına güvenli şekilde kaydedilir ve giriş
                  yaptığın cihazlarda yeniden yüklenir.
                </p>
              </div>
              <ProfileForm user={user} />
            </div>
          </div>
        </Container>
      </section>
    </>
  )
}

export default ProfilePage
