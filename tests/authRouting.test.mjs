import assert from 'node:assert/strict'
import test from 'node:test'
import { postLoginPath } from '../src/utils/authRouting.js'

test('kullanıcı adı olmayan yeni hesabı kullanıcı adı seçimine yönlendirir', () => {
  assert.equal(postLoginPath(null), '/kullanici-adi')
  assert.equal(postLoginPath({ username: null }), '/kullanici-adi')
  assert.equal(postLoginPath({ username: '   ' }), '/kullanici-adi')
})

test('backend profilinde kullanıcı adı olan hesabı normal akışa yönlendirir', () => {
  assert.equal(postLoginPath({ username: 'deniz_2026' }), '/')
})

test('korumalı sayfadan girişe gelen kullanıcıyı kaldığı sayfaya döndürür', () => {
  assert.equal(postLoginPath({ username: 'deniz_2026' }, '/tercihlerim'), '/tercihlerim')
})

test('kullanıcı adı olan hesabı auth sayfalarına geri döndürmez', () => {
  assert.equal(postLoginPath({ username: 'deniz_2026' }, '/kullanici-adi'), '/')
})
