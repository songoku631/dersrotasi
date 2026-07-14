import { Heart } from 'lucide-react'
import { useState } from 'react'
import { addPreference } from '../api/preferencesApi'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import ProgramCard from '../components/universities/ProgramCard'
import { useAuth } from '../context/useAuth'
import { useFavorites } from '../hooks/useFavorites'

function FavoritesPage() {
  const { user } = useAuth()
  const { favorites, status, message, busyId, load, remove } = useFavorites(user)
  const [preferenceBusyId, setPreferenceBusyId] = useState(null)
  const [preferenceMessage, setPreferenceMessage] = useState('')
  const [preferenceStatus, setPreferenceStatus] = useState('ready')

  async function handlePreference(program) {
    setPreferenceBusyId(program.id)
    setPreferenceMessage('')
    setPreferenceStatus('ready')
    try {
      const response = await addPreference(user, program.id)
      setPreferenceMessage(response.message || 'Program tercih listene eklendi.')
    } catch (error) {
      setPreferenceMessage(error.message)
      setPreferenceStatus('error')
    } finally {
      setPreferenceBusyId(null)
    }
  }

  const visibleMessage = preferenceMessage || message

  return (
    <>
      <PageHeader
        title="Favorilerim"
        description="İlgini çeken üniversite programlarını burada tutabilir, detaylarını inceleyebilir ve tercih listene ekleyebilirsin."
      />
      <section className="section">
        <Container>
          {visibleMessage ? <div className={(preferenceMessage ? preferenceStatus : status) === 'error' ? 'form-alert' : 'success-alert'} role="status"><p>{visibleMessage}</p></div> : null}
          {status === 'loading' ? <div className="loading-panel">Favorilerin yükleniyor...</div> : null}
          {status === 'error' ? <Button onClick={() => load()}>Yeniden Dene</Button> : null}
          {status === 'ready' && favorites.length === 0 ? (
            <div className="empty-state">
              <div className="empty-state__icon"><Heart aria-hidden="true" /></div>
              <h2>Henüz favori program eklemedin.</h2>
              <p>İlgini çeken programları keşfedip buraya ekleyebilirsin.</p>
              <Button to="/universite-tercih">Üniversiteleri Keşfet</Button>
            </div>
          ) : null}
          {status === 'ready' && favorites.length > 0 ? (
            <div className="program-list">
              {favorites.map((program) => (
                <ProgramCard
                  key={program.id}
                  busy={busyId === program.id || preferenceBusyId === program.id}
                  onFavorite={remove}
                  onPreference={handlePreference}
                  program={program}
                />
              ))}
            </div>
          ) : null}
        </Container>
      </section>
    </>
  )
}

export default FavoritesPage
