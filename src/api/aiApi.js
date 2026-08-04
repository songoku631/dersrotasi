import { apiRequest } from './client'

export function sendAiMessage(user, message, history, requestId, signal) {
  return apiRequest('/api/ai/chat', {
    user,
    auth: true,
    method: 'POST',
    body: { message, history, request_id: requestId },
    signal,
  })
}
