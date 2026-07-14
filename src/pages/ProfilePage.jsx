import { useEffect, useState } from 'react'
import { getCurrentUser } from '../api/client'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import ProfileForm from '../components/profile/ProfileForm'
import UserAvatar from '../components/user/UserAvatar'
import { useAuth } from '../context/useAuth'

function ProfilePage() {
  const { user } = useAuth()
  const [sessionStatus, setSessionStatus] = useState('loading')
  const [sessionError, setSessionError] = useState('')
  const [retryCount, setRetryCount] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    setSessionStatus('loading')
    setSessionError('')

    getCurrentUser(user, controller.signal)
      .then(() => setSessionStatus('ready'))
      .catch((error) => {
        if (error.name !== 'AbortError') {
          setSessionError(error.message)
          setSessionStatus('error')
        }
      })

    return () => controller.abort()
  }, [retryCount, user])

  if (sessionStatus === 'loading') {
    return (
      <section className="auth-loading" aria-live="polite">
        <div>
          <span className="auth-loading__mark"></span>
          <p>Oturumun doğrulanıyor...</p>
        </div>
      </section>
    )
  }

  if (sessionStatus === 'error') {
    return (
      <section className="section">
        <Container>
          <div className="profile-session-error">
            <div className="form-alert" role="alert">
              <p>{sessionError}</p>
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
