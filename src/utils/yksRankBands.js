export function officialBandPreferenceUrl(result) {
  if (!result || result.score_kind !== 'placement') return ''
  const currentYear = result.years?.find((item) => item.year === 2025)
  if (!currentYear || currentYear.status !== 'band') return ''

  const params = new URLSearchParams({
    score_type: result.score_type,
    min_rank: String(currentYear.rank_min),
    max_rank: String(currentYear.rank_max),
    rank_source: 'official_osym_band',
  })
  return `/universite-tercih?${params.toString()}`
}

export function formatOfficialRank(value) {
  if (!Number.isFinite(Number(value))) return '—'
  return new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 }).format(Number(value))
}

export function formatOfficialScore(value) {
  if (!Number.isFinite(Number(value))) return '—'
  return new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 5 }).format(Number(value))
}
