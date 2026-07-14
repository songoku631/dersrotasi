import { apiRequest } from './client'

export function getPreferences(user, signal) {
  return apiRequest('/api/preferences', { user, auth: true, signal })
}

export function addPreference(user, universityId, note = '', signal) {
  return apiRequest('/api/preferences', {
    user, auth: true, method: 'POST', body: { university_id: universityId, note }, signal,
  })
}

export function updatePreferenceNote(user, universityId, note, signal) {
  return apiRequest(`/api/preferences/${universityId}`, {
    user, auth: true, method: 'PUT', body: { note }, signal,
  })
}

export function reorderPreferences(user, items, signal) {
  return apiRequest('/api/preferences/reorder', {
    user, auth: true, method: 'PUT', body: { items }, signal,
  })
}

export function removePreference(user, universityId, signal) {
  return apiRequest(`/api/preferences/${universityId}`, {
    user, auth: true, method: 'DELETE', signal,
  })
}
