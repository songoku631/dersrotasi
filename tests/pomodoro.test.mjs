import assert from 'node:assert/strict'
import test from 'node:test'
import { defaultRoomName, formatTimer, remainingSeconds, supportedMusicUrl, validateRoom } from '../src/utils/pomodoro.js'

test('server saat farkını hesaba katarak kalan süreyi hesaplar', () => {
  assert.equal(remainingSeconds('2026-09-04T12:25:00Z', '2026-09-04T12:00:00Z', Date.parse('2026-09-04T15:00:00Z')), 1500)
  assert.equal(formatTimer(1500), '25:00')
})
test('bitmiş sayaç negatif olmaz', () => assert.equal(remainingSeconds('2026-09-04T11:00:00Z', '2026-09-04T12:00:00Z'), 0))
test('oda oluşturma alanlarını doğrular', () => {
  assert.ok(validateRoom({ name:'', category:'', work_minutes:2, break_minutes:0, max_members:1 }).name)
  assert.deepEqual(validateRoom({ name:'TYT Matematik', category:'TYT', visibility:'public', work_minutes:25, break_minutes:5, max_members:12 }), {})
  assert.ok(validateRoom({ name:'Şifreli', category:'AYT', password_protected:true, password:'1234', password_confirmation:'4321', work_minutes:50, break_minutes:10, max_members:8 }).password_confirmation)
})
test('oda adı kullanıcı adından doğal ve güvenli fallback ile oluşturulur', () => {
  assert.equal(defaultRoomName('hayroking', 'ignored'), "@hayroking'in odası")
  assert.equal(defaultRoomName('', 'Ali'), "Ali'nin odası")
  assert.equal(defaultRoomName('', 'user@example.com'), 'Öğrenci odası')
})
test('şifre koruması uzunluk ve eşleşme kontrolü yapar', () => {
  const base={name:'Oda',category:'TYT',work_minutes:25,break_minutes:5,max_members:8,password_protected:true}
  assert.ok(validateRoom({...base,password:'123',password_confirmation:'123'}).password)
  assert.deepEqual(validateRoom({...base,password:'güvenli',password_confirmation:'güvenli'}),{})
})
test('müzik allowlist yalnızca desteklenen sağlayıcıları kabul eder', () => {
  assert.equal(supportedMusicUrl('https://youtu.be/abc'), true); assert.equal(supportedMusicUrl('https://open.spotify.com/track/abc'), true); assert.equal(supportedMusicUrl('https://evil.example/audio.mp3'), false)
})
