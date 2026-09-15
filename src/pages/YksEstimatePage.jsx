import { useEffect, useRef, useState } from 'react'
import { Calculator } from 'lucide-react'
import Button from '../components/Button'
import Container from '../components/Container'
import { YKS_TESTS, YKS_SCORE_TYPES } from '../config/yksTests'
import { yksCoefficients } from '../config/yksCoefficients'
import { defaultScoreYear, yksYears } from '../config/yksYears'
import { calculateNet, calculateYks } from '../utils/yksCalculator'
import { compareOfficialYksRankBands } from '../api/yksApi'
import { formatOfficialRank, formatOfficialScore, officialBandPreferenceUrl, selectRankYear } from '../utils/yksRankBands'
import '../styles/yksCalculator.css'

function TestInputs({ keys, tests, onChange, title }) {
  return <div className="yks-tests">
    <div className="yks-test-row yks-test-head" aria-hidden="true"><span>Ders</span><span>Doğru</span><span>Yanlış</span><span>Net</span></div>
    {keys.map(key => {
      const test = YKS_TESTS[key], value = tests[key] || {}
      let net, invalid = false
      try { net = calculateNet(value.correct, value.wrong, test.questions) } catch { invalid = true }
      return <div className="yks-test" key={key}>
        <div className="yks-test-row">
          <label htmlFor={`${key}-correct`}>{test.label}<small>{test.questions} soru</small></label>
          {['correct', 'wrong'].map(property => <input key={property} id={`${key}-${property}`} aria-label={`${title} ${test.label} ${property === 'correct' ? 'doğru' : 'yanlış'}`} aria-invalid={invalid} aria-describedby={invalid ? `${key}-error` : undefined} type="number" inputMode="numeric" min="0" max={test.questions} step="1" placeholder="0" value={value[property] ?? ''} onChange={event => onChange(key, property, event.target.value)} />)}
          <output aria-label={`${title} ${test.label} net`}>{invalid ? '—' : formatOfficialScore(net)}</output>
        </div>
        {invalid && <small className="yks-field-error" id={`${key}-error`}>Toplam en fazla {test.questions}; değerler 0 veya pozitif tam sayı olmalı.</small>}
      </div>
    })}
  </div>
}

