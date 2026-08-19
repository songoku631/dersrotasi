import { Filter, Search } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { addFavorite, removeFavorite } from '../api/favoritesApi'
import { getPreferenceSuggestions } from '../api/preferenceSuggestionsApi'
import { addPreference } from '../api/preferencesApi'
import {
  getDepartmentNameOptions,
  getUniversities,
  getUniversityFilters,
  getUniversityNameOptions,
} from '../api/universitiesApi'
import Button from '../components/Button'
import CheckboxFilter from '../components/CheckboxFilter'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import SearchableCombobox from '../components/SearchableCombobox'
import UniversityResults from '../components/universities/UniversityResults'
import { useAuth } from '../context/useAuth'
import { useFavorites } from '../hooks/useFavorites'
import { enumLabel } from '../utils/universityFormat'
import {
  changeUniversitySearchParams,
  defaultUniversitySort,
  filterOptionValues,
  multiFilterValues,
  normalizeScoreType,
  normalizeUniversitySort,
  universityApiParams,
  universityRankFilterLabels,
  universitySortOptions,
} from '../utils/universityFilters'

const tabs = [
  { value: 'all', label: 'Tüm Programlar' },
  { value: 'favorites', label: 'Favorilerim' },
  { value: 'suggestions', label: 'Sıralamama Göre Öneriler' },
]

const emptyFields = {
  min_rank: '', max_rank: '', sort: defaultUniversitySort, page: '1',
}

const checkboxFilters = [
  { name: 'city', label: 'Şehir', optionKey: 'cities', searchable: true },
  { name: 'score_type', label: 'Puan türü', optionKey: 'score_types', enumValues: true },
  { name: 'university_type', label: 'Üniversite türü', optionKey: 'university_types', enumValues: true },
  { name: 'education_type', label: 'Öğretim türü', optionKey: 'education_types', enumValues: true },
  { name: 'scholarship_type', label: 'Burs türü', optionKey: 'scholarship_types', enumValues: true },
  { name: 'education_language', label: 'Öğretim dili', optionKey: 'education_languages', searchable: true },
]

function paginationPages(current, total) {
  return [...new Set([1, current - 1, current, current + 1, total])]
    .filter((page) => page >= 1 && page <= total)
    .sort((left, right) => left - right)
}

function UniversityPreferencePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const location = useLocation()
  const { user, isAuthenticated } = useAuth()
  const activeTab = searchParams.get('tab') || 'all'
  const favoriteState = useFavorites(user, activeTab === 'favorites' && isAuthenticated)
  const requestSequence = useRef(0)
  const selectionValidationSequence = useRef(0)
  const [items, setItems] = useState([])
  const [filterOptions, setFilterOptions] = useState({})
  const [pagination, setPagination] = useState({ page: 1, total_pages: 0, total: 0 })
  const [suggestions, setSuggestions] = useState(null)
  const [status, setStatus] = useState('loading')
  const [message, setMessage] = useState('')
  const [filterMessage, setFilterMessage] = useState('')
  const [authPrompt, setAuthPrompt] = useState('')
  const [busyId, setBusyId] = useState(null)
  const fields = { ...emptyFields, ...Object.fromEntries(searchParams.entries()) }
  fields.sort = normalizeUniversitySort(fields.sort)
  const rankFilterLabels = universityRankFilterLabels()
  const serializedSearchParams = searchParams.toString()
  const selectedUniversities = useMemo(
    () => multiFilterValues(new URLSearchParams(serializedSearchParams), 'university'),
    [serializedSearchParams],
  )
  const selectedDepartments = useMemo(
    () => multiFilterValues(new URLSearchParams(serializedSearchParams), 'department'),
    [serializedSearchParams],
  )
  const selectedCheckboxFilters = useMemo(() => {
    const params = new URLSearchParams(serializedSearchParams)
    return Object.fromEntries(checkboxFilters.map(({ name }) => {
      const values = multiFilterValues(params, name)
      return [name, name === 'score_type' ? values.map(normalizeScoreType) : values]
    }))
  }, [serializedSearchParams])
  const hasActiveProgramFilters = useMemo(() => (
    Object.keys(universityApiParams(new URLSearchParams(serializedSearchParams)))
      .some((name) => !['page', 'sort'].includes(name))
  ), [serializedSearchParams])

  const updateParams = useCallback((changes) => {
    setSearchParams((current) => changeUniversitySearchParams(current, changes))
  }, [setSearchParams])

  const loadUniversityNames = useCallback(async (query, signal) => {
    const response = await getUniversityNameOptions(query, signal)
    return response.data?.items || []
  }, [])

  const loadDepartmentNames = useCallback(async (query, signal) => {
    const response = await getDepartmentNameOptions(query, selectedUniversities, signal)
    return response.data?.items || []
  }, [selectedUniversities])

  const selectUniversities = useCallback(async (universities) => {
    const sequence = ++selectionValidationSequence.current
    setFilterMessage('')
    updateParams({ university: universities, page: 1 })
    if (universities.length === 0) {
      return
    }

    try {
      const universityChecks = await Promise.all(universities.map(async (university) => {
        const response = await getUniversityNameOptions('', undefined, university)
        return (response.data?.items || []).includes(university) ? university : null
      }))
      const validUniversities = universityChecks.filter(Boolean)
      const departmentChecks = await Promise.all(selectedDepartments.map(async (department) => {
        const response = await getDepartmentNameOptions('', validUniversities, undefined, department)
        return (response.data?.items || []).includes(department) ? department : null
      }))
      if (sequence !== selectionValidationSequence.current) return

      const validDepartments = departmentChecks.filter(Boolean)
      updateParams({ university: validUniversities, department: validDepartments, page: 1 })
      const removedUniversityCount = universities.length - validUniversities.length
      const removedDepartmentCount = selectedDepartments.length - validDepartments.length
      if (removedDepartmentCount > 0) {
        setFilterMessage(`${removedDepartmentCount} bölüm yeni üniversite seçimlerinde bulunmadığı için kaldırıldı.`)
      } else if (removedUniversityCount > 0) {
        setFilterMessage(`${removedUniversityCount} geçersiz üniversite seçimi kaldırıldı.`)
      }
    } catch {
      if (sequence !== selectionValidationSequence.current) return
      setFilterMessage('Seçili bölümlerin yeni üniversitelerde bulunup bulunmadığı doğrulanamadı.')
    }
  }, [selectedDepartments, updateParams])

  const selectDepartments = useCallback((departments) => {
    setFilterMessage('')
    updateParams({ department: departments, page: 1 })
  }, [updateParams])

  const selectCheckboxFilter = useCallback((name, values) => {
    setFilterMessage('')
    updateParams({ [name]: values, page: 1 })
  }, [updateParams])

  useEffect(() => {
    const controller = new AbortController()
    getUniversityFilters(controller.signal)
      .then((response) => setFilterOptions(response.data || {}))
      .catch(() => setFilterOptions({}))
    return () => controller.abort()
  }, [])

  const load = useCallback((signal) => {
    const sequence = ++requestSequence.current
    if (activeTab === 'favorites') return Promise.resolve()

    setStatus('loading')
    setMessage('')
    if (activeTab !== 'all' && !isAuthenticated) {
      setItems([])
      setSuggestions(null)
      setStatus('ready')
      return Promise.resolve()
    }

    const params = { ...universityApiParams(searchParams), limit: 50 }
    const request = activeTab === 'suggestions'
      ? getPreferenceSuggestions(user, params, signal)
      : getUniversities(params, user, signal)

    return request.then((response) => {
      if (sequence !== requestSequence.current) return
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
      if (sequence === requestSequence.current && error?.name !== 'AbortError') {
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
        const favoriteId = program.favorite_id || program.id
        const response = await removeFavorite(user, favoriteId)
        setItems((current) => current.map((item) => item.id === program.id ? { ...item, is_favorite: 0 } : item))
        updateSuggestionFavorite(program.id, 0)
        setMessage(response.message)
      } else {
        const response = await addFavorite(user, program.id)
        setItems((current) => current.map((item) => item.id === program.id ? { ...item, is_favorite: 1, favorite_id: program.id } : item))
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
      if (activeTab === 'all' && hasActiveProgramFilters) {
        return <div className="empty-state university-empty"><Search size={30} /><h2>Seçimlerine uygun program bulunamadı.</h2><p>Bir veya daha fazla filtreyi temizleyerek tekrar deneyebilirsin.</p></div>
      }
      return <div className="empty-state university-empty"><Search size={30} /><h2>Üniversite verileri henüz sisteme yüklenmedi.</h2><p>Daha sonra tekrar deneyebilirsin.</p></div>
    }

    return (
      <UniversityResults
        programs={programs}
        busyId={busyId || favoriteState.busyId}
        onFavorite={handleFavorite}
        onPreference={handlePreference}
      />
    )
  }

  const estimatedRank = Number(searchParams.get('estimated_rank') || 0)
  const hasOfficialRankBand = searchParams.get('rank_source') === 'official_osym_band'
  const activeMessage = activeTab === 'favorites' ? message || favoriteState.message : message
  const activeStatus = activeTab === 'favorites' && !message ? favoriteState.status : status

  return (
    <>
      <PageHeader title="Üniversite ve Bölüm Ara" description="Veriler geçmiş YKS yerleştirme sonuçlarına dayanmaktadır. Başarı sıraları gelecek dönem için kesin sonuç anlamına gelmez." />
      <section className="section university-preference-section"><Container className="university-page-container">
        <div className="information-banner">Nihai tercihlerinizi ÖSYM’nin güncel kılavuzundan kontrol edin.</div>
        <div className="preference-tabs" role="tablist" aria-label="Üniversite tercih bölümleri">
          {tabs.map((tab) => <button key={tab.value} className={activeTab === tab.value ? 'active' : ''} type="button" onClick={() => updateParams({ tab: tab.value === 'all' ? '' : tab.value, page: 1 })}>{tab.label}</button>)}
        </div>

        {authPrompt ? <div className="form-alert" role="alert"><p>{authPrompt}</p><Link className="button button--primary" to="/giris" state={{ from: location }}>Giriş Yap</Link></div> : null}
        {filterMessage ? <div className="information-banner" role="status">{filterMessage}</div> : null}
        {activeMessage ? <div className={activeStatus === 'error' ? 'form-alert' : 'success-alert'} role="status"><p>{activeMessage}</p></div> : null}

        {activeTab === 'all' && hasOfficialRankBand ? <div className="information-banner">2025 ÖSYM kümülatif dağılımından aktarılan resmî sıra aralığındaki programlar gösteriliyor. Tek bir sıra değeri kullanılmıyor.</div> : null}
        {activeTab === 'all' && !hasOfficialRankBand && estimatedRank > 0 ? <div className="information-banner">Tahmini sıralamana göre seçilen aralık gösteriliyor. Bu aralık geçmiş yerleştirme sonuçlarına dayalı yaklaşık bir yardımcı bilgidir.</div> : null}
        {activeTab === 'all' ? <div className="university-layout">
          <form className="filter-panel university-toolbar" aria-label="Üniversite tercih filtreleri" onSubmit={(event) => event.preventDefault()}>
            <div className="university-toolbar__header">
              <div className="filter-panel__header"><Filter size={18} /><h2>Programları filtrele</h2></div>
              <Button className="university-toolbar__clear" variant="secondary" onClick={() => { setFilterMessage(''); setSearchParams({}) }}>Filtreleri Temizle</Button>
            </div>
            <div className="university-toolbar__filters">
              <SearchableCombobox id="university-filter" label="Üniversite" values={selectedUniversities} onChange={selectUniversities} loadOptions={loadUniversityNames} placeholder="Üniversite ara" />
              <SearchableCombobox id="department-filter" label="Bölüm / program" values={selectedDepartments} onChange={selectDepartments} loadOptions={loadDepartmentNames} placeholder="Bölüm ara" />
              {checkboxFilters.map((filter) => (
                <CheckboxFilter
                  id={`${filter.name}-filter`}
                  key={filter.name}
                  label={filter.label}
                  options={filterOptionValues(
                    filterOptions[filter.optionKey] || [],
                    selectedCheckboxFilters[filter.name],
                  )}
                  values={selectedCheckboxFilters[filter.name]}
                  searchable={filter.searchable}
                  formatOption={filter.enumValues ? enumLabel : String}
                  onChange={(values) => selectCheckboxFilter(filter.name, values)}
                />
              ))}
              <label><span>{rankFilterLabels.min}</span><input type="number" min="1" placeholder="Örn. 10.000" value={fields.min_rank} onChange={(event) => updateParams({ min_rank: event.target.value, page: 1 })} /></label>
              <label><span>{rankFilterLabels.max}</span><input type="number" min="1" placeholder="Örn. 50.000" value={fields.max_rank} onChange={(event) => updateParams({ max_rank: event.target.value, page: 1 })} /></label>
              <label>
                <span>Sıralama</span>
                <select value={fields.sort} onChange={(event) => updateParams({ sort: event.target.value, page: 1 })}>
                  {universitySortOptions.map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                  ))}
                </select>
              </label>
            </div>
          </form>
          <div className="university-results-panel">
            <div className="university-results-summary" aria-live="polite">
              <strong>{status === 'ready' ? `${Number(pagination.total).toLocaleString('tr-TR')} program` : 'Programlar'}</strong>
              <span>2026, 2025, 2024 ve 2023 yerleştirme verileri birlikte gösteriliyor.</span>
            </div>
            {status === 'loading' ? <div className="loading-panel">Programlar yükleniyor...</div> : null}
            {status === 'error' ? <Button onClick={() => load()}>Yeniden Dene</Button> : null}
            {status === 'ready' ? renderPrograms(items) : null}
            {pagination.total_pages > 1 ? <nav className="pagination university-pagination" aria-label="Sonuç sayfaları">
              <Button variant="secondary" disabled={pagination.page <= 1} onClick={() => updateParams({ page: pagination.page - 1 })}>Önceki</Button>
              <div className="university-pagination__pages">
                {paginationPages(pagination.page, pagination.total_pages).map((page, index, pages) => (
                  <span key={page}>{index > 0 && page - pages[index - 1] > 1 ? <i aria-hidden="true">…</i> : null}<button className={page === pagination.page ? 'active' : ''} type="button" aria-current={page === pagination.page ? 'page' : undefined} onClick={() => updateParams({ page })}>{page}</button></span>
                ))}
              </div>
              <Button variant="secondary" disabled={pagination.page >= pagination.total_pages} onClick={() => updateParams({ page: pagination.page + 1 })}>Sonraki</Button>
            </nav> : null}
          </div>
        </div> : null}

        {activeTab === 'favorites' ? (!isAuthenticated ? <div className="empty-state"><h2>Favorilerini görmek için giriş yap.</h2><Button to="/giris">Giriş Yap</Button></div> : favoriteState.status === 'loading' ? <div className="loading-panel">Favorilerin yükleniyor...</div> : favoriteState.status === 'error' ? <Button onClick={() => favoriteState.load()}>Yeniden Dene</Button> : renderPrograms(favoriteState.favorites)) : null}
        {activeTab === 'suggestions' ? (!isAuthenticated ? <div className="empty-state"><h2>Önerileri kişiselleştirmek için giriş yap.</h2><Button to="/giris">Giriş Yap</Button></div> : status === 'loading' ? <div className="loading-panel">Önerilerin hazırlanıyor...</div> : suggestions ? <div className="suggestion-groups"><div className="information-banner">{suggestions.disclaimer}</div>{[['zor', 'Zor Tercihler'], ['hedef', 'Hedef Tercihler'], ['daha_guvenli', 'Daha Güvenli Tercihler']].map(([key, title]) => <section key={key}><h2>{title}</h2>{renderPrograms(suggestions.groups[key] || [])}</section>)}</div> : <div className="empty-state"><h2>Profilinden hedef sıralamanı ekle.</h2><Button to="/profil">Profilim</Button></div>) : null}
        <p className="university-data-note">Bilgilendirme: Veriler ÖSYM ve YÖK Atlas tarafından yayımlanan bilgiler esas alınarak sunulmaktadır. Kaynaklardaki güncelleme, düzeltme veya teknik farklılıklar nedeniyle veriler değişiklik gösterebilir. Tercih işlemlerinizi tamamlamadan önce güncel bilgileri ÖSYM ve YÖK Atlas üzerinden kontrol ediniz.</p>
      </Container></section>
    </>
  )
}

export default UniversityPreferencePage
