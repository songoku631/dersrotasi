import { ExternalLink, Info, Search, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { compareOfficialYksRankBands } from '../api/yksApi'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import {
  formatOfficialRank,
  formatOfficialScore,
  officialBandPreferenceUrl,
} from '../utils/yksRankBands'

const scoreTypes = [
  { value: 'SAY', label: 'Sayısal' },
  { value: 'EA', label: 'Eşit Ağırlık' },
  { value: 'SÖZ', label: 'Sözel' },
  { value: 'DİL', label: 'Dil' },
]

const scoreKinds = [
  {
    value: 'placement',
    label: 'Yerleştirme puanı',
    shortLabel: 'Y- puanı',
    description: 'ÖSYM sonuç belgenizde Y-SAY, Y-EA, Y-SÖZ veya Y-DİL olarak görünen puan.',
  },
  {
    value: 'exam',
    label: 'Sınav puanı',
    shortLabel: 'Ham yerleştirme öncesi puan',
    description: 'OBP eklenmeden önce sonuç belgenizde SAY, EA, SÖZ veya DİL olarak görünen puan.',
  },
]

function scoreInputHelp(scoreKind, scoreType) {
  if (scoreKind === 'placement') {
    return `ÖSYM sonuç belgenizdeki Y-${scoreType} puanını girin. Bu seçenek OBP eklenmiş yerleştirme puanı içindir.`
  }
  return `ÖSYM sonuç belgenizdeki ${scoreType} sınav puanını girin. Y-${scoreType} puanını bu alana girmeyin.`
}

function resultStatus(year) {
  if (year.status === 'band') {
    return <strong>{formatOfficialRank(year.rank_min)} – {formatOfficialRank(year.rank_max)}</strong>
  }
  if (year.status === 'no_candidates_in_band') {
    return <span>Bu iki resmî eşik arasında aday görünmüyor.</span>
  }
  return <span>Yayımlanan tabloda daha dar bir sıra aralığı belirlenemiyor.</span>
}

function betterBoundary(year) {
  if (year.status === 'insufficient_resolution') return '—'
  if (year.higher_score_threshold === null) return '1. sıradan başlayan üst sınır'
  return `${formatOfficialScore(year.higher_score_threshold)} ve üzeri: ${formatOfficialRank(year.higher_threshold_candidate_count)} aday`
}

function worseBoundary(year) {
  if (year.status === 'insufficient_resolution') {
    return `En düşük yayımlanmış eşik: ${formatOfficialScore(year.minimum_published_threshold)} puan`
  }
  return `${formatOfficialScore(year.lower_score_threshold)} ve üzeri: ${formatOfficialRank(year.lower_threshold_candidate_count)} aday`
}

function YksEstimatePage() {
  const [scoreType, setScoreType] = useState('SAY')
  const [scoreKind, setScoreKind] = useState('placement')
  const [score, setScore] = useState('')
  const [result, setResult] = useState(null)
  const [status, setStatus] = useState('idle')
  const [message, setMessage] = useState('')

  const maximumScore = scoreKind === 'placement' ? 560 : 500
  const preferenceUrl = officialBandPreferenceUrl(result)

  function selectScoreType(value) {
    setScoreType(value)
    setResult(null)
    setMessage('')
  }

  function selectScoreKind(value) {
    setScoreKind(value)
    setScore('')
    setResult(null)
    setMessage('')
  }

  async function compare(event) {
    event.preventDefault()
    const numericScore = Number(score)
    setMessage('')

    if (score === '' || !Number.isFinite(numericScore) || numericScore < 100 || numericScore > maximumScore) {
      setMessage(`Puan 100 ile ${maximumScore} arasında olmalıdır.`)
      return
    }

    setStatus('loading')
    try {
      const response = await compareOfficialYksRankBands({
        score_type: scoreType,
        score_kind: scoreKind,
        score: numericScore,
      })
      setResult(response.data)
      setStatus('ready')
    } catch (error) {
      setResult(null)
      setMessage(error.message)
      setStatus('error')
    }
  }

  return (
    <>
      <PageHeader
        eyebrow="ÖSYM resmî kümülatif verileri"
        title="YKS Puan - Sıralama Karşılaştırma"
        description="ÖSYM puanını gir; 2025, 2024 ve 2023 resmî dağılımlarının kesin olarak sınırlandırdığı başarı sırası aralığını gör."
      />
      <section className="section official-rank-section">
        <Container>
          <div className="official-rank-intro">
            <ShieldCheck aria-hidden="true" size={24} />
            <div>
              <strong>Tahmin veya interpolasyon yapılmaz.</strong>
              <p>Sonuçlar yalnız ÖSYM’nin yayımladığı kümülatif puan eşiklerindeki aday sayılarına dayanır.</p>
            </div>
          </div>

          <form className="estimate-card official-rank-form" onSubmit={compare}>
            <fieldset>
              <legend>1. Puan türünü seç</legend>
              <div className="score-type-grid official-score-type-grid">
                {scoreTypes.map((item) => (
                  <button
                    className={scoreType === item.value ? 'score-type-option is-selected' : 'score-type-option'}
                    key={item.value}
                    type="button"
                    onClick={() => selectScoreType(item.value)}
                  >
                    <strong>{item.value}</strong>
                    <span>{item.label}</span>
                  </button>
                ))}
              </div>
            </fieldset>

            <fieldset>
              <legend>2. Sonuç belgendeki puan tipini seç</legend>
              <div className="official-score-kind-grid">
                {scoreKinds.map((item) => (
                  <button
                    className={scoreKind === item.value ? 'official-score-kind is-selected' : 'official-score-kind'}
                    key={item.value}
                    type="button"
                    onClick={() => selectScoreKind(item.value)}
                  >
                    <span>{item.shortLabel}</span>
                    <strong>{item.label}</strong>
                    <small>{item.description}</small>
                  </button>
                ))}
              </div>
            </fieldset>

            <fieldset>
              <legend>3. YKS puanını gir</legend>
              <div className="official-score-entry">
                <label htmlFor="official-yks-score">
                  <span>{scoreKind === 'placement' ? `Y-${scoreType} yerleştirme puanı` : `${scoreType} sınav puanı`}</span>
                  <input
                    id="official-yks-score"
                    inputMode="decimal"
                    max={maximumScore}
                    min="100"
                    placeholder="Örn. 443"
                    required
                    step="0.00001"
                    type="number"
                    value={score}
                    onChange={(event) => setScore(event.target.value)}
                  />
                </label>
                <p><Info aria-hidden="true" size={17} />{scoreInputHelp(scoreKind, scoreType)}</p>
              </div>
            </fieldset>

            {message ? <div className="form-alert" role="alert"><p>{message}</p></div> : null}
            <Button icon={Search} type="submit" disabled={status === 'loading'}>
              {status === 'loading' ? 'Karşılaştırılıyor...' : 'Resmî sıra aralığını göster'}
            </Button>
          </form>

          {result ? (
            <section className="official-rank-results" aria-live="polite">
              <div className="official-rank-results__header">
                <div>
                  <p className="eyebrow">Resmî kümülatif dağılım sonucu</p>
                  <h2>{formatOfficialScore(result.score)} {result.score_label} için sıra aralıkları</h2>
                </div>
                <span>Merkez değer üretilmez</span>
              </div>

              <div className="official-rank-table-wrap">
                <table className="official-rank-table">
                  <thead>
                    <tr>
                      <th scope="col">Karşılaştırma</th>
                      {result.years.map((year) => <th scope="col" key={year.year}>{year.year}</th>)}
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="official-rank-table__primary">
                      <th scope="row">Resmî sıra aralığı</th>
                      {result.years.map((year) => <td key={year.year}>{resultStatus(year)}<small>ÖSYM resmî dağılım verisine göre</small></td>)}
                    </tr>
                    <tr>
                      <th scope="row">İyi sınırın dayandığı veri</th>
                      {result.years.map((year) => <td key={year.year}>{betterBoundary(year)}</td>)}
                    </tr>
                    <tr>
                      <th scope="row">Kötü sınırın dayandığı veri</th>
                      {result.years.map((year) => <td key={year.year}>{worseBoundary(year)}</td>)}
                    </tr>
                    <tr>
                      <th scope="row">Kaynak</th>
                      {result.years.map((year) => (
                        <td key={year.year}>
                          <a href={year.source.url} rel="noreferrer" target="_blank">
                            ÖSYM <ExternalLink aria-hidden="true" size={14} />
                          </a>
                          <small>{year.source.table_name}</small>
                        </td>
                      ))}
                    </tr>
                  </tbody>
                </table>
              </div>

              <div className="information-banner official-rank-disclaimer">
                <strong>Nasıl okunmalı?</strong> {result.disclaimer}
              </div>

              {preferenceUrl ? (
                <div className="estimate-actions official-rank-actions">
                  <Button to={preferenceUrl}>Bu aralıktaki 2025 bölümlerini gör</Button>
                  <p>Tercih sayfasına tek bir sıra değil, 2025 resmî bandının iyi ve kötü sınırları aktarılır.</p>
                </div>
              ) : null}
            </section>
          ) : null}
        </Container>
      </section>
    </>
  )
}

export default YksEstimatePage
