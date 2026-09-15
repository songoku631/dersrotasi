import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = (path) => readFile(new URL(path, import.meta.url), 'utf8')

test('authentication no longer sends signed-in users to username onboarding', async () => {
  const [app, login, register] = await Promise.all([
    source('../src/App.jsx'),
    source('../src/pages/Login.jsx'),
    source('../src/pages/Register.jsx'),
  ])

  assert.match(app, /path="\/kullanici-adi" element={<Navigate replace to="\/" \/>}/)
  assert.doesNotMatch(app, /UsernameSetup/)
  assert.doesNotMatch(login, /\/kullanici-adi/)
  assert.doesNotMatch(register, /\/kullanici-adi/)
  assert.doesNotMatch(register, /saveUsername|usernamePattern/)
})

test('profile username remains optional and editable', async () => {
  const profile = await source('../src/pages/ProfilePage.jsx')

  assert.match(profile, /label="Kullanıcı adı"/)
  assert.match(profile, /pattern="\[a-z0-9_\]\{3,24\}" value=\{draft\.username \|\| ''\}/)
  assert.doesNotMatch(profile, /pattern="\[a-z0-9_\]\{3,24\}" required/)
})
