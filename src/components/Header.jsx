import { ChevronDown, LogIn, Menu, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { NavLink, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import { getProfile, profileMediaUrl } from '../api/client'
import Logo from './brand/Logo'
import Button from './Button'
import Container from './Container'
import UserAvatar from './user/UserAvatar'

function Header() {
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isUserMenuOpen, setIsUserMenuOpen] = useState(false)
  const [profile, setProfile] = useState(null)
  const userMenuRef = useRef(null)
  const location = useLocation()
  const navigate = useNavigate()
  const { authLoading, isAuthenticated, logout, user } = useAuth()

  useEffect(() => {
    setIsMenuOpen(false)
    setIsUserMenuOpen(false)
  }, [location.pathname])

  useEffect(() => {
    function handleClickOutside(event) {
      if (userMenuRef.current && !userMenuRef.current.contains(event.target)) {
        setIsUserMenuOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  useEffect(() => {
    if (!user) { setProfile(null); return undefined }
    let active = true
    const loadProfile = () => getProfile(user).then((response) => {
      if (active) setProfile(response.profile || null)
    }).catch(() => {})
    loadProfile()
    window.addEventListener('dersrotasi:profile-updated', loadProfile)
    return () => { active = false; window.removeEventListener('dersrotasi:profile-updated', loadProfile) }
  }, [user])

  async function handleLogout() {
    await logout()
    navigate('/', { replace: true })
  }

  function renderUserLinks() {
    return (
      <>
        <NavLink to="/profil">Profilim</NavLink>
        <NavLink to="/favorilerim">Favorilerim</NavLink>
        <NavLink to="/tercihlerim">Tercihlerim</NavLink>
        <NavLink to="/calisma-plani">Çalışma Planım</NavLink>
        <NavLink to="/premium">Premium</NavLink>
        <button type="button" onClick={handleLogout}>
          Çıkış Yap
        </button>
      </>
    )
  }

  return (
    <header className="site-header">
      <Container className="site-header__inner">
        <Logo />

        <nav className="desktop-nav" aria-label="Ana menü">
          <NavLink to="/">Ana Sayfa</NavLink>
          <NavLink to="/yks-siralama-tahmini">YKS Puan Hesaplama</NavLink>
          <NavLink to="/universite-tercih">Üniversite Tercih</NavLink>
          <NavLink to="/ai-asistan">Dersrotası AI</NavLink>
          <NavLink to="/tercihlerim">Tercihlerim</NavLink>
          <NavLink to="/calisma-plani">Çalışma Planı</NavLink>
          <NavLink to="/pomodoro">Pomodoro Odaları</NavLink>
          <NavLink to="/profil">Profilim</NavLink>
          {isAuthenticated ? <NavLink to="/premium">Premium</NavLink> : null}
        </nav>

        <div className="site-header__actions">
          {!authLoading && !isAuthenticated ? (
            <Button to="/login" icon={LogIn} variant="secondary">
              Giriş Yap / Kayıt Ol
            </Button>
          ) : null}

          {!authLoading && isAuthenticated ? (
            <div className="user-menu" ref={userMenuRef}>
              <button
                aria-expanded={isUserMenuOpen}
                aria-haspopup="menu"
                className="user-menu__trigger"
                type="button"
                onClick={() => setIsUserMenuOpen((current) => !current)}
              >
                <UserAvatar profile={profile} profilePhotoUrl={profileMediaUrl(profile?.profile_photo_path)} user={user} size={34} />
                <span>{profile?.username || user.displayName || 'Profilim'}</span>
                <ChevronDown aria-hidden="true" size={16} />
              </button>
              <div
                className={`user-menu__panel ${isUserMenuOpen ? 'user-menu__panel--open' : ''}`}
                role="menu"
              >
                {renderUserLinks()}
              </div>
            </div>
          ) : null}

          <button
            aria-controls="mobile-menu"
            aria-expanded={isMenuOpen}
            aria-label={isMenuOpen ? 'Mobil menüyü kapat' : 'Mobil menüyü aç'}
            className="menu-toggle"
            type="button"
            onClick={() => setIsMenuOpen((current) => !current)}
          >
            {isMenuOpen ? <X aria-hidden="true" /> : <Menu aria-hidden="true" />}
          </button>
        </div>
      </Container>

      <div
        className={`mobile-menu ${isMenuOpen ? 'mobile-menu--open' : ''}`}
        id="mobile-menu"
      >
        <Container className="mobile-menu__inner">
          <NavLink to="/">Ana Sayfa</NavLink>
          <NavLink to="/yks-siralama-tahmini">YKS Puan Hesaplama</NavLink>
          <NavLink to="/universite-tercih">Üniversite Tercih</NavLink>
          <NavLink to="/ai-asistan">Dersrotası AI</NavLink>
          <NavLink to="/tercihlerim">Tercihlerim</NavLink>
          <NavLink to="/calisma-plani">Çalışma Planı</NavLink>
          <NavLink to="/pomodoro">Pomodoro Odaları</NavLink>
          <NavLink to="/profil">Profilim</NavLink>
          {!authLoading && isAuthenticated ? (
            <div className="mobile-user-links">
              <div className="mobile-user-links__profile">
                <UserAvatar profile={profile} profilePhotoUrl={profileMediaUrl(profile?.profile_photo_path)} user={user} size={38} />
                <strong>{profile?.username || user?.displayName || 'Profilim'}</strong>
              </div>
              {renderUserLinks()}
            </div>
          ) : (
            <Button to="/login" icon={LogIn} variant="primary">
              Giriş Yap / Kayıt Ol
            </Button>
          )}
        </Container>
      </div>
    </header>
  )
}

export default Header