function YksEstimatePage() {
  const [year, setYear] = useState(defaultScoreYear)
  const [diploma, setDiploma] = useState('')
  const [brokenObp, setBrokenObp] = useState(false)
  const [tests, setTests] = useState({})
  const [field, setField] = useState('SAY')
  const [result, setResult] = useState(null)
  const [ranks, setRanks] = useState({})
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const resultsRef = useRef(null)
  const requestRef = useRef(null)
  useEffect(() => () => requestRef.current?.abort(), [])
  useEffect(() => {
    if (result) {
      resultsRef.current?.focus({ preventScroll: true })
      resultsRef.current?.scrollIntoView({ block: 'start', behavior: 'instant' })
    }
  }, [result])

  function invalidate() {
    requestRef.current?.abort()
    setResult(null); setRanks({}); setLoading(false); setError('')
  }
  function updateTest(key, property, value) {
    invalidate()
    setTests(current => ({ ...current, [key]: { ...current[key], [property]: value } }))
  }
  async function submit(event) {
    event.preventDefault()
    invalidate()
    let calculated
    try { calculated = calculateYks({ year, tests, diplomaGrade: diploma, previouslyPlaced: brokenObp }) }
    catch (err) { setError(err.message); return }
    setResult(calculated)
    const controller = new AbortController()
    requestRef.current = controller
    setLoading(true)
    const rankConfig = yksYears.find(item => item.year === calculated.scoreYear)?.rank
    const entries = await Promise.all(calculated.scores.filter(item => !item.reason && rankConfig?.scoreTypes.includes(item.type)).map(async item => {
      try {
        const response = await compareOfficialYksRankBands({ score_type: item.type, score_kind: 'placement', score: item.placement }, controller.signal)
        const band = selectRankYear(response.data, calculated.scoreYear, rankConfig.datasetYear)
        return [item.type, { band, preferenceUrl: band?.year === 2025 ? officialBandPreferenceUrl(response.data) : '' }]
      } catch { return [item.type, { error: true }] }
    }))
    if (!controller.signal.aborted) { setRanks(Object.fromEntries(entries)); setLoading(false) }
  }

  return <section className="yks-calculator"><Container>
    <header className="yks-heading"><h1>YKS Puan ve Sıralama Hesaplama</h1><p>Doğru ve yanlışlarını gir; tüm puan türlerini birlikte hesapla.</p></header>
    <form onSubmit={submit} noValidate>
      <div className="yks-settings yks-card">
        <label>Hesaplama yılı<select value={year} onChange={event => { invalidate(); setYear(Number(event.target.value)) }}>{yksYears.map(item => <option key={item.year} value={item.year} disabled={!item.score.enabled}>{item.year}{item.score.enabled ? '' : ' — hesaplama verisi henüz yok'}</option>)}</select></label>
        <label>Diploma notu<input aria-describedby="yks-diploma-help" type="number" inputMode="decimal" min="0" max="100" step="0.01" placeholder="0–100" value={diploma} onChange={event => { invalidate(); setDiploma(event.target.value) }} /></label>
        <label className="yks-checkbox"><input type="checkbox" checked={brokenObp} onChange={event => { invalidate(); setBrokenObp(event.target.checked) }} /><span>Geçen yıl bir programa yerleştim<small>Kırık OBP uygula</small></span></label>
        <p id="yks-diploma-help" className="yks-help">Boş ders alanları 0 kabul edilir. Diploma notu boş veya 50’nin altındaysa OBP için 50 kullanılır.</p>
      </div>
      <div className="yks-entry-grid">
        <section className="yks-card"><h2>TYT <small>120 soru</small></h2><TestInputs keys={YKS_SCORE_TYPES.TYT.tests} tests={tests} onChange={updateTest} title="TYT" /></section>
        <div className="yks-field-sections">
          <section className="yks-card"><h2>AYT <small>Alanını seç</small></h2>
            <div className="yks-field-switch" aria-label="AYT alanı">{['SAY', 'EA', 'SÖZ'].map(value => <button type="button" key={value} aria-pressed={field === value} onClick={() => setField(value)}>{value}</button>)}</div>
            <TestInputs keys={YKS_SCORE_TYPES[field].tests.filter(key => key.startsWith('ayt'))} tests={tests} onChange={updateTest} title="AYT" />
            <p className="yks-help">Alan değiştirince girdilerin korunur. Ortak dersler diğer alanlara da uygulanır.</p>
          </section>
          <details className="yks-card yks-ydt"><summary>YDT · Yabancı Dil <small>80 soru</small></summary><TestInputs keys={['ydt_language']} tests={tests} onChange={updateTest} title="YDT" /></details>
        </div>
      </div>
      {error && <p className="form-alert" role="alert">{error}</p>}
      <div className="yks-submit"><Button icon={Calculator} type="submit">Puanımı Hesapla</Button><span>Puan ve sıralamalar tahminidir; kesin ÖSYM sonucu değildir.</span></div>
    </form>
    {result && <section className="yks-results yks-card" ref={resultsRef} tabIndex={-1} aria-label="Hesaplama sonuçları">
      <header><h2>{result.scoreYear} sonuçların</h2><p className="yks-help">Puan hesabı: {result.scoreYear} için yayımlanmış katsayı tablosu. Sıra aralıkları yalnız aynı yılın ÖSYM dağılımıyla eşleştirilir.</p></header>
      <div className="yks-totals"><span>TYT neti<strong>{formatOfficialScore(result.totals.tyt)}</strong></span><span>AYT neti <small>(tüm girdiler)</small><strong>{formatOfficialScore(result.totals.ayt)}</strong></span><span>YDT neti<strong>{formatOfficialScore(result.totals.ydt)}</strong></span><span>OBP<strong>{formatOfficialScore(result.obp.obp)}</strong></span><span>OBP katkısı<strong>+{formatOfficialScore(result.obp.contribution)}</strong></span></div>
      <div className="yks-score-results">{result.scores.map(item => {
        const rank = ranks[item.type], band = rank?.band
        return <article key={item.type} className="yks-score-result"><h3>{item.type}</h3>
          {item.reason ? <p className="yks-help">Puan hesaplanamadı: {item.reason}</p> : <>
            <dl><div><dt>{item.type} ham puan</dt><dd>{formatOfficialScore(item.raw)}</dd></div><div><dt>Y-{item.type} yerleştirme</dt><dd>{formatOfficialScore(item.placement)}</dd></div></dl>
            {item.type !== 'TYT' && <p className="yks-help">{item.type === 'DİL' ? 'YDT' : `AYT ${item.type}`} neti: {formatOfficialScore(item.fieldNet)}</p>}
            <div className="yks-rank" aria-live="polite"><small>Tahmini yerleştirme sırası aralığı</small>{band?.status === 'band' ? <><strong>{formatOfficialRank(band.rank_min)} – {formatOfficialRank(band.rank_max)}</strong><a href={band.source.url} target="_blank" rel="noreferrer">ÖSYM {band.year} dağılımı ↗</a>{rank.preferenceUrl && <a href={rank.preferenceUrl}>Bu aralıktaki bölümler →</a>}</> : <p>{item.type === 'TYT' ? 'TYT sıra verisi mevcut değil.' : loading ? 'Sıra aralığı alınıyor…' : rank?.error ? 'Sıra verisine ulaşılamadı. Puanların hesaplandı; tekrar deneyebilirsin.' : 'Bu puan için uygun sıra aralığı belirlenemiyor.'}</p>}</div>
          </>}
        </article>
      })}</div>
    </section>}
    <details className="yks-method"><summary>Hesaplama yöntemi ve kaynaklar</summary><p>Net = doğru − yanlış / 4. Ham puan = başlangıç puanı + her dersin neti × yılın katsayısı. OBP = diploma notu × 5; katkısı normalde × 0,12, kırık OBP’de × 0,06’dır. Asgari 0,5 net koşulunu karşılamayan puan türleri hesaplanmaz.</p><p>2023, 2024 ve 2025 katsayıları yıl bazlı yayımlanmış referans tablolarından aktarılmıştır. Katsayılar tabloda yuvarlanmış biçimde yayımlandığı için sonuçlar kesin ÖSYM sonuç belgesi yerine referans hesap olarak kullanılmalıdır. 2026 için böyle bir yıl sonu katsayı tablosu bulunmadığından yıl seçilemez. Sıra aralığı bir güven aralığı değildir ve puan tahminindeki belirsizliği kapsamaz. Mesleki ek puan ve özel durumlar kapsam dışıdır.</p><a href={yksCoefficients[year].sources.coefficient_tables} target="_blank" rel="noreferrer">Yıl bazlı katsayı tablosu ↗</a><a href={yksCoefficients[year].sources.guide} target="_blank" rel="noreferrer">ÖSYM {year} kılavuzu ↗</a><a href={yksCoefficients[year].sources.statistics} target="_blank" rel="noreferrer">ÖSYM sayısal bilgiler ↗</a></details>
  </Container></section>
}

export default YksEstimatePage
