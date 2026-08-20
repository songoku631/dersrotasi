import { X } from 'lucide-react'
import { enumLabel, formatRank, formatScore, formatNullable } from '../../utils/universityFormat'

const years = ['2026', '2025', '2024', '2023']

function ProgramColumn({ program }) {
  return (
    <article className="premium-comparison__program">
      <span className={`premium-risk premium-risk--${program.analysis.label || 'unknown'}`}>{program.analysis.label_text}</span>
      <h3>{program.department_name}</h3><p>{program.university_name}</p>
      <dl className="premium-comparison__facts">
        <div><dt>Şehir</dt><dd>{program.city || 'Veri yok'}</dd></div><div><dt>Üniversite türü</dt><dd>{enumLabel(program.university_type)}</dd></div>
        <div><dt>Puan türü</dt><dd>{enumLabel(program.score_type)}</dd></div><div><dt>Burs</dt><dd>{enumLabel(program.scholarship_type)}</dd></div>
        <div><dt>Dil</dt><dd>{program.education_language || 'Veri yok'}</dd></div><div><dt>Öğretim</dt><dd>{enumLabel(program.education_type)}</dd></div>
        <div><dt>Kontenjan</dt><dd>{program.quota == null ? 'Veri yok' : formatNullable(program.quota)}</dd></div><div><dt>Yerleşen</dt><dd>{program.placed_count == null ? 'Veri yok' : formatNullable(program.placed_count)}</dd></div>
        <div><dt>Sıra değişimi</dt><dd>{program.analysis.rank_change == null ? 'Veri yok' : program.analysis.rank_change.toLocaleString('tr-TR')}</dd></div><div><dt>Kontenjan değişimi</dt><dd>{program.analysis.quota_change == null ? 'Veri yok' : program.analysis.quota_change.toLocaleString('tr-TR')}</dd></div>
      </dl>
      <div className="premium-comparison__history">
        <div className="premium-comparison__history-head"><span>Yıl</span><span>Sıra</span><span>Puan</span><span>Kont.</span></div>
        {years.map((year) => <div key={year}><strong>{year}</strong><span>{program.rankings[year] ? formatRank(program.rankings[year]) : 'Veri yok'}</span><span>{program.scores[year] ? formatScore(program.scores[year]) : 'Veri yok'}</span><span>{program.quotas[year] == null ? 'Veri yok' : formatNullable(program.quotas[year])}</span></div>)}
      </div>
      <p className="premium-analysis-card__comment">{program.analysis.comment}</p>
    </article>
  )
}

function ProgramComparisonModal({ comparison, loading, error, onClose }) {
  if (!comparison && !loading && !error) return null
  return (
    <div className="premium-modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section className="premium-modal premium-comparison" role="dialog" aria-modal="true" aria-labelledby="comparison-title" onMouseDown={(event) => event.stopPropagation()}>
        <button className="premium-modal__close" type="button" aria-label="Kapat" onClick={onClose}><X /></button>
        <p className="eyebrow">Premium karşılaştırma</p><h2 id="comparison-title">Programları yan yana karşılaştır</h2>
        {loading ? <div className="loading-panel">Gerçek yerleştirme verileri analiz ediliyor...</div> : null}
        {error ? <div className="form-alert"><p>{error}</p></div> : null}
        {comparison ? <><p className="premium-analysis__summary">{comparison.summary}</p><div className="premium-comparison__grid">{comparison.programs.map((program) => <ProgramColumn key={program.id} program={program} />)}</div><small className="premium-analysis__disclaimer">{comparison.disclaimer}</small></> : null}
      </section>
    </div>
  )
}

export default ProgramComparisonModal
