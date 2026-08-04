import { apiRequest } from './client'

export function sendAiMessage(user, message, history, signal) {
  return apiRequest('/api/ai/chat', {
    user,
    auth: true,
    method: 'POST',
    body: { message, history },
    signal,
  })
}
