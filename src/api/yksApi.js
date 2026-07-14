import { apiRequest } from './client'

export function estimateYks(input, signal) {
  return apiRequest('/api/yks/estimate', { method: 'POST', body: input, signal })
}

export function saveYksEstimate(user, input, signal) {
  return apiRequest('/api/yks/estimates', {
    user,
    auth: true,
    method: 'POST',
    body: input,
    signal,
  })
}

export function getYksEstimates(user, signal) {
  return apiRequest('/api/yks/estimates', { user, auth: true, signal })
}
