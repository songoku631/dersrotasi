import { apiRequest } from './client'
import { authenticatedBlob } from './client'

export const listRooms = (user, filter = 'all', signal) => apiRequest(`/api/pomodoro/rooms?filter=${encodeURIComponent(filter)}`, { user, auth: true, signal })
export const createRoom = (user, body) => apiRequest('/api/pomodoro/rooms', { user, auth: true, method: 'POST', body })
export const getRoom = (user, id, signal) => apiRequest(`/api/pomodoro/rooms/${id}`, { user, auth: true, signal })
export const joinRoom = (user, id, password = '') => apiRequest(`/api/pomodoro/rooms/${id}/join`, { user, auth: true, method: 'POST', body: { password } })
export const leaveRoom = (user, id) => apiRequest(`/api/pomodoro/rooms/${id}/leave`, { user, auth: true, method: 'POST' })
export const heartbeat = (user, id, microphone_enabled = false) => apiRequest(`/api/pomodoro/rooms/${id}/heartbeat`, { user, auth: true, method: 'POST', body: { microphone_enabled } })
export const timerAction = (user, id, action, body = {}) => apiRequest(`/api/pomodoro/rooms/${id}/timer`, { user, auth: true, method: 'POST', body: { action, ...body } })
export const addMusic = (user, id, external_url, title = '') => apiRequest(`/api/pomodoro/rooms/${id}/music`, { user, auth: true, method: 'POST', body: { external_url, title } })
export const musicAction = (user, id, action) => apiRequest(`/api/pomodoro/rooms/${id}/music/action`, { user, auth: true, method: 'POST', body: { action } })
export const sendSignal = (user, id, recipient_uid, signal_type, payload) => apiRequest(`/api/pomodoro/rooms/${id}/signals`, { user, auth: true, method: 'POST', body: { recipient_uid, signal_type, payload } })
export const getSignals = (user, id, after = 0) => apiRequest(`/api/pomodoro/rooms/${id}/signals?after=${after}`, { user, auth: true })
export const moderate = (user, id, action, target_uid, reason = '') => apiRequest(`/api/pomodoro/rooms/${id}/moderation`, { user, auth: true, method: 'POST', body: { action, target_uid, reason } })
export const getRoomMessages = (user, id, after = 0, signal) => apiRequest(`/api/pomodoro/rooms/${id}/messages?after=${Math.max(0, Number(after) || 0)}`, { user, auth: true, signal })
export const sendRoomMessage = (user, id, message) => apiRequest(`/api/pomodoro/rooms/${id}/messages`, { user, auth: true, method: 'POST', body: { message } })
export const getTurnCredentials = user => apiRequest('/api/pomodoro/turn-credentials', { user, auth: true })
export const getRoomImage = (user, roomId, messageId, signal) => authenticatedBlob(user, `/api/pomodoro/rooms/${roomId}/messages/${messageId}/image`, signal)
export async function sendRoomImage(user, id, file, message = '') {
  if (!user) throw new Error('Bu işlem için giriş yapmalısın.')
  const token = await user.getIdToken(); const form = new FormData(); form.append('image', file); form.append('message', message)
  const base = String(import.meta.env.VITE_API_BASE_URL || '').trim().replace(/\/+$/, '')
  const response = await fetch(`${base}/api/pomodoro/rooms/${id}/messages/image`, { method: 'POST', body: form, headers: { Authorization: `Bearer ${token}` } })
  const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.message || 'Görsel gönderilemedi.'); return data
}
export const getPomodoroStats = (user) => apiRequest('/api/pomodoro/stats', { user, auth: true })
