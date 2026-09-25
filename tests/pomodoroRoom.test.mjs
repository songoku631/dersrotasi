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
  assert.match(api, /sendRoomMessage = \(user, id, message\).*method: 'POST'.*body: \{ message \}/)
  assert.match(page, /setInterval\(poll, 2500\)/)
  assert.match(page, /maxLength=\{1000\}/)
  assert.match(page, /e\.key === ["']Enter["'] && !e\.shiftKey/)
  assert.match(page, /e\.currentTarget\.form\.requestSubmit\(\)/)
  assert.match(page, /\(!message && !imageFile\) \|\| sending \|\| message\.length > 1000/)
  assert.match(page, /const item = r\.data\.message/)
  assert.match(page, /setDraft\(""\)/)
  assert.match(page, /disabled=\{\(!draft\.trim\(\) && !imageFile\) \|\| sending\}/)
})

test('mobilde sohbet ve üyeler drawer olarak açılır', () => {
  assert.match(css, /@media\(max-width:900px\)/)
  assert.match(css, /\.room-app--drawer \.room-side\{transform:none\}/)
  assert.ok(page.includes('room-drawer-backdrop'))
})

test('dar mobil ekranlarda ses kontrolleri eşit genişlikte, dokunulabilir ve safe-area içinde kalır', () => {
  assert.match(css, /@media\(max-width:560px\)/)
  assert.match(css, /grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/)
  assert.match(css, /\.room-voice-controls button\{min-width:0;min-height:44px/)
  assert.match(css, /safe-area-inset-bottom/)
  assert.match(css, /\.room-stage\{min-width:0;overflow-x:hidden/)
  assert.match(css, /\.side-member>div\{min-width:0\}/)
})

test('ses kontrol grid’i 320–430 px iPhone genişliklerinde 44 px dokunma hedefini korur', () => {
  const mobileWidths = [320, 375, 390, 430]
  const sideInset = 8 * 2
  const innerPadding = 5.6 * 2
  const gridGaps = 5.6 * 3

  for (const width of mobileWidths) {
    const controlBarWidth = width - sideInset
    const controlWidth = (controlBarWidth - innerPadding - gridGaps) / 4
    assert.ok(controlBarWidth <= width)
    assert.ok(controlWidth >= 44, `${width}px: ${controlWidth}px`)
  }
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

test('oda üyeleri ve chat profil kullanıcı adını ve güvenli profil fotoğrafı URL’sini kullanır', () => {
  assert.match(page, /import \{ profileMediaUrl \} from "\.\.\/api\/client"/)
  assert.match(page, /const roomProfile = \(person\) => \(\{ username: person\?\.username \|\| "Öğrenci" \}\)/)
  assert.match(page, /const roomProfilePhotoUrl = \(person\) => profileMediaUrl\(person\?\.profile_photo_url\)/)
  assert.equal((page.match(/profile=\{roomProfile\(/g) || []).length, 3)
  assert.equal((page.match(/profilePhotoUrl=\{roomProfilePhotoUrl\(/g) || []).length, 3)
})
