const multiValueParamNames = [
  'university',
  'department',
  'city',
  'score_type',
  'university_type',
  'education_type',
  'education_language',
  'scholarship_type',
  'year',
]

const multiValueParamNameSet = new Set(multiValueParamNames)

const universityApiParamNames = new Set([
  'min_rank',
  'max_rank',
  'sort',
  'page',
])

export const defaultUniversitySort = 'rank_2026_asc'

export const universitySortOptions = [
  { value: defaultUniversitySort, label: 'En iyi sıralamadan en kötüye' },
  { value: 'rank_2026_desc', label: 'En kötü sıralamadan en iyiye' },
]

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

export function normalizeUniversitySort(value) {
  return value === 'rank_2026_desc' ? 'rank_2026_desc' : defaultUniversitySort
}

export function universityRankFilterLabels() {
  return {
    min: '2026 sıra min.',
    max: '2026 sıra maks.',
  }
}

export function universityApiParams(searchParams) {
  const params = {}
  for (const name of multiValueParamNames) {
    const arrayValues = cleanValues(searchParams.getAll(`${name}[]`))
    const legacyValues = cleanValues(searchParams.getAll(name))
    const usesArraySyntax = arrayValues.length > 0
    let values = cleanValues([...arrayValues, ...legacyValues])
    if (name === 'score_type') {
      values = cleanValues(values.map(normalizeScoreType))
    }
    if (values.length === 0) continue
    if (usesArraySyntax || values.length > 1) params[name] = values
    else params[name] = values[0]
  }
  for (const [name, rawValue] of searchParams.entries()) {
    if (multiValueParamNameSet.has(name.replace(/\[\]$/, ''))) continue
    if (!universityApiParamNames.has(name)) continue
    const value = String(rawValue).trim()
    if (!value) continue
    params[name] = name === 'sort' ? normalizeUniversitySort(value) : value
  }
  params.sort = normalizeUniversitySort(params.sort)
  return params
}

export function changeUniversitySearchParams(current, changes) {
  const next = new URLSearchParams(current)
  if (next.has('sort')) {
    const currentSort = normalizeUniversitySort(next.get('sort'))
    if (currentSort === defaultUniversitySort) next.delete('sort')
    else next.set('sort', currentSort)
  }
  Object.entries(changes).forEach(([name, rawValue]) => {
    if (Array.isArray(rawValue)) {
      next.delete(name)
      next.delete(`${name}[]`)
      cleanValues(rawValue).forEach((value) => next.append(`${name}[]`, value))
      return
    }
    const rawString = rawValue === null || rawValue === undefined ? '' : String(rawValue)
    const value = name === 'sort' ? normalizeUniversitySort(rawString) : rawString
    const isDefault = (name === 'page' && value === '1')
      || (name === 'sort' && value === defaultUniversitySort)
      || (name === 'tab' && (value === '' || value === 'all'))
    if (!value.trim() || isDefault) {
      next.delete(name)
      next.delete(`${name}[]`)
    } else {
      next.delete(`${name}[]`)
      next.set(name, value)
    }
  })
  return next
}

export function multiFilterValues(searchParams, name) {
  return cleanValues([
    ...searchParams.getAll(`${name}[]`),
    ...searchParams.getAll(name),
  ])
}

export function filterOptionValues(options, currentValues = []) {
  const selected = Array.isArray(currentValues) ? currentValues : [currentValues]
  return cleanValues([...options, ...selected])
}

function cleanValues(values) {
  return [...new Set(values.map((value) => String(value).trim()).filter(Boolean))]
}
