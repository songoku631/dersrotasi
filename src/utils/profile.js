export function profilePayload(draft) {
  const payload = { ...draft }
  const username = String(draft.username || '').trim().toLowerCase()
  if (!/^[a-z0-9_]{3,24}$/.test(username)) {
    throw new Error('Kullanıcı adı gerekli: 3–24 karakter, küçük harf, rakam veya alt çizgi kullan.')
  }
  payload.username = username
  for (const [key, label, min, max] of [
    ['birth_year', 'Doğum yılı', 1900, new Date().getFullYear()],
    ['target_rank', 'Hedef sıralama', 1, 4294967295],
  ]) {
    const value = String(draft[key] ?? '').trim()
    if (!value) { payload[key] = null; continue }
    if (!/^\d+$/.test(value) || Number(value) < min || Number(value) > max) {
      throw new Error(`${label} ${min}–${max} arasında bir tam sayı olmalı.`)
    }
    payload[key] = Number(value)
  }
  const hours = String(draft.daily_study_hours ?? '').trim().replace(',', '.')
  if (hours && (!/^\d+(?:\.\d)?$/.test(hours) || Number(hours) > 24)) {
    throw new Error('Günlük çalışma saati 0–24 arasında olmalı; örneğin 2,5 veya 2.5 yaz.')
  }
  payload.daily_study_hours = hours ? Number(hours) : null
  return payload
}
