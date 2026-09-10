import { yksCoefficients } from '../config/yksCoefficients.js'
import { YKS_TESTS, YKS_SCORE_TYPES } from '../config/yksTests.js'

export const scoreOrder = ['TYT', 'SAY', 'EA', 'SÖZ', 'DİL']
const sum = values => values.reduce((total, value) => total + value, 0)
const round = value => Math.round((value + Number.EPSILON) * 1000) / 1000

export function calculateNet(correct = '', wrong = '', questions = 80) {
  const c = Number(correct), w = Number(wrong)
  if (!Number.isInteger(c) || !Number.isInteger(w) || c < 0 || w < 0 || c + w > questions) {
    throw new Error(`Doğru ve yanlış, toplamı ${questions} soruyu aşmayan pozitif tam sayılar veya 0 olmalı.`)
  }
  return c - w / 4
}

export function calculateObp(grade = '', previouslyPlaced = false, year = 2025) {
  const rule = yksCoefficients[year]?.obp
  if (!rule) throw new Error('Bu hesaplama yılı desteklenmiyor.')
  const diploma = Number(String(grade).replace(',', '.'))
  if (!Number.isFinite(diploma) || diploma < 0 || diploma > 100) throw new Error('Diploma notu 0–100 arasında olmalı.')
  const obp = Math.max(rule.minimum_effective_diploma_grade, diploma) * rule.multiplier
  const coefficient = previouslyPlaced ? rule.previously_placed_coefficient : rule.placement_coefficient
  return { obp, contribution: round(obp * coefficient), coefficient }
}

function eligibility(type, nets) {
  if (nets.tyt_turkish < 0.5 && nets.tyt_math < 0.5) return 'TYT Türkçe veya Matematik testinden en az 0,5 net gerekli.'
  const literature = sum(['ayt_literature', 'ayt_history_1', 'ayt_geography_1'].map(key => nets[key]))
  const science = sum(['ayt_physics', 'ayt_chemistry', 'ayt_biology'].map(key => nets[key]))
  const social2 = sum(['ayt_history_2', 'ayt_geography_2', 'ayt_philosophy', 'ayt_religion'].map(key => nets[key]))
  if (type === 'SAY' && nets.ayt_math < 0.5 && science < 0.5) return 'AYT Matematik veya Fen Bilimleri testinden en az 0,5 net gerekli.'
  if (type === 'EA' && nets.ayt_math < 0.5 && literature < 0.5) return 'AYT Matematik veya Edebiyat–Sosyal Bilimler-1 testinden en az 0,5 net gerekli.'
  if (type === 'SÖZ' && literature < 0.5 && social2 < 0.5) return 'AYT Edebiyat–Sosyal Bilimler-1 veya Sosyal Bilimler-2 testinden en az 0,5 net gerekli.'
  if (type === 'DİL' && nets.ydt_language < 0.5) return 'YDT testinden en az 0,5 net gerekli.'
  return ''
}

export function calculateYks({ year = 2025, tests = {}, diplomaGrade = '', previouslyPlaced = false } = {}) {
  const config = yksCoefficients[year]
  if (!config?.calibration) throw new Error('Bu hesaplama yılı desteklenmiyor.')
  const nets = Object.fromEntries(Object.entries(YKS_TESTS).map(([key, test]) => {
    try { return [key, calculateNet(tests[key]?.correct, tests[key]?.wrong, test.questions)] }
    catch (error) { throw new Error(`${key.startsWith('tyt') ? 'TYT' : key.startsWith('ayt') ? 'AYT' : 'YDT'} ${test.label}: ${error.message}`) }
  }))
  const obp = calculateObp(diplomaGrade, previouslyPlaced, year)
  const scores = scoreOrder.map(type => {
    const reason = eligibility(type, nets)
    const { coefficients, intercepts } = config.calibration
    const raw = Math.min(500, Math.max(100, intercepts[type] + sum(Object.entries(coefficients[type]).map(([key, coefficient]) => nets[key] * coefficient))))
    return { type, reason, raw: reason ? null : round(raw), placement: reason ? null : round(raw + obp.contribution),
      fieldNet: sum(YKS_SCORE_TYPES[type].tests.filter(key => !key.startsWith('tyt')).map(key => nets[key])) }
  })
  return { year: Number(year), scoreYear: Number(year), scoreStatus: config.status, nets, obp, scores,
    totals: Object.fromEntries(['tyt', 'ayt', 'ydt'].map(prefix => [prefix, sum(Object.entries(nets).filter(([key]) => key.startsWith(prefix)).map(([, net]) => net))])) }
}
