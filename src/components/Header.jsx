import { ChevronDown, LogIn, Menu, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { NavLink, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import { toolMenuItems } from '../data/tools'
import Logo from './brand/Logo'
import Button from './Button'
import Container from './Container'
import UserAvatar from './user/UserAvatar'

function Header() {
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isUserMenuOpen, setIsUserMenuOpen] = useState(false)
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
          <div className="nav-dropdown">
            <button className="nav-dropdown__trigger" type="button">
              Araçlar
              <ChevronDown aria-hidden="true" size={16} />
            </button>
            <div className="nav-dropdown__panel">
              {toolMenuItems.map((tool) => (
                <NavLink key={tool.path} to={tool.path}>
                  {tool.title}
                </NavLink>
              ))}
            </div>
          </div>
          <NavLink to="/universite-tercih">Üniversite Tercih</NavLink>
          <NavLink to="/tercihlerim">Tercihlerim</NavLink>
          <NavLink to="/calisma-plani">Çalışma Planı</NavLink>
          <NavLink to="/profil">Profilim</NavLink>
        </nav>

        <div className="site-header__actions">
          {!authLoading && !isAuthenticated ? (
            <Button to="/giris" icon={LogIn} variant="secondary">
              Google ile Giriş
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
                <UserAvatar user={user} size={34} />
                <span>{user.displayName || 'Profilim'}</span>
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
          <strong className="mobile-menu__section-title">Araçlar</strong>
          {toolMenuItems.map((tool) => <NavLink key={tool.path} to={tool.path}>{tool.title}</NavLink>)}
          <NavLink to="/universite-tercih">Üniversite Tercih</NavLink>
          <NavLink to="/tercihlerim">Tercihlerim</NavLink>
          <NavLink to="/calisma-plani">Çalışma Planı</NavLink>
          <NavLink to="/profil">Profilim</NavLink>
          {!authLoading && isAuthenticated ? (
            <div className="mobile-user-links">
              <div className="mobile-user-links__profile">
                <UserAvatar user={user} size={38} />
                {user?.displayName ? <strong>{user.displayName}</strong> : null}
              </div>
              {renderUserLinks()}
            </div>
          ) : (
            <Button to="/giris" icon={LogIn} variant="primary">
              Google ile Giriş
            </Button>
          )}
        </Container>
      </div>
    </header>
  )
}

export default Header
