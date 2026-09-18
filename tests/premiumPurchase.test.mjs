import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = (path) => readFile(new URL(path, import.meta.url), 'utf8')

test('Premium purchase UI uses the backend-owned plan and an inactive payment abstraction', async () => {
  const [app, premium, checkout, payments, config, header] = await Promise.all([
    source('../src/App.jsx'),
    source('../src/pages/PremiumPage.jsx'),
    source('../src/pages/PremiumCheckoutPage.jsx'),
    source('../src/api/paymentsApi.js'),
    source('../src/config/premium.js'),
    source('../src/components/Header.jsx'),
  ])

  assert.match(app, /path="\/premium\/checkout"/)
  assert.match(app, /path="\/premium\/success"/)
  assert.match(app, /path="\/premium\/cancel"/)
  assert.match(premium, /15 \/ gün/)
  assert.match(premium, /100 \/ gün/)
  assert.match(premium, /useUserPlan\(user\)/)
  assert.match(checkout, /Güvenli Ödemeye Geç/)
  assert.match(checkout, /Iyzico Checkout Form/)
  assert.match(checkout, /PAYMENT_UNAVAILABLE_MESSAGE/)
  assert.match(payments, /IYZICO_CHECKOUT_ENDPOINT/)
  assert.match(payments, /method: 'POST'/)
  assert.doesNotMatch(payments, /fetch\(/)
  assert.match(config, /PREMIUM_PRICE = null/)
  assert.match(config, /Ödeme sistemi yakında aktif olacak\./)
  assert.match(header, /useUserPlan\(user\)/)
})
