import test from 'node:test'
import assert from 'node:assert/strict'
import { calculateNet, calculateObp, calculateYks } from '../src/utils/yksCalculator.js'
import { YKS_TESTS } from '../src/config/yksTests.js'
import { defaultScoreYear, yksYears } from '../src/config/yksYears.js'

test('net: blank inputs, quarters, negative nets and question limits', () => {
  assert.equal(calculateNet(), 0)
  assert.equal(calculateNet('30', '5', 40), 28.75)
  assert.equal(calculateNet('', '4', 40), -1)
  assert.equal(calculateNet(40, 0, 40), 40)
  for (const [correct, wrong] of [[40, 1], [-1, 0], [2.5, 1], [0, -1], ['abc', 0], [Infinity, 0]]) {
    assert.throws(() => calculateNet(correct, wrong, 40))
  }
})

test('OBP: normal, broken, decimal diploma and minimum effective grade', () => {
  assert.deepEqual(calculateObp(80), { obp: 400, contribution: 48, coefficient: 0.12 })
  assert.deepEqual(calculateObp(80, true), { obp: 400, contribution: 24, coefficient: 0.06 })
  assert.equal(calculateObp('85,5').contribution, 51.3)
  assert.equal(calculateObp('').obp, 250)
  assert.equal(calculateObp(40).contribution, 30)
  assert.equal(calculateObp(100).contribution, 60)
  assert.equal(calculateObp(100, true).contribution, 30)
  for (const grade of [-1, 101, 'abc', Infinity]) assert.throws(() => calculateObp(grade))
})

// Fixed regression vectors for the published, year-specific reference tables.
const sampleTests = Object.fromEntries(Object.entries(YKS_TESTS).map(([key, { questions }]) => [key, { correct: Math.floor(questions / 2), wrong: 2 }]))
const expectedScores = {
  2023: [['TYT', 314.905, 362.905, 0], ['SAY', 303.695, 351.695, 37], ['EA', 311.525, 359.525, 38], ['SÖZ', 306.945, 354.945, 35.5], ['DİL', 303.155, 351.155, 39.5]],
  2024: [['TYT', 316.688, 364.688, 0], ['SAY', 307.3, 355.3, 37], ['EA', 313.11, 361.11, 38], ['SÖZ', 312.455, 360.455, 35.5], ['DİL', 301.91, 349.91, 39.5]],
  2025: [['TYT', 317.055, 365.055, 0], ['SAY', 306.22, 354.22, 37], ['EA', 310.07, 358.07, 38], ['SÖZ', 302.475, 350.475, 35.5], ['DİL', 301.375, 349.375, 39.5]],
}
for (const [year, expected] of Object.entries(expectedScores)) for (const [type, raw, placement, fieldNet] of expected) {
  test(`${year} ${type}: year-specific mixed correct/wrong input and OBP contribution`, () => {
    const input = { tests: sampleTests, diplomaGrade: 80 }
    const score = calculateYks({ ...input, year: Number(year) }).scores.find(item => item.type === type)
    assert.deepEqual(score, { type, reason: '', raw, placement, fieldNet })
    const broken = calculateYks({ ...input, year: Number(year), previouslyPlaced: true }).scores.find(item => item.type === type)
    assert.equal(broken.raw, raw)
    assert.ok(Math.abs(broken.placement - (placement - 24)) < 0.00001)
  })
}

test('year selection uses the selected year table without falling back to another year', () => {
  const scores = [2023, 2024, 2025].map(year => (
    calculateYks({ year, tests: sampleTests, diplomaGrade: 80 }).scores.find(item => item.type === 'SAY').raw
  ))
  assert.deepEqual(scores, [303.695, 307.3, 306.22])
  assert.equal(new Set(scores).size, 3)
})

test('all types at maximum nets stay within raw and placement bounds', () => {
  const tests = Object.fromEntries(Object.entries(YKS_TESTS).map(([key, { questions }]) => [key, { correct: questions }]))
  for (const item of calculateYks({ tests, diplomaGrade: 100 }).scores) {
    assert.equal(item.raw, 500)
    assert.equal(item.placement, 560)
  }
})

test('blank exam has no eligible scores; TYT alone does not invent AYT/YDT scores', () => {
  assert.ok(calculateYks().scores.every(item => item.raw === null && item.placement === null && item.reason))
  const result = calculateYks({ tests: { tyt_turkish: { correct: 1, wrong: 2 } } })
  assert.equal(result.nets.tyt_turkish, 0.5)
  assert.ok(result.scores[0].raw > 100)
  assert.ok(result.scores.slice(1).every(item => item.raw === null))
})

test('eligibility uses combined AYT tests, including negative nets', () => {
  const tests = { tyt_math: { correct: 1 }, ayt_physics: { correct: 1 }, ayt_chemistry: { wrong: 4 }, ayt_literature: { correct: 1 }, ayt_history_1: { wrong: 4 } }
  const result = calculateYks({ tests })
  assert.ok(result.scores.filter(item => ['SAY', 'EA', 'SÖZ'].includes(item.type)).every(item => item.reason))
})

test('shared AYT math contributes to SAY and EA, not SOZ or DIL; totals count it once', () => {
  const base = calculateYks({ tests: sampleTests })
  const moreMath = calculateYks({ tests: { ...sampleTests, ayt_math: { correct: 21, wrong: 2 } } })
  assert.ok(moreMath.scores[1].raw > base.scores[1].raw)
  assert.ok(moreMath.scores[2].raw > base.scores[2].raw)
  assert.equal(moreMath.scores[3].raw, base.scores[3].raw)
  assert.equal(moreMath.scores[4].raw, base.scores[4].raw)
  assert.deepEqual(base.totals, { tyt: 58, ayt: 72.5, ydt: 39.5 })
  assert.equal(moreMath.totals.ayt, 73.5)
})

test('unsupported years and invalid hidden subject values are rejected', () => {
  assert.throws(() => calculateYks({ year: 2026 }))
  assert.throws(() => calculateYks({ tests: { ayt_history_2: { correct: 12 } } }))
})

test('year catalog separates available ranking data from score coefficients', () => {
  assert.deepEqual(yksYears.map(item => item.year), [2026, 2025, 2024, 2023])
  assert.deepEqual(yksYears.filter(item => item.score.enabled).map(item => item.year), [2025, 2024, 2023])
  assert.equal(defaultScoreYear, 2025)
  assert.ok(yksYears.every(item => item.rank.datasetYear === item.year))
  assert.equal(calculateYks().scoreYear, 2025)
  assert.equal(calculateYks().scoreStatus, 'reference')
})
