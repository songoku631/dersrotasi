import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const appSource = readFileSync(new URL('../src/App.jsx', import.meta.url), 'utf8')
const headerSource = readFileSync(new URL('../src/components/Header.jsx', import.meta.url), 'utf8')
const pomodoroPageSource = readFileSync(new URL('../src/pages/PomodoroPage.jsx', import.meta.url), 'utf8')

test('Pomodoro placeholder listesinden çıkarılıp gerçek sayfaya bağlanır', () => {
  assert.match(appSource, /tool\.path !== ['"]\/pomodoro['"]/)
  assert.match(appSource, /path=['"]\/pomodoro['"] element={<PomodoroPage\s*\/>}/)
})

test('oda oluşturma yeni sekmeyi tıklama akışında açar ve çift gönderimi engeller', () => {
  assert.match(pomodoroPageSource, /window\.open\('about:blank', '_blank'\)/)
  assert.match(pomodoroPageSource, /roomTab\.location\.replace/)
  assert.match(pomodoroPageSource, /setModal\(false\)/)
  assert.match(pomodoroPageSource, /disabled={submitting}/)
  assert.match(pomodoroPageSource, /roomTab\.close\(\)/)
})

test('şifre toggle, kart etiketi ve katılım dialogu render edilir', () => {
  assert.match(pomodoroPageSource, /checked={form\.password_protected}/)
  assert.match(pomodoroPageSource, /Şifreli/)
  assert.match(pomodoroPageSource, /Bu oda şifreli/)
  assert.match(pomodoroPageSource, /Şifreyi tekrar gir/)
})

test('Pomodoro oda detayı ve navigasyon adresleri doğrudur', () => {
  assert.match(appSource, /path=['"]\/pomodoro\/room\/:roomId['"] element={<PomodoroRoomPage\s*\/>}/)
  assert.match(headerSource, /to=['"]\/pomodoro['"]>Pomodoro Odaları/)
})
