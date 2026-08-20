import { apiRequest } from './client'

export function listAiConversations(user, signal) {
  return apiRequest('/api/ai/conversations', { user, auth: true, signal })
}

export function createAiConversation(user, signal) {
  return apiRequest('/api/ai/conversations', {
    user,
    auth: true,
    method: 'POST',
    signal,
  })
}

export function getAiConversation(user, conversationId, signal) {
  return apiRequest(`/api/ai/conversations/${conversationId}`, {
    user,
    auth: true,
    signal,
  })
}

export function sendAiMessage(user, conversationId, message, history, requestId, signal) {
  return apiRequest('/api/ai/chat', {
    user,
    auth: true,
    method: 'POST',
    body: {
      conversation_id: conversationId,
      message,
      history,
      request_id: requestId,
    },
    signal,
  })
}
