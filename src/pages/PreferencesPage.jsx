import { ArrowDown, ArrowUp, Save, Trash2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import {
  getPreferences,
  removePreference,
  reorderPreferences,
  updatePreferenceNote,
} from '../api/preferencesApi'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import EmptyPreferences from '../components/preferences/EmptyPreferences'
import { useAuth } from '../context/useAuth'
import { enumLabel, formatRank, formatScore } from '../utils/universityFormat'

function PreferencesPage() {
  const { user } = useAuth()
  const [preferences, setPreferences] = useState([])
  const [userRank, setUserRank] = useState(null)
  const [status, setStatus] = useState('loading')
  const [message, setMessage] = useState('')
  const [busyId, setBusyId] = useState(null)

  const load = useCallback((signal) => {
    setStatus('loading'); setMessage('')
    return getPreferences(user, signal)
      .then((response) => {
        setPreferences(response.data?.items || [])
        setUserRank(response.data?.user_rank || null)
        setStatus('ready')
      })
      .catch((error) => { if (error.name !== 'AbortError') { setMessage(error.message); setStatus('error') } })
  }, [user])

  useEffect(() => {
    const controller = new AbortController()
    load(controller.signal)
    return () => controller.abort()
  }, [load])

  function changeNote(id, note) {
    setPreferences((current) => current.map((item) => item.id === id ? { ...item, note } : item))
  }

  async function saveNote(item) {
    setBusyId(item.id); setMessage('')
    try { const response = await updatePreferenceNote(user, item.id, item.note); setMessage(response.message) }
    catch (error) { setMessage(error.message) }
    finally { setBusyId(null) }
  }

  async function remove(item) {
    setBusyId(item.id); setMessage('')
    try {
      const response = await removePreference(user, item.id)
      setPreferences((current) => current.filter((entry) => entry.id !== item.id).map((entry, index) => ({ ...entry, position: index + 1 })))
      setMessage(response.message)
    } catch (error) { setMessage(error.message) }
    finally { setBusyId(null) }
  }

  async function move(index, direction) {
    const nextIndex = index + direction
    if (nextIndex < 0 || nextIndex >= preferences.length) return
    const previous = preferences
    const next = [...preferences]
    ;[next[index], next[nextIndex]] = [next[nextIndex], next[index]]
    const positioned = next.map((item, itemIndex) => ({ ...item, position: itemIndex + 1 }))
    setPreferences(positioned); setMessage('')
    try {
      await reorderPreferences(user, positioned.map((item) => ({ university_id: item.id, position: item.position })))
      setMessage('Tercih sıralaman başarıyla kaydedildi.')
    } catch (error) {
      setPreferences(previous)
      setMessage(error.message)
    }
  }

  return (
    <>
      <PageHeader title="Tercihlerim" description="Programlarını sırala, notlarını kaydet ve geçmiş yerleştirme sonuçlarıyla birlikte değerlendir." />
      <section className="section"><Container>
        <div className="information-banner">Değerlendirmeler geçmiş sonuçlara dayalı yaklaşık yardımcı bilgilerdir. Nihai tercihlerinizi ÖSYM’nin güncel kılavuzundan kontrol edin.</div>
        {message ? <div className={status === 'error' ? 'form-alert' : 'success-alert'} role="status"><p>{message}</p></div> : null}
        {status === 'loading' ? <div className="loading-panel">Tercihlerin yükleniyor...</div> : null}
        {status === 'error' ? <Button onClick={() => load()}>Yeniden Dene</Button> : null}
        {status === 'ready' && preferences.length === 0 ? <EmptyPreferences userName={user.displayName?.split(' ')[0]} /> : null}
        {preferences.length > 0 ? <div className="preference-list">{preferences.map((item, index) => (
          <article className="preference-card preference-card--detailed" key={item.id}>
            <div className="preference-card__position">{index + 1}</div>
            <div className="preference-card__body">
              <div className="program-card__heading"><div><p className="eyebrow">{item.city} · {item.year}</p><h2>{item.department_name}</h2><p>{item.university_name}</p><small>{item.faculty_name}</small></div>{item.evaluation?.label ? <span className={`evaluation-badge evaluation-badge--${item.evaluation.label}`}>{item.evaluation.label_text}</span> : null}</div>
              <dl className="program-card__details"><div><dt>Üniversite türü</dt><dd>{enumLabel(item.university_type)}</dd></div><div><dt>Puan türü</dt><dd>{enumLabel(item.score_type)}</dd></div><div><dt>Taban sıra</dt><dd>{formatRank(item.base_rank)}</dd></div><div><dt>Taban puan</dt><dd>{formatScore(item.base_score)}</dd></div><div><dt>Hedef sıran</dt><dd>{formatRank(userRank)}</dd></div></dl>
              <label className="preference-note"><span>Notun</span><textarea maxLength="1000" value={item.note || ''} onChange={(event) => changeNote(item.id, event.target.value)} /></label>
              <div className="program-card__actions">
                <Button icon={ArrowUp} variant="secondary" disabled={index === 0 || busyId === item.id} onClick={() => move(index, -1)}>Yukarı Taşı</Button>
                <Button icon={ArrowDown} variant="secondary" disabled={index === preferences.length - 1 || busyId === item.id} onClick={() => move(index, 1)}>Aşağı Taşı</Button>
                <Button icon={Save} disabled={busyId === item.id} onClick={() => saveNote(item)}>Notu Kaydet</Button>
                <Button icon={Trash2} variant="secondary" disabled={busyId === item.id} onClick={() => remove(item)}>Listeden Çıkar</Button>
                <Button to={`/universite-tercih/${item.id}`}>Detayları Gör</Button>
              </div>
            </div>
          </article>
        ))}</div> : null}
      </Container></section>
    </>
  )
}

export default PreferencesPage
