import { ArrowLeft, ArrowRight, Calculator, Save } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import { estimateYks, getYksEstimates, saveYksEstimate } from '../api/yksApi'
import { useAuth } from '../context/useAuth'
import { liveNet, YKS_SCORE_TYPES, YKS_TESTS, YKS_YEAR } from '../config/yks2025'

const steps = ['Alan seçimi', 'Net bilgileri', 'Diploma ve OBP', 'Sonuç']
const confidenceLabels = {
  high: 'Yüksek',
  medium: 'Orta',
  low: 'Düşük',
  unavailable: 'Doğrulanmadı',
}

function formatNumber(value, digits = 2) {
  if (value === null || value === undefined) return 'Veri yetersiz'
  return new Intl.NumberFormat('tr-TR', { maximumFractionDigits: digits }).format(value)
}

function YksEstimatePage() {
  const { user, isAuthenticated } = useAuth()
  const [step, setStep] = useState(1)
  const [scoreType, setScoreType] = useState('SAY')
  const [inputMode, setInputMode] = useState('correct_wrong')
  const [tests, setTests] = useState({})
  const [diplomaGrade, setDiplomaGrade] = useState('')
  const [previouslyPlaced, setPreviouslyPlaced] = useState(false)
  const [result, setResult] = useState(null)
  const [history, setHistory] = useState([])
  const [status, setStatus] = useState('idle')
  const [message, setMessage] = useState('')

  const activeTests = YKS_SCORE_TYPES[scoreType].tests
  const validationErrors = useMemo(() => activeTests.flatMap((key) => {
    const value = tests[key] || {}
    const limit = YKS_TESTS[key].questions
    if (inputMode === 'net') {
      const net = Number(value.net || 0)
      return net < 0 || net > limit ? [`${YKS_TESTS[key].label} neti 0–${limit} aralığında olmalı.`] : []
    }
    const correct = Number(value.correct || 0)
    const wrong = Number(value.wrong || 0)
    return correct < 0 || wrong < 0 || correct + wrong > limit
      ? [`${YKS_TESTS[key].label} için doğru ve yanlış toplamı ${limit} soruyu aşamaz.`]
      : []
  }), [activeTests, inputMode, tests])

  useEffect(() => {
    if (!user) {
      setHistory([])
      return undefined
    }
    const controller = new AbortController()
    getYksEstimates(user, controller.signal)
      .then((response) => setHistory(response.data?.items || []))
      .catch((error) => {
        if (error.name !== 'AbortError') setHistory([])
      })
    return () => controller.abort()
  }, [user])

  function updateTest(key, field, value) {
    setTests((current) => ({ ...current, [key]: { ...(current[key] || {}), [field]: value } }))
  }

  function payload() {
    const selectedTests = Object.fromEntries(activeTests.map((key) => [key, tests[key] || {}]))
    return {
      year: YKS_YEAR,
      score_type: scoreType,
      input_mode: inputMode,
      diploma_grade: diplomaGrade === '' ? 0 : Number(diplomaGrade),
      previously_placed: previouslyPlaced,
      tests: selectedTests,
    }
  }

  async function calculate(event) {
    event.preventDefault()
    setMessage('')
    if (validationErrors.length) {
      setMessage(validationErrors[0])
      return
    }
    setStatus('loading')
    try {
      const response = await estimateYks(payload())
      setResult(response.data)
      setStep(4)
      setStatus('ready')
    } catch (error) {
      setMessage(error.message)
      setStatus('error')
    }
  }

  async function saveResult() {
    if (!user) return
    setStatus('saving')
    setMessage('')
    try {
      const response = await saveYksEstimate(user, payload())
      setHistory((current) => [response.data, ...current])
      setMessage('Hesaplaman başarıyla kaydedildi.')
      setStatus('ready')
    } catch (error) {
      setMessage(error.message)
      setStatus('error')
    }
  }

  const preferenceUrl = result?.rank_estimate?.center
    ? `/universite-tercih?score_type=${encodeURIComponent(result.score_type)}&estimated_rank=${result.rank_estimate.center}&min_rank=${result.rank_estimate.min}&max_rank=${result.rank_estimate.max}&year=${result.year}`
    : null

  return (
    <>
      <PageHeader
        title="YKS Puan ve Sıralama Tahmini"
        description="TYT ve AYT sonuçlarını gir, tahmini puanını ve başarı sıralaması aralığını gör."
      />
      <section className="section">
        <Container>
          <ol className="estimate-steps" aria-label="Hesaplama adımları">
            {steps.map((label, index) => <li className={step === index + 1 ? 'is-active' : step > index + 1 ? 'is-complete' : ''} key={label}><span>{index + 1}</span>{label}</li>)}
          </ol>

          <form className="estimate-card" onSubmit={calculate}>
            {step === 1 ? <div>
              <p className="eyebrow">1. adım</p><h2>Hangi puan türünü hesaplamak istiyorsun?</h2>
              <div className="score-type-grid">
                {Object.entries(YKS_SCORE_TYPES).map(([value, config]) => <button className={scoreType === value ? 'score-type-option is-selected' : 'score-type-option'} key={value} type="button" onClick={() => setScoreType(value)}><strong>{config.label}</strong><span>{value}</span></button>)}
              </div>
            </div> : null}

            {step === 2 ? <div>
              <p className="eyebrow">2. adım</p><h2>Sonuçlarını gir</h2>
              <div className="input-mode-switch" role="group" aria-label="Giriş yöntemi">
                <button className={inputMode === 'correct_wrong' ? 'is-active' : ''} type="button" onClick={() => setInputMode('correct_wrong')}>Doğru ve yanlış</button>
                <button className={inputMode === 'net' ? 'is-active' : ''} type="button" onClick={() => setInputMode('net')}>Doğrudan net</button>
              </div>
              <div className="estimate-test-list">
                {activeTests.map((key) => {
                  const test = YKS_TESTS[key]
                  const value = tests[key] || {}
                  return <fieldset className="estimate-test-row" key={key}><legend><strong>{test.label}</strong><span>{test.questions} soru</span></legend>
                    <div className="estimate-test-inputs">
                      {inputMode === 'correct_wrong' ? <>
                        <label><span>Doğru</span><input inputMode="numeric" min="0" max={test.questions} step="1" type="number" value={value.correct || ''} onChange={(event) => updateTest(key, 'correct', event.target.value)} /></label>
                        <label><span>Yanlış</span><input inputMode="numeric" min="0" max={test.questions} step="1" type="number" value={value.wrong || ''} onChange={(event) => updateTest(key, 'wrong', event.target.value)} /></label>
                      </> : <label><span>Net</span><input inputMode="decimal" min="0" max={test.questions} step="0.25" type="number" value={value.net || ''} onChange={(event) => updateTest(key, 'net', event.target.value)} /></label>}
                      <div className="live-net"><span>Anlık net</span><strong>{formatNumber(liveNet(value, inputMode))}</strong></div>
                    </div>
                  </fieldset>
                })}
              </div>
              {validationErrors.length ? <div className="form-alert" role="alert">{validationErrors.map((error) => <p key={error}>{error}</p>)}</div> : null}
            </div> : null}

            {step === 3 ? <div>
              <p className="eyebrow">3. adım</p><h2>Diploma ve OBP bilgileri</h2>
              <div className="obp-form">
                <label><span>Diploma notu (0–100)</span><input inputMode="decimal" min="0" max="100" step="0.01" required type="number" value={diplomaGrade} onChange={(event) => setDiplomaGrade(event.target.value)} /></label>
                <label className="checkbox-row"><input type="checkbox" checked={previouslyPlaced} onChange={(event) => setPreviouslyPlaced(event.target.checked)} /><span>Geçen yıl bir yükseköğretim programına yerleştim</span></label>
              </div>
              <div className="information-banner">Diploma notu 50’nin altındaysa resmî kural gereği OBP hesabında 50 kabul edilir. Geçen yıl yerleştiysen OBP katkı katsayısı yarıya düşer.</div>
            </div> : null}

            {step === 4 && result ? <div className="estimate-results">
              <p className="eyebrow">Hesaplama sonucu</p><h2>Tahmini {result.score_type} sonucun</h2>
              <p><strong>{result.year} verilerine göre tahmin</strong></p>
              <div className="result-metric-grid">
                <article><span>Diploma notu</span><strong>{formatNumber(result.obp_details.diploma_grade)}</strong></article>
                <article><span>Tahmini ham puan</span><strong>{formatNumber(result.scores.raw_score)}</strong>{result.scores.raw_score_range ? <small>{formatNumber(result.scores.raw_score_range.min)} – {formatNumber(result.scores.raw_score_range.max)}</small> : null}</article>
                <article><span>OBP</span><strong>{formatNumber(result.scores.obp)}</strong></article>
                <article><span>OBP katkısı</span><strong>{formatNumber(result.scores.obp_contribution)}</strong></article>
                <article><span>Tahmini yerleştirme puanı</span><strong>{formatNumber(result.scores.placement_score)}</strong>{result.scores.placement_score_range ? <small>{formatNumber(result.scores.placement_score_range.min)} – {formatNumber(result.scores.placement_score_range.max)}</small> : null}</article>
                <article><span>Kırık OBP</span><strong>{result.obp_details.previously_placed_reduction_applied ? 'Uygulandı' : 'Uygulanmadı'}</strong></article>
                <article><span>Tahmin güveni</span><strong>{confidenceLabels[result.confidence] || 'Doğrulanmadı'}</strong><small>{result.confidence_explanation}</small></article>
              </div>
              <section className="rank-result"><span>Tahmini başarı sırası</span>{result.rank_estimate.center ? <><strong>{formatNumber(result.rank_estimate.min, 0)} – {formatNumber(result.rank_estimate.max, 0)}</strong><p>Merkez tahmin: {formatNumber(result.rank_estimate.center, 0)}</p></> : <><strong>Sıralama için yeterli program verisi yok</strong><p>Tahmini puanın hesaplandı; ancak aynı puan türünde karşılaştırma yapacak yeterli puan ve başarı sırası verisi bulunamadı.</p></>}</section>
              <section><h3>Net özeti</h3><div className="net-summary">{Object.entries(result.nets).map(([key, net]) => <div key={key}><span>{YKS_TESTS[key]?.label || key}</span><strong>{formatNumber(net)} net</strong></div>)}</div></section>
              <div className="information-banner"><strong>Önemli:</strong> {result.disclaimer}</div>
              <div className="estimate-actions">
                {preferenceUrl ? <Button to={preferenceUrl}>Bu sıralamayla bölümleri gör</Button> : null}
                {isAuthenticated ? <Button icon={Save} variant="secondary" disabled={status === 'saving'} onClick={saveResult}>{status === 'saving' ? 'Kaydediliyor...' : 'Hesabıma Kaydet'}</Button> : <Button to="/giris" variant="secondary">Kaydetmek için giriş yap</Button>}
              </div>
            </div> : null}

            {message ? <div className={status === 'error' ? 'form-alert' : 'success-message'} role="status">{message}</div> : null}
            <div className="wizard-navigation">
              {step > 1 ? <Button icon={ArrowLeft} variant="secondary" onClick={() => setStep((current) => current - 1)}>Geri</Button> : <span />}
              {step < 3 ? <Button icon={ArrowRight} onClick={() => setStep((current) => current + 1)}>Devam Et</Button> : null}
              {step === 3 ? <Button icon={Calculator} type="submit" disabled={status === 'loading'}>{status === 'loading' ? 'Hesaplanıyor...' : 'Sonucu Hesapla'}</Button> : null}
              {step === 4 ? <Button variant="secondary" onClick={() => { setStep(1); setResult(null); setMessage('') }}>Yeni Hesaplama</Button> : null}
            </div>
          </form>

          {isAuthenticated && history.length ? <section className="estimate-history"><h2>Geçmiş hesaplamaların</h2><div>{history.map((item) => <article key={item.id}><strong>{item.score_type} · {item.year}</strong><span>{new Date(item.created_at).toLocaleDateString('tr-TR')}</span><p>{item.estimated_rank_center ? `Merkez sıra: ${formatNumber(item.estimated_rank_center, 0)}` : 'Bu hesaplamada sıralama için yeterli program verisi bulunamadı.'}</p></article>)}</div></section> : null}
        </Container>
      </section>
    </>
  )
}

export default YksEstimatePage
