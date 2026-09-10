export function remainingSeconds(phaseEndsAt, serverNow, clientNow = Date.now()) {
  if (!phaseEndsAt) return 0
  const offset = new Date(serverNow).getTime() - clientNow
  return Math.max(0, Math.ceil((new Date(phaseEndsAt).getTime() - (clientNow + offset)) / 1000))
}

export function formatTimer(seconds) {
  const safe = Number.isFinite(Number(seconds)) ? Math.max(0, Math.ceil(Number(seconds))) : 0
  return `${String(Math.floor(safe / 60)).padStart(2, '0')}:${String(safe % 60).padStart(2, '0')}`
}

export function roomSeconds(room, elapsedMs = 0) {
  if (!room) return 0
  const initial = Number(room.remaining_seconds ?? (room.work_minutes * 60))
  const running = ['work', 'break'].includes(room.current_phase)
  return Math.max(0, Math.ceil(initial - (running ? Math.max(0, elapsedMs) / 1000 : 0)))
}

export function canSpeakInRoom(room, seconds, fresh = true) {
  return Boolean(fresh && room?.voice_enabled && room.current_phase === 'break' && seconds > 0)
}

export function validateRoom(values) {
  const errors = {}
  if (!values.name?.trim() || values.name.trim().length > 80) errors.name = 'Oda adı 1-80 karakter olmalı.'
  if (!values.category) errors.category = 'Bir kategori seç.'
  if (+values.work_minutes < 5 || +values.work_minutes > 180) errors.work_minutes = 'Çalışma süresi 5-180 dakika olmalı.'
  if (+values.break_minutes < 1 || +values.break_minutes > 60) errors.break_minutes = 'Mola süresi 1-60 dakika olmalı.'
  if (+values.max_members < 2 || +values.max_members > 50) errors.max_members = 'Kapasite 2-50 kişi olmalı.'
  if (values.password_protected) {
    if (!values.password || values.password.length < 4 || values.password.length > 64) errors.password = 'Oda şifresi 4-64 karakter olmalı.'
  }
  return errors
}

export function defaultRoomName(username, displayName) {
  const cleanUsername = String(username || '').trim().replace(/^@/, '')
  const cleanDisplayName = String(displayName || '').trim()
  const identity = cleanUsername ? `@${cleanUsername}` : (!cleanDisplayName.includes('@') ? cleanDisplayName : '')
  if (!identity) return 'Öğrenci odası'
  const lower = identity.toLocaleLowerCase('tr-TR')
  const lastVowel = [...lower].reverse().find(letter => 'aeıioöuü'.includes(letter)) || 'i'
  const harmony = { a: 'ı', ı: 'ı', o: 'u', u: 'u', e: 'i', i: 'i', ö: 'ü', ü: 'ü' }[lastVowel]
  const buffer = 'aeıioöuü'.includes(lower.at(-1)) ? 'n' : ''
  return `${identity}'${buffer}${harmony}n odası`
}

export function supportedMusicUrl(value) {
  try {
    const url = new URL(value)
    return ['youtube.com', 'www.youtube.com', 'youtu.be', 'open.spotify.com'].includes(url.hostname)
  } catch { return false }
}
