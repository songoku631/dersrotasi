import { ArrowDown, ArrowUp, Crown, GitCompareArrows, Save, Sparkles, Trash2, X } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { analyzePreferenceList, comparePremiumPrograms } from '../api/premiumApi'
import { getPreferences, removePreference, reorderPreferences, updatePreferenceNote } from '../api/preferencesApi'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import EmptyPreferences from '../components/preferences/EmptyPreferences'
import PreferenceAnalysisPanel from '../components/premium/PreferenceAnalysisPanel'
import PremiumGateModal from '../components/premium/PremiumGateModal'
import ProgramComparisonModal from '../components/premium/ProgramComparisonModal'
import { useAuth } from '../context/useAuth'
import { useUserPlan } from '../hooks/useUserPlan'
import { enumLabel, formatRank, formatScore } from '../utils/universityFormat'

function PreferencesPage() {
  const { user } = useAuth()
  const { loading: planLoading, plan, refresh: refreshPlan } = useUserPlan(user)
  const [preferences, setPreferences] = useState([])
  const [userRank, setUserRank] = useState(null)
  const [status, setStatus] = useState('loading')
  const [message, setMessage] = useState('')
  const [busyId, setBusyId] = useState(null)
  const [gateOpen, setGateOpen] = useState(false)
  const [analysis, setAnalysis] = useState(null)
  const [analysisBusy, setAnalysisBusy] = useState(false)
  const [rankPromptOpen, setRankPromptOpen] = useState(false)
  const [rankInput, setRankInput] = useState('')
  const [comparisonIds, setComparisonIds] = useState([])
  const [comparison, setComparison] = useState(null)
  const [comparisonBusy, setComparisonBusy] = useState(false)
  const [comparisonError, setComparisonError] = useState('')
  const hasPremiumAccess = Boolean(plan?.has_premium_access || plan?.is_premium || plan?.is_admin)

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
      setComparisonIds((current) => current.filter((id) => id !== item.id))
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
    } catch (error) { setPreferences(previous); setMessage(error.message) }
  }

  async function runAnalysis(rank) {
    setAnalysisBusy(true); setMessage(''); setRankPromptOpen(false)
    try {
      const response = await analyzePreferenceList(user, rank, crypto.randomUUID())
      setAnalysis(response.data)
      if (!userRank) setUserRank(Number(rank))
      refreshPlan()
    } catch (error) { setMessage(error.message) }
    finally { setAnalysisBusy(false) }
  }

  function startAnalysis() {
    if (planLoading) return
    if (!hasPremiumAccess) { setGateOpen(true); return }
    if (!userRank) { setRankInput(''); setRankPromptOpen(true); return }
    runAnalysis(userRank)
  }

  async function runComparison(ids) {
    setComparison(null); setComparisonError(''); setComparisonBusy(true)
    try {
      const response = await comparePremiumPrograms(user, ids, userRank, crypto.randomUUID())
      setComparison(response.data); refreshPlan()
    } catch (error) { setComparisonError(error.message) }
    finally { setComparisonBusy(false) }
  }

  function toggleComparison(id) {
    if (planLoading) return
    if (!hasPremiumAccess) { setGateOpen(true); return }
    if (comparisonIds.includes(id)) { setComparisonIds((current) => current.filter((item) => item !== id)); return }
    if (comparisonIds.length === 0) { setComparisonIds([id]); setMessage('Karşılaştırmak için bir program daha seç.'); return }
    const ids = [comparisonIds[0], id]
    setComparisonIds(ids)
    runComparison(ids)
  }

  return (
    <>
      <PageHeader title="Tercihlerim" description="Programlarını sırala, notlarını kaydet ve geçmiş yerleştirme sonuçlarıyla birlikte değerlendir." />
      <section className="section"><Container>
        <div className="information-banner">Değerlendirmeler geçmiş sonuçlara dayalı yaklaşık yardımcı bilgilerdir. Nihai tercihlerinizi ÖSYM’nin güncel kılavuzundan kontrol edin.</div>
        <div className="preferences-premium-toolbar">
          <div><p className="eyebrow"><Crown aria-hidden="true" /> Premium</p><h2>Tercihlerini veriye dayalı değerlendir</h2><p>2023–2026 başarı sıraları ve kontenjan eğilimleriyle listenin dengesini gör.</p></div>
          <Button icon={Sparkles} onClick={startAnalysis} disabled={analysisBusy || planLoading}>{analysisBusy ? 'Analiz Ediliyor...' : 'Tercih Listemi Analiz Et'}</Button>
        </div>
        {message ? <div className={status === 'error' ? 'form-alert' : 'success-alert'} role="status"><p>{message}</p></div> : null}
        <PreferenceAnalysisPanel analysis={analysis} />
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
                <Button icon={GitCompareArrows} variant={comparisonIds.includes(item.id) ? 'primary' : 'secondary'} disabled={comparisonBusy} onClick={() => toggleComparison(item.id)}>{comparisonIds.includes(item.id) ? 'Seçildi' : 'Karşılaştır'}</Button>
                <Button to={`/universite-tercih/${item.id}`}>Detayları Gör</Button>
              </div>
            </div>
          </article>
        ))}</div> : null}
      </Container></section>
      <PremiumGateModal open={gateOpen} onClose={() => setGateOpen(false)} />
      {rankPromptOpen ? <div className="premium-modal-backdrop" role="presentation" onMouseDown={() => setRankPromptOpen(false)}><form className="premium-modal premium-rank-prompt" role="dialog" aria-modal="true" onSubmit={(event) => { event.preventDefault(); runAnalysis(rankInput) }} onMouseDown={(event) => event.stopPropagation()}><button className="premium-modal__close" type="button" aria-label="Kapat" onClick={() => setRankPromptOpen(false)}><X /></button><p className="eyebrow">Analiz için</p><h2>Başarı sıranı gir</h2><p>Profilinde kayıtlı bir sıra olmadığı için bu analizde kullanılacak sayısal başarı sırasını yaz.</p><label><span>Başarı sırası</span><input type="number" min="1" max="10000000" required value={rankInput} onChange={(event) => setRankInput(event.target.value)} placeholder="Örn. 100000" /></label><Button type="submit" icon={Sparkles}>Analizi Başlat</Button></form></div> : null}
      <ProgramComparisonModal comparison={comparison} loading={comparisonBusy} error={comparisonError} onClose={() => { setComparison(null); setComparisonError(''); setComparisonIds([]) }} />
    </>
  )
}

export default PreferencesPage
