import assert from 'node:assert/strict'
import test from 'node:test'
import { comboboxKeyAction } from '../src/utils/comboboxNavigation.js'

test('aşağı ve yukarı ok seçenekler arasında döngüsel ilerler', () => {
  assert.deepEqual(comboboxKeyAction('ArrowDown', -1, 3), { action: 'navigate', index: 0 })
  assert.deepEqual(comboboxKeyAction('ArrowDown', 2, 3), { action: 'navigate', index: 0 })
  assert.deepEqual(comboboxKeyAction('ArrowUp', 0, 3), { action: 'navigate', index: 2 })
})

test('Enter ve Space aktif checkbox seçimini değiştirir, Escape listeyi kapatır', () => {
  assert.deepEqual(comboboxKeyAction('Enter', 1, 3), { action: 'toggle', index: 1 })
  assert.deepEqual(comboboxKeyAction(' ', 2, 3), { action: 'toggle', index: 2 })
  assert.deepEqual(comboboxKeyAction('Escape', 1, 3), { action: 'close', index: -1 })
})

test('Tab varsayılan odak geçişini korur', () => {
  assert.deepEqual(comboboxKeyAction('Tab', 0, 3), { action: 'tab', index: -1 })
})
