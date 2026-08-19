import assert from 'node:assert/strict'
import test from 'node:test'
import {
  formatCompactRank,
  formatCompactScore,
  historyValue,
} from '../src/utils/universityHistory.js'

test('dört yıllık değerleri normalize edilmiş API alanlarından okur', () => {
  const program = {
    year: 2026,
    base_rank: 1,
    rankings: { 2026: 1, 2025: 7395, 2024: 5725, 2023: null },
  }

  assert.equal(historyValue(program, 'rankings', 2026, 'base_rank'), 1)
  assert.equal(historyValue(program, 'rankings', 2024, 'base_rank'), 5725)
  assert.equal(historyValue(program, 'rankings', 2023, 'base_rank'), null)
})

test('tek yıllık yanıtlarda 2026 değerini yalnız 2026 hücresinde gösterir', () => {
  assert.equal(historyValue({ year: 2026, base_rank: 1 }, 'rankings', 2026, 'base_rank'), 1)
  assert.equal(historyValue({ year: 2026, base_rank: 1 }, 'rankings', 2025, 'base_rank'), null)
})

test('eksik değerleri çizgi, sayıları Türkçe biçimde gösterir', () => {
  assert.equal(formatCompactRank(null), '—')
  assert.equal(formatCompactRank(0), '—')
  assert.equal(formatCompactRank(-1), '—')
  assert.equal(formatCompactRank('geçersiz'), '—')
  assert.equal(formatCompactRank(12345), '12.345')
  assert.equal(formatCompactScore(null), '—')
  assert.equal(formatCompactScore(0), '—')
  assert.equal(formatCompactScore(-0.01), '—')
  assert.equal(formatCompactScore('geçersiz'), '—')
  assert.equal(formatCompactScore(509.08812), '509,088')
})
