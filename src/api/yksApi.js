import { apiRequest } from './client'

export function compareOfficialYksRankBands(input, signal) {
  return apiRequest('/api/yks/rank-band', { method: 'POST', body: input, signal })
}
