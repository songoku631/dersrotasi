import assert from 'node:assert/strict'
import test from 'node:test'
import {
  changeUniversitySearchParams,
  filterOptionValues,
  multiFilterValues,
  universityApiParams,
} from '../src/utils/universityFilters.js'

test('API isteğine yalnızca desteklenen ve dolu filtreleri ekler', () => {
  const params = new URLSearchParams({
    search: 'kaldırılmış genel arama',
    university: ' gazi ',
    department: '',
    city: 'ANKARA',
    tab: 'all',
    estimated_rank: '10000',
    page: '2',
  })

  assert.deepEqual(universityApiParams(params), {
    university: 'gazi',
    city: 'ANKARA',
    page: '2',
  })
})

test('eski puan türü URL değerlerini backend değerlerine normalize eder', () => {
  assert.deepEqual(
    universityApiParams(new URLSearchParams('score_type=S%C3%96Z')),
    { score_type: 'soz' },
  )
})

test('filtre değişikliğinde birinci sayfayı varsayılan kabul edip URL’den kaldırır', () => {
  const current = new URLSearchParams('city=ANKARA&page=4&sort=score_desc')
  const next = changeUniversitySearchParams(current, { department: 'mühendis', page: 1 })

  assert.equal(next.toString(), 'city=ANKARA&sort=score_desc&department=m%C3%BChendis')
})

test('boş filtreleri ve varsayılan sıralamayı URL’den kaldırır', () => {
  const current = new URLSearchParams('university=Gazi&sort=score_desc')
  const next = changeUniversitySearchParams(current, { university: ' ', sort: 'rank_asc' })

  assert.equal(next.toString(), '')
})

test('API seçeneklerini tekilleştirir ve URL’deki seçimi korur', () => {
  assert.deepEqual(filterOptionValues(['say', 'ea', 'say'], 'soz'), ['say', 'ea', 'soz'])
})

test('combobox seçimi URL yenilemesinde standart adıyla korunur', () => {
  const selected = changeUniversitySearchParams(new URLSearchParams(), {
    university: 'BOĞAZİÇİ ÜNİVERSİTESİ',
    department: 'Bilgisayar Mühendisliği',
    page: 1,
  })
  const restored = new URLSearchParams(selected.toString())

  assert.equal(restored.get('university'), 'BOĞAZİÇİ ÜNİVERSİTESİ')
  assert.equal(restored.get('department'), 'Bilgisayar Mühendisliği')
  assert.deepEqual(universityApiParams(restored), {
    university: 'BOĞAZİÇİ ÜNİVERSİTESİ',
    department: 'Bilgisayar Mühendisliği',
  })
})

test('combobox seçimi temizlenince URL ve API parametrelerinden kalkar', () => {
  const current = new URLSearchParams({ university: 'GAZİ ÜNİVERSİTESİ', city: 'ANKARA' })
  const cleared = changeUniversitySearchParams(current, { university: '', page: 1 })

  assert.equal(cleared.has('university'), false)
  assert.deepEqual(universityApiParams(cleared), { city: 'ANKARA' })
})

test('çoklu üniversite ve bölüm seçimlerini array URL parametreleriyle korur', () => {
  const selected = changeUniversitySearchParams(new URLSearchParams('page=3'), {
    university: ['GAZİ ÜNİVERSİTESİ', 'BOĞAZİÇİ ÜNİVERSİTESİ'],
    department: ['Bilgisayar Mühendisliği', 'Elektrik-Elektronik Mühendisliği'],
    page: 1,
  })
  const restored = new URLSearchParams(selected.toString())

  assert.deepEqual(multiFilterValues(restored, 'university'), [
    'GAZİ ÜNİVERSİTESİ', 'BOĞAZİÇİ ÜNİVERSİTESİ',
  ])
  assert.deepEqual(multiFilterValues(restored, 'department'), [
    'Bilgisayar Mühendisliği', 'Elektrik-Elektronik Mühendisliği',
  ])
  assert.deepEqual(universityApiParams(restored), {
    university: ['GAZİ ÜNİVERSİTESİ', 'BOĞAZİÇİ ÜNİVERSİTESİ'],
    department: ['Bilgisayar Mühendisliği', 'Elektrik-Elektronik Mühendisliği'],
  })
  assert.equal(restored.has('page'), false)
})

test('çoklu seçimden tek değer kaldırıldığında diğer değerleri korur', () => {
  const current = changeUniversitySearchParams(new URLSearchParams(), {
    university: ['GAZİ ÜNİVERSİTESİ', 'BOĞAZİÇİ ÜNİVERSİTESİ'],
  })
  const next = changeUniversitySearchParams(current, {
    university: ['BOĞAZİÇİ ÜNİVERSİTESİ'],
  })

  assert.deepEqual(multiFilterValues(next, 'university'), ['BOĞAZİÇİ ÜNİVERSİTESİ'])
})

test('tüm çoklu seçimleri temizlemek array URL parametrelerini kaldırır', () => {
  const current = changeUniversitySearchParams(new URLSearchParams(), {
    university: ['GAZİ ÜNİVERSİTESİ', 'BOĞAZİÇİ ÜNİVERSİTESİ'],
    department: ['Bilgisayar Mühendisliği'],
  })
  const cleared = changeUniversitySearchParams(current, { university: [], department: [] })

  assert.deepEqual(multiFilterValues(cleared, 'university'), [])
  assert.deepEqual(multiFilterValues(cleared, 'department'), [])
  assert.deepEqual(universityApiParams(cleared), {})
})

test('eski tekli URL parametrelerini scalar API filtresi olarak korur', () => {
  const legacy = new URLSearchParams('university=gazi&department=m%C3%BChendis')

  assert.deepEqual(multiFilterValues(legacy, 'university'), ['gazi'])
  assert.deepEqual(universityApiParams(legacy), {
    university: 'gazi',
    department: 'mühendis',
  })
})
