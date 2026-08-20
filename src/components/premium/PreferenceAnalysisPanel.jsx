import { AlertTriangle, Sparkles } from 'lucide-react'
import { formatRank } from '../../utils/universityFormat'

const years = ['2026', '2025', '2024', '2023']

function PreferenceAnalysisPanel({ analysis }) {
  if (!analysis) return null
  return (
    <section className="premium-analysis" aria-live="polite">
      <div className="premium-analysis__heading"><Sparkles aria-hidden="true" /><div><p className="eyebrow">Premium AI analizi</p><h2>Tercih listenin risk dağılımı</h2></div></div>
      <p className="premium-analysis__summary">{analysis.summary}</p>
      <div className="premium-analysis__counts">
        <span>🔴 İddialı <strong>{analysis.counts.zor}</strong></span><span>🟡 Hedef <strong>{analysis.counts.hedef}</strong></span><span>🟢 Daha Güvenli <strong>{analysis.counts.daha_guvenli}</strong></span>
      </div>
      <div className="premium-analysis__warnings">
        {analysis.warnings.map((warning) => <p key={warning}><AlertTriangle aria-hidden="true" />{warning}</p>)}
      </div>
      <div className="premium-analysis__items">
        {analysis.items.map((item) => (
          <article key={item.id} className="premium-analysis-card">
            <div><small>#{item.position} · {item.city}</small><h3>{item.department_name}</h3><p>{item.university_name}</p></div>
            <span className={`premium-risk premium-risk--${item.analysis.label || 'unknown'}`}>{item.analysis.label_text}</span>
            <dl>{years.map((year) => <div key={year}><dt>{year}</dt><dd>{item.rankings[year] ? formatRank(item.rankings[year]) : 'Veri yok'}</dd></div>)}<div><dt>Senin sıran</dt><dd>{formatRank(item.analysis.user_rank)}</dd></div></dl>
            <p className="premium-analysis-card__comment">{item.analysis.comment}</p>
          </article>
        ))}
      </div>
      <small className="premium-analysis__disclaimer">{analysis.disclaimer}</small>
    </section>
  )
}

export default PreferenceAnalysisPanel
