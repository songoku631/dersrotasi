import { apiRequest } from './client'
import { queryString } from './universitiesApi'

export function getPreferenceSuggestions(user, params = {}, signal) {
  return apiRequest(`/api/preference-suggestions${queryString(params)}`, {
    user, auth: true, signal,
  })
}
