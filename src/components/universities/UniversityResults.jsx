import { ExternalLink, Heart, ListPlus } from 'lucide-react'
import { Link } from 'react-router-dom'
import { educationLanguageLabel, enumLabel } from '../../utils/universityFormat'
import {
  formatCompactQuota,
  formatCompactRank,
  formatCompactScore,
  historyValue,
  universityHistoryYears,
} from '../../utils/universityHistory'

function FavoriteButton({ program, busy, onFavorite }) {
  const favorite = Boolean(Number(program.is_favorite))
  return (
    <button
      className={favorite ? 'university-icon-button favorite' : 'university-icon-button'}
      type="button"
      disabled={busy}
      aria-label={favorite ? 'Favorilerden çıkar' : 'Favorilere ekle'}
      title={favorite ? 'Favorilerden çıkar' : 'Favorilere ekle'}
      onClick={() => onFavorite?.(program)}
    >
      <Heart aria-hidden="true" size={17} fill={favorite ? 'currentColor' : 'none'} />
    </button>
  )
}

function RowActions({ program, busy, onPreference }) {
  return (
    <div className="university-row-actions">
      <button
        className="university-icon-button"
        type="button"
        disabled={busy}
        aria-label="Tercih listeme ekle"
        title="Tercih listeme ekle"
        onClick={() => onPreference?.(program)}
      >
        <ListPlus aria-hidden="true" size={17} />
      </button>
      <Link
        className="university-icon-button university-icon-button--link"
        to={`/universite-tercih/${program.id}`}
        aria-label="Program detaylarını gör"
        title="Program detaylarını gör"
      >
        <ExternalLink aria-hidden="true" size={17} />
      </Link>
    </div>
  )
}

function EducationSummary({ program }) {
  return (
    <div className="university-education-summary">
      <span>{enumLabel(program.education_type)}</span>
      <span>{educationLanguageLabel(program.education_language)}</span>
      <span>{enumLabel(program.scholarship_type)}</span>
    </div>
  )
}

function DesktopTable({ programs, busyId, onFavorite, onPreference }) {
  return (
    <div className="university-table-scroll">
      <table className="university-results-table">
        <caption className="visually-hidden">Üniversite programlarının 2026, 2025, 2024 ve 2023 karşılaştırması</caption>
        <thead>
          <tr>
            <th aria-label="Favori" />
            <th>Üniversite</th>
            <th>Bölüm / Program</th>
            <th>Puan</th>
            <th>Öğretim / Dil / Burs</th>
            <th className="university-year-heading university-year-heading--current"><span>2026</span><small>Sıra</small></th>
            <th className="university-year-heading"><span>2025</span><small>Sıra</small></th>
            <th className="university-year-heading"><span>2024</span><small>Sıra</small></th>
            <th className="university-year-heading"><span>2023</span><small>Sıra</small></th>
            <th className="university-year-heading university-year-heading--current"><span>2026</span><small>Puan</small></th>
            <th className="university-year-heading"><span>2025</span><small>Puan</small></th>
            <th className="university-year-heading"><span>2024</span><small>Puan</small></th>
            <th className="university-year-heading"><span>2023</span><small>Puan</small></th>
            <th>Kont.</th>
            <th aria-label="İşlemler" />
          </tr>
        </thead>
        <tbody>
          {programs.map((program) => {
            const busy = busyId === program.id
            return (
              <tr key={program.id}>
                <td><FavoriteButton program={program} busy={busy} onFavorite={onFavorite} /></td>
                <td className="university-name-cell">
                  <strong>{program.university_name}</strong>
                  <small>{program.city} · {enumLabel(program.university_type)}</small>
                </td>
                <td className="university-program-cell">
                  <strong>{program.department_name}</strong>
                  <small className="university-faculty">
                    {program.faculty_name || 'Fakülte belirtilmedi'}
                  </small>
                  <small className="university-program-code">{program.program_code}</small>
                </td>
                <td><span className="university-score-type">{enumLabel(program.score_type)}</span></td>
                <td><EducationSummary program={program} /></td>
                {universityHistoryYears.map((year) => (
                  <td className={year === 2026 ? 'university-number university-number--current' : 'university-number'} key={`rank-${year}`}>
                    {formatCompactRank(historyValue(program, 'rankings', year, 'base_rank'))}
                  </td>
                ))}
                {universityHistoryYears.map((year) => (
                  <td className={year === 2026 ? 'university-number university-number--current' : 'university-number'} key={`score-${year}`}>
                    {formatCompactScore(historyValue(program, 'scores', year, 'base_score'))}
                  </td>
                ))}
                <td className="university-number">{formatCompactQuota(historyValue(program, 'quotas', 2026, 'quota'))}</td>
                <td><RowActions program={program} busy={busy} onPreference={onPreference} /></td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function MobileCards({ programs, busyId, onFavorite, onPreference }) {
  return (
    <div className="university-mobile-results">
      {programs.map((program) => {
        const busy = busyId === program.id
        return (
          <article className="university-mobile-card" key={program.id}>
            <header>
              <div>
                <p>{program.university_name}</p>
                <h2>{program.department_name}</h2>
                <small className="university-faculty">
                  {program.faculty_name || 'Fakülte belirtilmedi'}
                </small>
                <small className="university-mobile-meta">
                  {program.city} · {enumLabel(program.score_type)} · {program.program_code}
                </small>
              </div>
              <FavoriteButton program={program} busy={busy} onFavorite={onFavorite} />
            </header>
            <EducationSummary program={program} />
            <div className="university-mobile-history">
              {universityHistoryYears.map((year) => (
                <div className={year === 2026 ? 'current' : ''} key={year}>
                  <strong>{year}</strong>
                  <span><small>Sıra</small>{formatCompactRank(historyValue(program, 'rankings', year, 'base_rank'))}</span>
                  <span><small>Puan</small>{formatCompactScore(historyValue(program, 'scores', year, 'base_score'))}</span>
                </div>
              ))}
            </div>
            <footer>
              <span>2026 kontenjanı: <strong>{formatCompactQuota(historyValue(program, 'quotas', 2026, 'quota'))}</strong></span>
              <RowActions program={program} busy={busy} onPreference={onPreference} />
            </footer>
          </article>
        )
      })}
    </div>
  )
}

function UniversityResults({ programs, busyId, onFavorite, onPreference }) {
  return (
    <div className="university-results">
      <DesktopTable programs={programs} busyId={busyId} onFavorite={onFavorite} onPreference={onPreference} />
      <MobileCards programs={programs} busyId={busyId} onFavorite={onFavorite} onPreference={onPreference} />
    </div>
  )
}

export default UniversityResults
