import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../src/pages/PomodoroRoomPage.jsx', import.meta.url), 'utf8')
const api = readFileSync(new URL('../src/api/pomodoroApi.js', import.meta.url), 'utf8')
const css = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8')
const voice = readFileSync(new URL('../src/hooks/usePomodoroVoice.js', import.meta.url), 'utf8')

test('oda ekranı kompakt kanal, katılımcı, sohbet ve kontrol alanlarını sunar', () => {
  for (const marker of ['room-rail', 'room-stage', 'room-side', 'room-people', 'room-voice-controls', 'room-host-controls']) assert.ok(page.includes(marker), marker)
  assert.ok(page.includes('(Sen)'))
  assert.ok(page.includes('Host'))
})

test('chat cursor ile polling yapar ve gönderimi 1000 karakterle sınırlar', () => {
  assert.match(api, /messages\?after=/)
  assert.match(page, /setInterval\(poll, 2500\)/)
  assert.match(page, /maxLength=\{1000\}/)
  assert.match(page, /e\.key === ["']Enter["'] && !e\.shiftKey/)
})

test('mobilde sohbet ve üyeler drawer olarak açılır', () => {
  assert.match(css, /@media\(max-width:900px\)/)
  assert.match(css, /\.room-app--drawer \.room-side\{transform:none\}/)
  assert.ok(page.includes('room-drawer-backdrop'))
})

test('WebRTC yeni üyeleri bağlar, erken ICE adaylarını kuyruklar ve autoplay hatasını yakalar', () => {
  assert.match(voice, /pendingIce/)
  assert.match(voice, /members\s*\.filter/)
  assert.match(voice, /audio\s*\.play\(\)\s*\.catch/)
  assert.match(api, /\/api\/pomodoro\/turn-credentials/)
  assert.match(voice, /getTurnCredentials/)
  assert.match(voice, /iceServers\.current = config\.iceServers/)
  assert.match(voice, /Date\.parse\(config\?\.expiresAt\)/)
  assert.match(voice, /hasCredentialedTurnServer/)
  assert.match(voice, /Ses bağlantısı şu anda güvenli şekilde hazırlanamadı/)
  assert.doesNotMatch(voice, /credential:\s*["']/)
  assert.doesNotMatch(api, /TURN_SHARED_SECRET|TURN_SECRET/)
  assert.match(voice, /candidateType/)
  assert.match(voice, /ice-config-refreshed/)
})

test('fotoğraf chat yalnızca izinli MIME ve 5 MB istemci sınırını sunar', () => {
  assert.match(page, /image\/jpeg,image\/png,image\/webp/)
  assert.match(page, /5 \* 1024 \* 1024/)
  assert.ok(page.includes('chat-lightbox'))
  assert.match(page, /getRoomImage/)
  assert.match(page, /URL\.createObjectURL/)
})
