import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = (path) => readFile(new URL(path, import.meta.url), 'utf8')

const freeDailyMessageLimit =
  'Günlük ücretsiz mesaj hakkınızı kullandınız. Daha fazla mesaj için Premium plana geçebilirsiniz.'
const premiumDailyMessageLimit =
  'Bugünkü Premium AI mesaj hakkınızı kullandınız. Mesaj hakkınız günlük olarak yenilenir.'

test('AI assistant hides remaining-message usage and blocks the composer after a daily limit response', async () => {
  const [page, styles] = await Promise.all([
    source('../src/pages/AiAssistantPage.jsx'),
    source('../src/styles/global.css'),
  ])

  assert.doesNotMatch(page, /ai-assistant__quota/)
  assert.doesNotMatch(page, /requests_remaining/)
  assert.doesNotMatch(styles, /ai-assistant__quota/)
  assert.ok(page.includes(freeDailyMessageLimit))
  assert.ok(page.includes(premiumDailyMessageLimit))
  assert.match(page, /requestError\.status === 429/)
  assert.match(page, /setDailyLimitReached\(\{ plan: 'free', date: currentUsageDate\(\) \}\)/)
  assert.match(page, /setDailyLimitReached\(\{ plan: 'premium', date: currentUsageDate\(\) \}\)/)
  assert.match(page, /disabled=\{freeDailyLimitReached \|\| premiumDailyLimitReached \|\| status === 'loading'/)
  assert.match(page, /<Link to="\/premium">Premium’a Geç<\/Link>/)
})
