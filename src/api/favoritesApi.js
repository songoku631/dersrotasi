import { apiRequest } from './client'

export function getFavorites(user, signal) {
  return apiRequest('/api/favorites', { user, auth: true, signal })
}

export function addFavorite(user, universityId, signal) {
  return apiRequest('/api/favorites', {
    user, auth: true, method: 'POST', body: { university_id: universityId }, signal,
  })
}

export function removeFavorite(user, universityId, signal) {
  return apiRequest(`/api/favorites/${universityId}`, { user, auth: true, method: 'DELETE', signal })
}
