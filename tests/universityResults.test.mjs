import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const resultsSource = await readFile(
  new URL('../src/components/universities/UniversityResults.jsx', import.meta.url),
  'utf8',
)

test('üniversite tablosundaki yıl başlıkları statiktir', () => {
  assert.doesNotMatch(resultsSource, /SortableHeading|ArrowUpDown|onSort|university-sort/)
  assert.doesNotMatch(resultsSource, /<th[^>]*onClick=/)
  assert.match(resultsSource, /<span>2025<\/span><small>Sıra<\/small>/)
  assert.match(resultsSource, /<span>2024<\/span><small>Sıra<\/small>/)
  assert.match(resultsSource, /<span>2023<\/span><small>Sıra<\/small>/)
})

test('historical 2023 ve 2024 sıra ile puan verileri tabloda kalır', () => {
  assert.match(resultsSource, /universityHistoryYears\.map\(\(year\) => \(/)
  assert.match(resultsSource, /historyValue\(program, 'rankings', year, 'base_rank'\)/)
  assert.match(resultsSource, /historyValue\(program, 'scores', year, 'base_score'\)/)
})
