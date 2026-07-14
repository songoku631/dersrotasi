const labels = {
  say: 'Sayısal', ea: 'Eşit Ağırlık', soz: 'Sözel', dil: 'Dil', tyt: 'TYT',
  devlet: 'Devlet', vakif: 'Vakıf', kktc: 'KKTC', yabanci: 'Yabancı',
  orgun: 'Örgün', ikinci_ogretim: 'İkinci öğretim', uzaktan: 'Uzaktan',
  acikogretim: 'Açıköğretim', diger: 'Diğer', ucretsiz: 'Ücretsiz',
  burslu: 'Burslu', yuzde_50: '%50 burslu', yuzde_25: '%25 burslu', ucretli: 'Ücretli',
}

const languagePatterns = [
  ['İngilizce', /(?:%\s*(?:30|100)\s*)?(?:İngilizce|Ingilizce|İngiliz|Ingiliz|English)/iu],
  ['Almanca', /(?:Almanca|German)/iu],
  ['Fransızca', /(?:Fransızca|Fransizca|Fransız|Fransiz|French)/iu],
  ['Arapça', /(?:Arapça|Arapca|Arabic)/iu],
  ['Rusça', /(?:Rusça|Rusca|Russian)/iu],
  ['İspanyolca', /(?:İspanyolca|Ispanyolca|Spanish)/iu],
  ['İtalyanca', /(?:İtalyanca|Italyanca|Italian)/iu],
  ['Çince', /(?:Çince|Cince|Chinese)/iu],
  ['Korece', /(?:Korece|Korean)/iu],
]

export function enumLabel(value) {
  return labels[value] || value || 'Belirtilmedi'
}

export function educationLanguageLabel(value, departmentName = '') {
  const language = String(value || '').trim()
  if (language) return language

  const matchingLanguage = languagePatterns.find(([, pattern]) => pattern.test(departmentName))
  return matchingLanguage?.[0] || 'Türkçe'
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
