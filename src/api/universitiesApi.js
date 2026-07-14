import { apiRequest } from './client'

function queryString(params = {}) {
  const query = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined && value !== false) {
      query.set(key, String(value))
    }
  })
  const value = query.toString()
  return value ? `?${value}` : ''
}

export function getUniversities(params, user, signal) {
  return apiRequest(`/api/universities${queryString(params)}`, { user, signal })
}

export function getUniversity(id, user, signal) {
  return apiRequest(`/api/universities/${id}`, { user, signal })
}

export function getUniversityFilters(signal) {
  return apiRequest('/api/universities/filters', { signal })
}

export { queryString }
