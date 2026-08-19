export const universityHistoryYears = [2026, 2025, 2024, 2023]

export function historyValue(program, collection, year, fallbackField) {
  const values = program?.[collection]
  if (values && Object.prototype.hasOwnProperty.call(values, String(year))) {
    return values[String(year)]
  }

  const sourceYear = Number(program?.year)
  return sourceYear === year ? program?.[fallbackField] ?? null : null
}

export function formatCompactRank(value) {
  const number = Number(value)
  return value == null || value === '' || !Number.isFinite(number) || number <= 0
    ? '—'
    : number.toLocaleString('tr-TR', { maximumFractionDigits: 0 })
}

export function formatCompactScore(value) {
  const number = Number(value)
  return value == null || value === '' || !Number.isFinite(number) || number <= 0
    ? '—'
    : number.toLocaleString('tr-TR', { maximumFractionDigits: 3 })
}

export function formatCompactQuota(value) {
  return value == null || value === '' ? '—' : Number(value).toLocaleString('tr-TR')
}
