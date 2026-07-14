import { useEffect, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { addFavorite, removeFavorite } from '../api/favoritesApi'
import { addPreference } from '../api/preferencesApi'
import { getUniversity } from '../api/universitiesApi'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import ProgramCard from '../components/universities/ProgramCard'
import { useAuth } from '../context/useAuth'

function UniversityDetailPage() {
  const { id } = useParams()
  const location = useLocation()
  const { user, isAuthenticated } = useAuth()
  const [program, setProgram] = useState(null)
  const [status, setStatus] = useState('loading')
  const [message, setMessage] = useState('')

  useEffect(() => {
    const controller = new AbortController()
    setStatus('loading')
    getUniversity(id, user, controller.signal)
      .then((response) => { setProgram(response.data); setStatus('ready') })
      .catch((error) => { if (error.name !== 'AbortError') { setMessage(error.message); setStatus('error') } })
    return () => controller.abort()
  }, [id, user])

  async function favorite(item) {
    if (!isAuthenticated) { setMessage('Favorilere eklemek için giriş yapmalısın.'); return }
    try {
      if (Number(item.is_favorite)) await removeFavorite(user, item.id)
      else await addFavorite(user, item.id)
      setProgram((current) => ({ ...current, is_favorite: Number(current.is_favorite) ? 0 : 1 }))
    } catch (error) { setMessage(error.message) }
  }

  async function preference(item) {
    if (!isAuthenticated) { setMessage('Tercihlerine eklemek için giriş yapmalısın.'); return }
    try { const result = await addPreference(user, item.id); setMessage(result.message) }
    catch (error) { setMessage(error.message) }
  }

  return (
    <>
      <PageHeader title="Program Detayı" description="Geçmiş yerleştirme verilerini ve program bilgilerini incele." />
      <section className="section"><Container>
        <Link className="back-link" to="/universite-tercih">← Üniversite aramasına dön</Link>
        {message ? <div className="form-alert" role="alert"><p>{message}</p>{!isAuthenticated ? <Button to="/giris" state={{ from: location }}>Giriş Yap</Button> : null}</div> : null}
        {status === 'loading' ? <div className="loading-panel">Program bilgileri yükleniyor...</div> : null}
        {status === 'error' ? <Button onClick={() => window.location.reload()}>Yeniden Dene</Button> : null}
        {program ? <p className="program-code">Program kodu: <strong>{program.program_code}</strong></p> : null}
        {program ? <ProgramCard program={program} onFavorite={favorite} onPreference={preference} /> : null}
        {program?.source_url ? <p className="source-link"><a href={program.source_url} target="_blank" rel="noopener noreferrer">Kaynak sayfasını aç</a></p> : null}
        {program?.rank_source_url ? <p className="source-link"><a href={program.rank_source_url} target="_blank" rel="noopener noreferrer">Başarı sırası kaynağını aç</a></p> : null}
      </Container></section>
    </>
  )
}

export default UniversityDetailPage
