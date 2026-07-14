import { Filter, Search } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { addFavorite, removeFavorite } from '../api/favoritesApi'
import { getPreferenceSuggestions } from '../api/preferenceSuggestionsApi'
import { addPreference } from '../api/preferencesApi'
import { getUniversities, getUniversityFilters } from '../api/universitiesApi'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import ProgramCard from '../components/universities/ProgramCard'
import { useAuth } from '../context/useAuth'
import { useFavorites } from '../hooks/useFavorites'
import { enumLabel } from '../utils/universityFormat'

const tabs = [
  { value: 'all', label: 'Tüm Programlar' },
  { value: 'favorites', label: 'Favorilerim' },
  { value: 'suggestions', label: 'Sıralamama Göre Öneriler' },
]

const enumOptions = {
  score_type: ['say', 'ea', 'soz', 'dil', 'tyt'],
  university_type: ['devlet', 'vakif', 'kktc', 'yabanci'],
  education_type: ['orgun', 'ikinci_ogretim', 'uzaktan', 'acikogretim', 'diger'],
  scholarship_type: ['ucretsiz', 'burslu', 'yuzde_50', 'yuzde_25', 'ucretli', 'diger'],
}

const emptyFilters = {
  search: '', university: '', department: '', city: '', score_type: '',
  university_type: '', education_type: '', education_language: '',
  scholarship_type: '', year: '', min_rank: '', max_rank: '', sort: 'rank_asc', page: '1',
}

const incomingScoreTypeMap = {
  SAY: 'say', EA: 'ea', 'SÖZ': 'soz', SOZ: 'soz', 'DİL': 'dil', DIL: 'dil', TYT: 'tyt',
}

function UniversityPreferencePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const location = useLocation()
  const { user, isAuthenticated } = useAuth()
  const activeTab = searchParams.get('tab') || 'all'
  const favoriteState = useFavorites(user, activeTab === 'favorites' && isAuthenticated)
  const [searchDraft, setSearchDraft] = useState(searchParams.get('search') || '')
  const [items, setItems] = useState([])
  const [filterOptions, setFilterOptions] = useState({})
  const [pagination, setPagination] = useState({ page: 1, total_pages: 0, total: 0 })
  const [suggestions, setSuggestions] = useState(null)
  const [status, setStatus] = useState('loading')
  const [message, setMessage] = useState('')
  const [authPrompt, setAuthPrompt] = useState('')
  const [busyId, setBusyId] = useState(null)

  const updateParams = useCallback((changes) => {
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(changes).forEach(([key, value]) => {
        if (value === '' || value === null) next.delete(key)
        else next.set(key, String(value))
      })
      return next
    })
  }, [setSearchParams])

  useEffect(() => {
    const timer = setTimeout(() => {
      if (searchDraft !== (searchParams.get('search') || '')) {
        updateParams({ search: searchDraft, page: 1 })
      }
    }, 450)
    return () => clearTimeout(timer)
  }, [searchDraft, searchParams, updateParams])

  useEffect(() => {
    const controller = new AbortController()
    getUniversityFilters(controller.signal)
      .then((response) => setFilterOptions(response.data || {}))
      .catch(() => setFilterOptions({}))
    return () => controller.abort()
  }, [])

  const load = useCallback((signal) => {
    if (activeTab === 'favorites') return Promise.resolve()

    setStatus('loading')
    setMessage('')
    if (activeTab !== 'all' && !isAuthenticated) {
      setItems([])
      setSuggestions(null)
      setStatus('ready')
      return Promise.resolve()
    }

    const params = Object.fromEntries(searchParams.entries())
    delete params.tab
    params.score_type = incomingScoreTypeMap[params.score_type] || params.score_type
    delete params.estimated_rank
    const request = activeTab === 'suggestions'
      ? getPreferenceSuggestions(user, params, signal)
      : getUniversities(params, user, signal)

    return request.then((response) => {
      if (activeTab === 'suggestions') {
        setSuggestions(response.data)
        setItems([])
      } else {
        setItems(response.data?.items || [])
        setSuggestions(null)
        setPagination(response.data?.pagination || {
          page: 1,
          total_pages: 1,
          total: response.data?.items?.length || 0,
        })
      }
      setStatus('ready')
    }).catch((error) => {
      if (error.name !== 'AbortError') {
        setMessage(error.message)
        setStatus('error')
      }
    })
  }, [activeTab, isAuthenticated, searchParams, user])

  useEffect(() => {
    const controller = new AbortController()
    load(controller.signal)
    return () => controller.abort()
  }, [load])

  function requireLogin(text) {
    if (isAuthenticated) return false
    setAuthPrompt(text)
    return true
  }

  function updateSuggestionFavorite(programId, isFavorite) {
    setSuggestions((current) => current ? {
      ...current,
      groups: Object.fromEntries(Object.entries(current.groups).map(([key, programs]) => [
        key,
        programs.map((item) => item.id === programId ? { ...item, is_favorite: isFavorite } : item),
      ])),
    } : current)
  }

  async function handleFavorite(program) {
    if (requireLogin('Favorilerine program eklemek için giriş yapmalısın.')) return
    if (activeTab === 'favorites' && Number(program.is_favorite)) {
      await favoriteState.remove(program)
      return
    }

    setBusyId(program.id)
    setMessage('')
    try {
      if (Number(program.is_favorite)) {
        const response = await removeFavorite(user, program.id)
        setItems((current) => current.map((item) => item.id === program.id ? { ...item, is_favorite: 0 } : item))
        updateSuggestionFavorite(program.id, 0)
        setMessage(response.message)
      } else {
        const response = await addFavorite(user, program.id)
        setItems((current) => current.map((item) => item.id === program.id ? { ...item, is_favorite: 1 } : item))
        updateSuggestionFavorite(program.id, 1)
        setMessage(response.message)
      }
    } catch (error) {
      setMessage(error.message)
    } finally {
      setBusyId(null)
    }
  }

  async function handlePreference(program) {
    if (requireLogin('Tercih listene program eklemek için giriş yapmalısın.')) return
    setBusyId(program.id)
    setMessage('')
    try {
      const response = await addPreference(user, program.id)
      setMessage(response.message)
    } catch (error) {
      setMessage(error.message)
    } finally {
      setBusyId(null)
    }
  }

  function renderPrograms(programs) {
    if (programs.length === 0) {
      if (activeTab === 'favorites') {
        return (
          <div className="empty-state">
            <h2>Henüz favori program eklemedin.</h2>
            <p>İlgini çeken programları keşfedip buraya ekleyebilirsin.</p>
            <Button to="/universite-tercih">Üniversiteleri Keşfet</Button>
          </div>
        )
      }
      return <div className="empty-state university-empty"><Search size={30} /><h2>Üniversite verileri henüz sisteme yüklenmedi.</h2><p>Filtrelerini değiştirerek tekrar deneyebilirsin.</p></div>
    }

    return <div className="program-list">{programs.map((program) => <ProgramCard key={program.id} program={program} busy={busyId === program.id || favoriteState.busyId === program.id} onFavorite={handleFavorite} onPreference={handlePreference} />)}</div>
  }

  const fields = { ...emptyFilters, ...Object.fromEntries(searchParams.entries()) }
  fields.score_type = incomingScoreTypeMap[fields.score_type] || fields.score_type
  const estimatedRank = Number(searchParams.get('estimated_rank') || 0)
  const activeMessage = activeTab === 'favorites' ? message || favoriteState.message : message
  const activeStatus = activeTab === 'favorites' && !message ? favoriteState.status : status

  return (
    <>
      <PageHeader title="Üniversite ve Bölüm Ara" description="Veriler geçmiş YKS yerleştirme sonuçlarına dayanmaktadır. Başarı sıraları gelecek dönem için kesin sonuç anlamına gelmez." />
      <section className="section"><Container>
        <div className="information-banner">Nihai tercihlerinizi ÖSYM’nin güncel kılavuzundan kontrol edin.</div>
        <div className="preference-tabs" role="tablist" aria-label="Üniversite tercih bölümleri">
          {tabs.map((tab) => <button key={tab.value} className={activeTab === tab.value ? 'active' : ''} type="button" onClick={() => updateParams({ tab: tab.value === 'all' ? '' : tab.value, page: 1 })}>{tab.label}</button>)}
        </div>

        {authPrompt ? <div className="form-alert" role="alert"><p>{authPrompt}</p><Link className="button button--primary" to="/giris" state={{ from: location }}>Giriş Yap</Link></div> : null}
        {activeMessage ? <div className={activeStatus === 'error' ? 'form-alert' : 'success-alert'} role="status"><p>{activeMessage}</p></div> : null}

        {activeTab === 'all' && estimatedRank > 0 ? <div className="information-banner">Tahmini sıralamana göre seçilen aralık gösteriliyor. Bu aralık geçmiş yerleştirme sonuçlarına dayalı yaklaşık bir yardımcı bilgidir.</div> : null}
        {activeTab === 'all' ? <div className="university-layout">
          <form className="filter-panel" aria-label="Üniversite tercih filtreleri" onSubmit={(event) => event.preventDefault()}>
            <div className="filter-panel__header"><Filter size={22} /><h2>Filtreler</h2></div>
            <label><span>Genel arama</span><input value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Üniversite, bölüm veya şehir" /></label>
            {[['university', 'Üniversite adı'], ['department', 'Bölüm adı']].map(([name, label]) => <label key={name}><span>{label}</span><input value={fields[name]} onChange={(event) => updateParams({ [name]: event.target.value, page: 1 })} /></label>)}
            <label><span>Şehir</span><select value={fields.city} onChange={(event) => updateParams({ city: event.target.value, page: 1 })}><option value="">Tümü</option>{(filterOptions.cities || []).map((value) => <option key={value}>{value}</option>)}</select></label>
            {Object.entries(enumOptions).map(([name, options]) => <label key={name}><span>{({ score_type: 'Puan türü', university_type: 'Üniversite türü', education_type: 'Öğretim türü', scholarship_type: 'Burs türü' })[name]}</span><select value={fields[name]} onChange={(event) => updateParams({ [name]: event.target.value, page: 1 })}><option value="">Tümü</option>{options.map((value) => <option key={value} value={value}>{enumLabel(value)}</option>)}</select></label>)}
            <label><span>Öğretim dili</span><select value={fields.education_language} onChange={(event) => updateParams({ education_language: event.target.value, page: 1 })}><option value="">Tümü</option>{(filterOptions.education_languages || []).map((value) => <option key={value}>{value}</option>)}</select></label>
            <label><span>Veri yılı</span><select value={fields.year} onChange={(event) => updateParams({ year: event.target.value, page: 1 })}><option value="">Tümü</option>{(filterOptions.years || []).map((value) => <option key={value}>{value}</option>)}</select></label>
            <label><span>Minimum başarı sırası</span><input type="number" min="1" value={fields.min_rank} onChange={(event) => updateParams({ min_rank: event.target.value, page: 1 })} /></label>
            <label><span>Maksimum başarı sırası</span><input type="number" min="1" value={fields.max_rank} onChange={(event) => updateParams({ max_rank: event.target.value, page: 1 })} /></label>
            <label><span>Sıralama</span><select value={fields.sort} onChange={(event) => updateParams({ sort: event.target.value, page: 1 })}><option value="rank_asc">Düşükten yükseğe</option><option value="rank_desc">Yüksekten düşüğe</option><option value="score_desc">Taban puanı: yüksekten düşüğe</option><option value="score_asc">Taban puanı: düşükten yükseğe</option><option value="university_asc">Üniversite: A-Z</option><option value="university_desc">Üniversite: Z-A</option><option value="department_asc">Bölüm: A-Z</option><option value="department_desc">Bölüm: Z-A</option></select></label>
            <Button variant="secondary" onClick={() => { setSearchDraft(''); setSearchParams({}) }}>Filtreleri Temizle</Button>
          </form>
          <div>
            {status === 'loading' ? <div className="loading-panel">Programlar yükleniyor...</div> : null}
            {status === 'error' ? <Button onClick={() => load()}>Yeniden Dene</Button> : null}
            {status === 'ready' ? renderPrograms(items) : null}
            {pagination.total_pages > 1 ? <nav className="pagination" aria-label="Sonuç sayfaları"><Button variant="secondary" disabled={pagination.page <= 1} onClick={() => updateParams({ page: pagination.page - 1 })}>Önceki</Button><span>{pagination.page} / {pagination.total_pages}</span><Button variant="secondary" disabled={pagination.page >= pagination.total_pages} onClick={() => updateParams({ page: pagination.page + 1 })}>Sonraki</Button></nav> : null}
          </div>
        </div> : null}

        {activeTab === 'favorites' ? (!isAuthenticated ? <div className="empty-state"><h2>Favorilerini görmek için giriş yap.</h2><Button to="/giris">Giriş Yap</Button></div> : favoriteState.status === 'loading' ? <div className="loading-panel">Favorilerin yükleniyor...</div> : favoriteState.status === 'error' ? <Button onClick={() => favoriteState.load()}>Yeniden Dene</Button> : renderPrograms(favoriteState.favorites)) : null}
        {activeTab === 'suggestions' ? (!isAuthenticated ? <div className="empty-state"><h2>Önerileri kişiselleştirmek için giriş yap.</h2><Button to="/giris">Giriş Yap</Button></div> : status === 'loading' ? <div className="loading-panel">Önerilerin hazırlanıyor...</div> : suggestions ? <div className="suggestion-groups"><div className="information-banner">{suggestions.disclaimer}</div>{[['zor', 'Zor Tercihler'], ['hedef', 'Hedef Tercihler'], ['daha_guvenli', 'Daha Güvenli Tercihler']].map(([key, title]) => <section key={key}><h2>{title}</h2>{renderPrograms(suggestions.groups[key] || [])}</section>)}</div> : <div className="empty-state"><h2>Profilinden hedef sıralamanı ekle.</h2><Button to="/profil">Profilim</Button></div>) : null}
      </Container></section>
    </>
  )
}

export default UniversityPreferencePage
