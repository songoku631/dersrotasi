import assert from 'node:assert/strict'
import test from 'node:test'
import { formatRank, formatScore } from '../src/utils/universityFormat.js'

test('geçersiz başarı sıralarını veri yok olarak gösterir', () => {
  assert.equal(formatRank(null), 'Başarı sırası verisi bulunmuyor')
  assert.equal(formatRank(0), 'Başarı sırası verisi bulunmuyor')
  assert.equal(formatRank(-1), 'Başarı sırası verisi bulunmuyor')
  assert.equal(formatRank('geçersiz'), 'Başarı sırası verisi bulunmuyor')
  assert.equal(formatRank(12345), '12.345')
})

test('NULL, sıfır ve geçersiz taban puanlarını çizgi olarak gösterir', () => {
  assert.equal(formatScore(null), '—')
  assert.equal(formatScore(0), '—')
  assert.equal(formatScore(-0.01), '—')
  assert.equal(formatScore('geçersiz'), '—')
  assert.equal(formatScore(509.08812), '509,08812')
})
