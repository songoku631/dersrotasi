import { useEffect, useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { getProfile, saveUsername } from '../api/client'
import Logo from '../components/brand/Logo'
import { useAuth } from '../context/useAuth'

function UsernameSetup() {
  const { user } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const [username, setUsername] = useState('')
  const [status, setStatus] = useState('loading')
  const [error, setError] = useState('')
  const nextPath = location.state?.from?.pathname || '/profil'
  const aiPrompt = location.state?.aiPrompt || ''

  useEffect(() => {
    const controller = new AbortController()
    getProfile(user, controller.signal).then((response) => {
      if (response.profile?.username) navigate(nextPath, { replace: true, state: aiPrompt ? { aiPrompt } : undefined })
      else setStatus('ready')
    }).catch((requestError) => { if (requestError.name !== 'AbortError') { setError(requestError.message); setStatus('ready') } })
    return () => controller.abort()
  }, [aiPrompt, navigate, nextPath, user])

  if (!user) return <Navigate replace to="/giris" />
  async function submit(event) {
    event.preventDefault(); setError(''); setStatus('saving')
    try { await saveUsername(user, username.trim().toLowerCase()); navigate(nextPath, { replace: true, state: aiPrompt ? { aiPrompt } : undefined }) }
    catch (requestError) { setError(requestError.message); setStatus('ready') }
  }
  return <main className="auth-page"><section className="auth-card auth-card--compact"><div className="auth-brand"><Logo to={null} /></div><div className="auth-heading"><h1>Kullanıcı adını seç</h1><p>Bu ad sana özel olacak. Daha sonra profilinden kullanabileceksin.</p></div>{error ? <div className="form-alert"><p>{error}</p></div> : null}<form className="auth-form" onSubmit={submit}><label><span>Kullanıcı adı</span><input autoFocus autoCapitalize="none" disabled={status !== 'ready'} pattern="[a-z0-9_]{3,24}" required value={username} onChange={(event) => setUsername(event.target.value.toLowerCase())} /></label><button className="auth-submit" disabled={status !== 'ready'} type="submit">{status === 'loading' ? 'Kontrol ediliyor...' : status === 'saving' ? 'Kaydediliyor...' : 'Devam Et'}</button></form></section></main>
}

export default UsernameSetup
