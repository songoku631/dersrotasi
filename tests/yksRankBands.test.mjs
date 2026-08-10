import test from 'node:test'
import assert from 'node:assert/strict'
import { officialBandPreferenceUrl } from '../src/utils/yksRankBands.js'

test('resmî 2025 yerleştirme bandını tercih filtrelerine min/max olarak taşır', () => {
  const url = officialBandPreferenceUrl({
    score_type: 'SAY',
    score_kind: 'placement',
    years: [
      { year: 2025, status: 'band', rank_min: 46143, rank_max: 65449 },
      { year: 2024, status: 'band', rank_min: 38579, rank_max: 54307 },
    ],
  })
  const parsed = new URL(url, 'https://dersrotasi.example')

  assert.equal(parsed.pathname, '/universite-tercih')
  assert.equal(parsed.searchParams.get('score_type'), 'SAY')
  assert.equal(parsed.searchParams.get('min_rank'), '46143')
  assert.equal(parsed.searchParams.get('max_rank'), '65449')
  assert.equal(parsed.searchParams.get('rank_source'), 'official_osym_band')
  assert.equal(parsed.searchParams.has('estimated_rank'), false)
})

test('sınav puanı veya çözümlenemeyen band için tercih bağlantısı üretmez', () => {
  assert.equal(officialBandPreferenceUrl({ score_kind: 'exam', years: [] }), '')
  assert.equal(officialBandPreferenceUrl({
    score_type: 'EA',
    score_kind: 'placement',
    years: [{ year: 2025, status: 'insufficient_resolution', rank_min: null, rank_max: null }],
  }), '')
})
