const universityApiParamNames = new Set([
  'city',
  'score_type',
  'university_type',
  'education_type',
  'education_language',
  'scholarship_type',
  'year',
  'min_rank',
  'max_rank',
  'sort',
  'page',
])

const incomingScoreTypeMap = {
  SAY: 'say',
  EA: 'ea',
  'SÖZ': 'soz',
  SOZ: 'soz',
  'DİL': 'dil',
  DIL: 'dil',
  TYT: 'tyt',
}

export function normalizeScoreType(value) {
  return incomingScoreTypeMap[value] || value || ''
}

export function universityApiParams(searchParams) {
  const params = {}
  for (const name of ['university', 'department']) {
    const arrayValues = cleanValues(searchParams.getAll(`${name}[]`))
    const legacyValues = cleanValues(searchParams.getAll(name))
    if (arrayValues.length) params[name] = arrayValues
    else if (legacyValues.length === 1) params[name] = legacyValues[0]
    else if (legacyValues.length > 1) params[name] = legacyValues
  }
  for (const [name, rawValue] of searchParams.entries()) {
    if (!universityApiParamNames.has(name)) continue
    const value = String(rawValue).trim()
    if (!value) continue
    params[name] = name === 'score_type' ? normalizeScoreType(value) : value
  }
  return params
}

export function changeUniversitySearchParams(current, changes) {
  const next = new URLSearchParams(current)
  Object.entries(changes).forEach(([name, rawValue]) => {
    if (Array.isArray(rawValue)) {
      next.delete(name)
      next.delete(`${name}[]`)
      cleanValues(rawValue).forEach((value) => next.append(`${name}[]`, value))
      return
    }
    const value = rawValue === null || rawValue === undefined ? '' : String(rawValue)
    const isDefault = (name === 'page' && value === '1')
      || (name === 'sort' && value === 'rank_asc')
      || (name === 'tab' && (value === '' || value === 'all'))
    if (!value.trim() || isDefault) next.delete(name)
    else next.set(name, value)
  })
  return next
}

export function multiFilterValues(searchParams, name) {
  return cleanValues([
    ...searchParams.getAll(`${name}[]`),
    ...searchParams.getAll(name),
  ])
}

export function filterOptionValues(options, currentValue = '') {
  return [...new Set([
    ...options.map((value) => String(value).trim()).filter(Boolean),
    String(currentValue).trim(),
  ].filter(Boolean))]
}

function cleanValues(values) {
  return [...new Set(values.map((value) => String(value).trim()).filter(Boolean))]
}
