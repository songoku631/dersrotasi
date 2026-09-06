import assert from 'node:assert/strict'
import test from 'node:test'
import { profilePayload } from '../src/utils/profile.js'

const draft = { username: 'ogrenci_test', department: 'Bilgisayar Mühendisliği', grade_level: '2', target_department: 'Tıp', target_rank: '15000', score_type: 'sayisal', daily_study_hours: '24,0', profile_visibility: 'public', birth_year_public: 1 }

test('profile payload preserves fields and normalizes Turkish decimal hours', () => {
  const payload = profilePayload(draft)
  assert.equal(payload.daily_study_hours, 24)
  assert.equal(payload.target_rank, 15000)
  for (const key of ['department', 'grade_level', 'target_department', 'score_type', 'profile_visibility', 'birth_year_public']) assert.equal(payload[key], draft[key])
  assert.equal(profilePayload({ ...draft, daily_study_hours: '2.5' }).daily_study_hours, 2.5)
  assert.equal(profilePayload({ ...draft, daily_study_hours: 0 }).daily_study_hours, 0)
  assert.equal(profilePayload({ ...draft, daily_study_hours: '' }).daily_study_hours, null)
})
test('invalid fields produce actionable errors before sending', () => {
  assert.throws(() => profilePayload({ ...draft, username: '' }), /Kullanıcı adı gerekli/)
  assert.throws(() => profilePayload({ ...draft, target_rank: '24,0' }), /Hedef sıralama/)
  assert.throws(() => profilePayload({ ...draft, birth_year: '1800' }), /Doğum yılı/)
  for (const daily_study_hours of ['25', '-1', 'abc', '2,5,0', '1.25']) assert.throws(() => profilePayload({ ...draft, daily_study_hours }), /Günlük çalışma saati/)
})
