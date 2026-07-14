export const YKS_YEAR = 2025

export const YKS_TESTS = {
  tyt_turkish: { label: 'Türkçe', questions: 40 },
  tyt_social: { label: 'Sosyal Bilimler', questions: 20 },
  tyt_math: { label: 'Temel Matematik', questions: 40 },
  tyt_science: { label: 'Fen Bilimleri', questions: 20 },
  ayt_math: { label: 'Matematik', questions: 40 },
  ayt_physics: { label: 'Fizik', questions: 14 },
  ayt_chemistry: { label: 'Kimya', questions: 13 },
  ayt_biology: { label: 'Biyoloji', questions: 13 },
  ayt_literature: { label: 'Türk Dili ve Edebiyatı', questions: 24 },
  ayt_history_1: { label: 'Tarih-1', questions: 10 },
  ayt_geography_1: { label: 'Coğrafya-1', questions: 6 },
  ayt_history_2: { label: 'Tarih-2', questions: 11 },
  ayt_geography_2: { label: 'Coğrafya-2', questions: 11 },
  ayt_philosophy: { label: 'Felsefe Grubu', questions: 12 },
  ayt_religion: { label: 'Din Kültürü / İlave Felsefe', questions: 6 },
  ydt_language: { label: 'Yabancı Dil', questions: 80 },
}

const tyt = ['tyt_turkish', 'tyt_social', 'tyt_math', 'tyt_science']

export const YKS_SCORE_TYPES = {
  SAY: { label: 'Sayısal', tests: [...tyt, 'ayt_math', 'ayt_physics', 'ayt_chemistry', 'ayt_biology'] },
  EA: { label: 'Eşit Ağırlık', tests: [...tyt, 'ayt_math', 'ayt_literature', 'ayt_history_1', 'ayt_geography_1'] },
  'SÖZ': { label: 'Sözel', tests: [...tyt, 'ayt_literature', 'ayt_history_1', 'ayt_geography_1', 'ayt_history_2', 'ayt_geography_2', 'ayt_philosophy', 'ayt_religion'] },
  'DİL': { label: 'Dil', tests: [...tyt, 'ydt_language'] },
  TYT: { label: 'Yalnızca TYT', tests: tyt },
}

export function liveNet(value = {}, mode = 'correct_wrong') {
  if (mode === 'net') return Number(value.net || 0)
  return Number(value.correct || 0) - Number(value.wrong || 0) / 4
}
