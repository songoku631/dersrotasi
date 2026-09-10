import assert from 'node:assert/strict'
import test from 'node:test'
import { canSpeakInRoom, defaultRoomName, formatTimer, remainingSeconds, roomSeconds, supportedMusicUrl, validateRoom } from '../src/utils/pomodoro.js'

test('idle and paused timers preserve their duration', () => {
  assert.equal(roomSeconds({ current_phase: 'idle', work_minutes: 25 }, 90000), 1500)
  assert.equal(roomSeconds({ current_phase: 'paused', remaining_seconds: 123 }, 90000), 123)
})
test('running timer follows elapsed time after a suspended tab', () => {
  const room = { current_phase: 'work', remaining_seconds: 1500 }
  assert.equal(roomSeconds(room, 65000), 1435)
  assert.equal(roomSeconds(room, 1600000), 0)
  assert.equal(formatTimer(NaN), '00:00')
})
test('microphones are allowed only in a fresh unexpired break', () => {
  const room = { current_phase: 'break', voice_enabled: true }
  assert.equal(canSpeakInRoom(room, 20), true)
  assert.equal(canSpeakInRoom(room, 0), false)
  assert.equal(canSpeakInRoom(room, 20, false), false)
  for (const current_phase of ['idle', 'work', 'paused']) assert.equal(canSpeakInRoom({ ...room, current_phase }, 20), false)
  assert.equal(canSpeakInRoom({ ...room, voice_enabled: false }, 20), false)
})

test('server saat farkını hesaba katarak kalan süreyi hesaplar', () => {
  assert.equal(remainingSeconds('2026-09-04T12:25:00Z', '2026-09-04T12:00:00Z', Date.parse('2026-09-04T15:00:00Z')), 1500)
  assert.equal(formatTimer(1500), '25:00')
})
test('bitmiş sayaç negatif olmaz', () => assert.equal(remainingSeconds('2026-09-04T11:00:00Z', '2026-09-04T12:00:00Z'), 0))
test('oda oluşturma alanlarını doğrular', () => {
  assert.ok(validateRoom({ name:'', category:'', work_minutes:2, break_minutes:0, max_members:1 }).name)
  assert.deepEqual(validateRoom({ name:'TYT Matematik', category:'TYT', visibility:'public', work_minutes:25, break_minutes:5, max_members:12 }), {})
})
test('oda adı kullanıcı adından doğal ve güvenli fallback ile oluşturulur', () => {
  assert.equal(defaultRoomName('hayroking', 'ignored'), "@hayroking'in odası")
  assert.equal(defaultRoomName('', 'Ali'), "Ali'nin odası")
  assert.equal(defaultRoomName('', 'user@example.com'), 'Öğrenci odası')
})
test('protected rooms need only one password and enforce length limits', () => {
  const base={name:'Oda',category:'TYT',work_minutes:25,break_minutes:5,max_members:8,password_protected:true}
  for (const password of [undefined, '', '123', 'a'.repeat(65)]) {
    assert.ok(validateRoom({...base,password}).password)
  }
  for (const password of ['1234', 'valid room code', 'a'.repeat(64)]) {
    assert.deepEqual(validateRoom({...base,password}),{})
  }
  assert.deepEqual(validateRoom({...base,password_protected:false,password:''}),{})
})
test('müzik allowlist yalnızca desteklenen sağlayıcıları kabul eder', () => {
  assert.equal(supportedMusicUrl('https://youtu.be/abc'), true); assert.equal(supportedMusicUrl('https://open.spotify.com/track/abc'), true); assert.equal(supportedMusicUrl('https://evil.example/audio.mp3'), false)
})
