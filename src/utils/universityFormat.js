const labels = {
  say: 'Sayısal', ea: 'Eşit Ağırlık', soz: 'Sözel', dil: 'Dil', tyt: 'TYT',
  devlet: 'Devlet', vakif: 'Vakıf', kktc: 'KKTC', yabanci: 'Yabancı',
  orgun: 'Örgün', ikinci_ogretim: 'İkinci öğretim', uzaktan: 'Uzaktan',
  acikogretim: 'Açıköğretim', diger: 'Diğer', ucretsiz: 'Ücretsiz',
  burslu: 'Burslu', yuzde_50: '%50 burslu', yuzde_25: '%25 burslu', ucretli: 'Ücretli',
}

export function enumLabel(value) {
  return labels[value] || value || 'Belirtilmedi'
}

export function educationLanguageLabel(value) {
  const language = String(value || '').trim()
  return language || 'Türkçe'
}

export function formatRank(value) {
  return value == null ? 'Başarı sırası verisi bulunmuyor' : Number(value).toLocaleString('tr-TR')
}

export function formatScore(value) {
  return value == null ? 'Taban puanı oluşmadı' : Number(value).toLocaleString('tr-TR', { maximumFractionDigits: 5 })
}

export function formatNullable(value, suffix = '') {
  return value == null || value === '' ? 'Belirtilmedi' : `${value}${suffix}`
}
