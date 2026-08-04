import assert from 'node:assert/strict'
import test from 'node:test'
import {
  AI_HISTORY_MAX_ITEM_LENGTH,
  AI_HISTORY_MAX_TOTAL_LENGTH,
  aiHistoryContentLength,
  buildAiHistory,
  truncateAiHistoryContent,
} from '../src/api/aiHistory.js'

test('uzun history içeriğinin başlangıcını ve sonucunu korur', () => {
  const content = `başlangıç-${'x'.repeat(2500)}-sonuç`
  const truncated = truncateAiHistoryContent(content)

  assert.equal(aiHistoryContentLength(truncated), AI_HISTORY_MAX_ITEM_LENGTH)
  assert.ok(truncated.startsWith('başlangıç-'))
  assert.ok(truncated.includes('[önceki mesaj kısaltıldı]'))
  assert.ok(truncated.endsWith('-sonuç'))
})

test('Unicode karakterlerini surrogate pair ortasında bölmez', () => {
  const truncated = truncateAiHistoryContent('😀'.repeat(2500))

  assert.equal(aiHistoryContentLength(truncated), AI_HISTORY_MAX_ITEM_LENGTH)
  assert.equal(truncated.includes('\uFFFD'), false)
})

test('en yeni mesajları backend toplam sınırı içinde tutar', () => {
  const messages = Array.from({ length: 6 }, (_, index) => ({
    role: index % 2 === 0 ? 'user' : 'assistant',
    content: `${index}-${'x'.repeat(2200)}`,
  }))
  const history = buildAiHistory(messages)
  const totalLength = history.reduce(
    (total, item) => total + aiHistoryContentLength(item.content),
    0,
  )

  assert.equal(history.length, 4)
  assert.equal(history[0].content.startsWith('2-'), true)
  assert.equal(history[3].content.startsWith('5-'), true)
  assert.equal(totalLength, AI_HISTORY_MAX_TOTAL_LENGTH)
  assert.ok(history.every(
    (item) => aiHistoryContentLength(item.content) <= AI_HISTORY_MAX_ITEM_LENGTH,
  ))
})
