import { ExternalLink, Heart, ListPlus } from 'lucide-react'
import Button from '../Button'
import {
  educationLanguageLabel,
  enumLabel,
  formatNullable,
  formatRank,
  formatScore,
} from '../../utils/universityFormat'

function ProgramCard({ program, onFavorite, onPreference, busy = false, evaluation }) {
  const currentEvaluation = evaluation || program.evaluation

  return (
    <article className="program-card">
      <div className="program-card__heading">
        <div>
          <p className="eyebrow">{program.city} · {program.year}</p>
          <h2>{program.department_name}</h2>
          <p>{program.university_name}</p>
          <small>{program.faculty_name || 'Fakülte bilgisi belirtilmedi'}</small>
        </div>
        {currentEvaluation?.label ? (
          <span className={`evaluation-badge evaluation-badge--${currentEvaluation.label}`}>
            {currentEvaluation.label_text}
          </span>
        ) : null}
      </div>

      <dl className="program-card__details">
        <div><dt>Puan türü</dt><dd>{enumLabel(program.score_type)}</dd></div>
        <div><dt>Üniversite türü</dt><dd>{enumLabel(program.university_type)}</dd></div>
        <div><dt>Öğretim</dt><dd>{enumLabel(program.education_type)}</dd></div>
        <div><dt>Dil</dt><dd>{educationLanguageLabel(program.education_language)}</dd></div>
        <div><dt>Burs</dt><dd>{enumLabel(program.scholarship_type)}</dd></div>
        <div><dt>Taban sıra</dt><dd>{formatRank(program.base_rank)}</dd></div>
        <div><dt>Taban puan</dt><dd>{formatScore(program.base_score)}</dd></div>
        <div><dt>Kontenjan</dt><dd>{formatNullable(program.quota)}</dd></div>
        <div><dt>Yerleşen</dt><dd>{formatNullable(program.placed_count)}</dd></div>
        <div><dt>Süre</dt><dd>{formatNullable(program.duration_years, ' yıl')}</dd></div>
        {currentEvaluation ? <div><dt>Hedef sıra</dt><dd>{formatRank(currentEvaluation.user_rank)}</dd></div> : null}
        {currentEvaluation ? <div><dt>Yaklaşık fark</dt><dd>{currentEvaluation.difference == null ? 'Belirtilmedi' : Number(currentEvaluation.difference).toLocaleString('tr-TR')}</dd></div> : null}
      </dl>

      {currentEvaluation ? <p className="program-card__evaluation">{currentEvaluation.explanation}</p> : null}
      <p className="program-card__source">Program kaynağı: {program.source_name}</p>
      {program.rank_source_name ? <p className="program-card__source">Başarı sırası kaynağı: {program.rank_source_name}</p> : null}
      <div className="program-card__actions">
        <Button icon={Heart} variant="secondary" disabled={busy} onClick={() => onFavorite?.(program)}>
          {Number(program.is_favorite) ? 'Favoriden Çıkar' : 'Favoriye Ekle'}
        </Button>
        <Button icon={ListPlus} variant="secondary" disabled={busy} onClick={() => onPreference?.(program)}>
          Tercihlerime Ekle
        </Button>
        <Button icon={ExternalLink} to={`/universite-tercih/${program.id}`}>Detayları Gör</Button>
      </div>
    </article>
  )
}

export default ProgramCard
