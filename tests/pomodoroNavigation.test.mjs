import assert from 'node:assert/strict'
import test from 'node:test'
import { cancelRoomNavigation, finishRoomNavigation, prepareRoomNavigation, roomPath } from '../src/utils/pomodoroNavigation.js'

function fakeWindow({ mobile = false, popup = null } = {}) {
  return {
    location: { origin: 'https://dersrotasi.com' },
    matchMedia: () => ({ matches: mobile }),
    open: () => popup,
  }
}

test('mobile room navigation stays in the current tab without asking for a popup', () => {
  let opened = false
  const browser = { ...fakeWindow({ mobile: true }), open: () => { opened = true; return null } }
  const destination = prepareRoomNavigation(42, browser)
  const navigated = []
  finishRoomNavigation(destination, path => navigated.push(path), browser)
  assert.equal(opened, false)
  assert.deepEqual(destination, { path: '/pomodoro/room/42', tab: null })
  assert.deepEqual(navigated, ['/pomodoro/room/42'])
})

test('desktop opens a user-initiated room tab and navigates it only after joining succeeds', () => {
  const tab = { opener: 'parent', location: { replace: url => { tab.url = url } }, close: () => { tab.closed = true } }
  const browser = fakeWindow({ popup: tab })
  const destination = prepareRoomNavigation('abc', browser)
  finishRoomNavigation(destination, () => assert.fail('same-tab navigation should not run'), browser)
  assert.equal(tab.opener, null)
  assert.equal(tab.url, 'https://dersrotasi.com/pomodoro/room/abc')
})

test('blocked desktop popup safely falls back to same-tab navigation and failed joins close a reserved tab', () => {
  const blocked = prepareRoomNavigation(7, fakeWindow())
  const navigated = []
  finishRoomNavigation(blocked, path => navigated.push(path), fakeWindow())
  assert.deepEqual(navigated, ['/pomodoro/room/7'])
  const tab = { close: () => { tab.closed = true } }
  cancelRoomNavigation({ path: roomPath(8), tab })
  assert.equal(tab.closed, true)
})
