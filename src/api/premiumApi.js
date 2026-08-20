import { apiRequest } from './client'

export function analyzePreferenceList(user, userRank, requestId, signal) {
  return apiRequest('/api/premium/preference-analysis', {
    user,
    auth: true,
    method: 'POST',
    body: { user_rank: userRank, request_id: requestId },
    signal,
  })
}

export function comparePremiumPrograms(user, programIds, userRank, requestId, signal) {
  return apiRequest('/api/premium/program-comparison', {
    user,
    auth: true,
    method: 'POST',
    body: { program_ids: programIds, user_rank: userRank, request_id: requestId },
    signal,
  })
}
