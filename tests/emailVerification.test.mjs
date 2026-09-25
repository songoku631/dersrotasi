import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  EMAIL_VERIFICATION_COOLDOWN_SECONDS,
  requiresEmailVerification,
} from '../src/utils/emailVerification.js'

const authContext = readFileSync(new URL('../src/context/AuthContext.jsx', import.meta.url), 'utf8')
const app = readFileSync(new URL('../src/App.jsx', import.meta.url), 'utf8')
const protectedRoute = readFileSync(new URL('../src/components/auth/ProtectedRoute.jsx', import.meta.url), 'utf8')
const verificationPage = readFileSync(new URL('../src/pages/EmailVerificationPage.jsx', import.meta.url), 'utf8')

test('yalnızca doğrulanmamış email/password kullanıcıları doğrulama ister', () => {
  assert.equal(requiresEmailVerification(null), false)
  assert.equal(requiresEmailVerification({ emailVerified: true, providerData: [{ providerId: 'password' }] }), false)
  assert.equal(requiresEmailVerification({ emailVerified: false, providerData: [{ providerId: 'password' }] }), true)
  assert.equal(requiresEmailVerification({ emailVerified: false, providerData: [{ providerId: 'google.com' }] }), false)
})

test('doğrulama e-postası gönderilir, kontrol reload ve zorunlu guard bulunur', () => {
  assert.equal(EMAIL_VERIFICATION_COOLDOWN_SECONDS, 60)
  assert.match(authContext, /sendEmailVerification/)
  assert.match(authContext, /createUserWithEmailAndPassword[\s\S]*sendEmailVerification/)
  assert.match(authContext, /onIdTokenChanged/)
  assert.match(authContext, /await currentUser\.reload\(\)/)
  assert.match(authContext, /getIdToken\(true\)/)
  assert.match(app, /path="\/eposta-dogrula"/)
  assert.match(app, /<Route index element=\{<Home \/>\} \/>/)
  assert.match(protectedRoute, /emailVerificationRequired/)
  assert.match(verificationPage, /localStorage/)
  assert.match(verificationPage, /actionInFlight/)
  assert.match(verificationPage, /Tekrar gönder/)
  assert.match(verificationPage, /Çıkış yap/)
})
