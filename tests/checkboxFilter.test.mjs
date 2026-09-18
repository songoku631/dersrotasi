import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

test('mobil odak değişiminde seçenek değişimi tamamlanana kadar paneli açık tutar', async () => {
  const source = await readFile(new URL('../src/components/CheckboxFilter.jsx', import.meta.url), 'utf8')

  assert.match(source, /function handleBlur\(\) \{[\s\S]*window\.setTimeout\(\(\) => \{[\s\S]*rootRef\.current\?\.contains\(document\.activeElement\)/)
  assert.doesNotMatch(source, /event\.relatedTarget/)
})
