import assert from 'node:assert/strict'
import test from 'node:test'
import {
  changeUniversitySearchParams,
  defaultUniversitySort,
  filterOptionValues,
  multiFilterValues,
  normalizeUniversitySort,
  universityApiParams,
  universityRankFilterLabels,
  universitySortOptions,
} from '../src/utils/universityFilters.js'

test('yalnızca iki adet 2025 başarı sırası seçeneği sunar', () => {
  assert.equal(defaultUniversitySort, 'rank_2025_asc')
  assert.deepEqual(universitySortOptions, [
    { value: 'rank_2025_asc', label: 'En iyi sıralamadan en kötüye' },
    { value: 'rank_2025_desc', label: 'En kötü sıralamadan en iyiye' },
  ])
  assert.deepEqual(universityRankFilterLabels(), {
    min: '2025 sıra min.',
    max: '2025 sıra maks.',
  })
})

test('eski ve geçersiz sıralamaları 2025 artana normalize eder', () => {
  assert.equal(normalizeUniversitySort('rank_2025_asc'), 'rank_2025_asc')
  assert.equal(normalizeUniversitySort('rank_2025_desc'), 'rank_2025_desc')
  assert.equal(normalizeUniversitySort('rank_2024_desc'), 'rank_2025_asc')
  assert.equal(normalizeUniversitySort('rank_2023_asc'), 'rank_2025_asc')
  assert.equal(normalizeUniversitySort('score_2025_desc'), 'rank_2025_asc')
  assert.equal(normalizeUniversitySort(''), 'rank_2025_asc')
})

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
    sort: 'rank_2025_asc',
  })
})

test('eski puan türü URL değerlerini backend değerlerine normalize eder', () => {
  assert.deepEqual(
    universityApiParams(new URLSearchParams('score_type=S%C3%96Z')),
    { score_type: 'soz', sort: 'rank_2025_asc' },
  )
})

test('filtre değişikliğinde eski sort değerini ve varsayılan birinci sayfayı URL’den kaldırır', () => {
  const current = new URLSearchParams('city=ANKARA&page=4&sort=score_desc')
  const next = changeUniversitySearchParams(current, { department: 'mühendis', page: 1 })

  assert.equal(next.toString(), 'city=ANKARA&department=m%C3%BChendis')
})

test('dropdown yalnız 2025 artan ve azalan query değerleri arasında geçiş yapar', () => {
  const descending = changeUniversitySearchParams(new URLSearchParams(), {
    sort: 'rank_2025_desc', page: 1,
  })
  assert.equal(descending.toString(), 'sort=rank_2025_desc')
  assert.equal(universityApiParams(descending).sort, 'rank_2025_desc')

  const ascending = changeUniversitySearchParams(descending, {
    sort: 'rank_2025_asc', page: 1,
  })
  assert.equal(ascending.toString(), '')
  assert.equal(universityApiParams(ascending).sort, 'rank_2025_asc')
})

test('boş filtreleri ve eski sıralamayı varsayılan 2025 sıralamasına çevirir', () => {
  const current = new URLSearchParams('university=Gazi&sort=score_desc')
  const next = changeUniversitySearchParams(current, { university: ' ', sort: 'rank_asc' })

  assert.equal(next.toString(), '')
  assert.deepEqual(universityApiParams(next), { sort: 'rank_2025_asc' })
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
    sort: 'rank_2025_asc',
  })
})

test('combobox seçimi temizlenince URL ve API parametrelerinden kalkar', () => {
  const current = new URLSearchParams({ university: 'GAZİ ÜNİVERSİTESİ', city: 'ANKARA' })
  const cleared = changeUniversitySearchParams(current, { university: '', page: 1 })

  assert.equal(cleared.has('university'), false)
  assert.deepEqual(universityApiParams(cleared), { city: 'ANKARA', sort: 'rank_2025_asc' })
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
    sort: 'rank_2025_asc',
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
  assert.deepEqual(universityApiParams(cleared), { sort: 'rank_2025_asc' })
})

test('eski tekli URL parametrelerini scalar API filtresi olarak korur', () => {
  const legacy = new URLSearchParams(
    'university=gazi&department=m%C3%BChendis&city=ANKARA&score_type=SAY&year=2026',
  )

  assert.deepEqual(multiFilterValues(legacy, 'university'), ['gazi'])
  assert.deepEqual(universityApiParams(legacy), {
    university: 'gazi',
    department: 'mühendis',
    city: 'ANKARA',
    score_type: 'say',
    year: '2026',
    sort: 'rank_2025_asc',
  })
})

test('checkbox filtrelerini array URL parametreleriyle saklar ve yenilemede geri yükler', () => {
  const selected = changeUniversitySearchParams(new URLSearchParams('page=4'), {
    city: ['İSTANBUL', 'ANKARA', 'İZMİR'],
    score_type: ['say', 'ea'],
    scholarship_type: ['ucretsiz', 'burslu'],
    education_language: ['Türkçe', 'İngilizce'],
    year: ['2025', '2026'],
    page: 1,
  })
  const restored = new URLSearchParams(selected.toString())

  assert.deepEqual(multiFilterValues(restored, 'city'), ['İSTANBUL', 'ANKARA', 'İZMİR'])
  assert.deepEqual(multiFilterValues(restored, 'score_type'), ['say', 'ea'])
  assert.deepEqual(universityApiParams(restored), {
    city: ['İSTANBUL', 'ANKARA', 'İZMİR'],
    score_type: ['say', 'ea'],
    education_language: ['Türkçe', 'İngilizce'],
    scholarship_type: ['ucretsiz', 'burslu'],
    year: ['2025', '2026'],
    sort: 'rank_2025_asc',
  })
  assert.equal(restored.has('page'), false)
})

test('kategori temizleme hem eski hem array URL değerlerini kaldırır', () => {
  const current = new URLSearchParams('city=BURSA&city%5B%5D=ANKARA&score_type%5B%5D=say')
  const cleared = changeUniversitySearchParams(current, { city: [], page: 1 })

  assert.deepEqual(multiFilterValues(cleared, 'city'), [])
  assert.deepEqual(universityApiParams(cleared), {
    score_type: ['say'],
    sort: 'rank_2025_asc',
  })
})

test('aynı kategorinin eski ve array URL değerlerini kaybetmeden birleştirir', () => {
  const mixed = new URLSearchParams('city=BURSA&city%5B%5D=ANKARA&city%5B%5D=BURSA')

  assert.deepEqual(multiFilterValues(mixed, 'city'), ['ANKARA', 'BURSA'])
  assert.deepEqual(universityApiParams(mixed), {
    city: ['ANKARA', 'BURSA'],
    sort: 'rank_2025_asc',
  })
})

test('API seçenekleri ile array seçimleri tekilleştirerek chip değerlerini korur', () => {
  assert.deepEqual(
    filterOptionValues(['ANKARA', 'İSTANBUL'], ['İZMİR', 'ANKARA']),
    ['ANKARA', 'İSTANBUL', 'İZMİR'],
  )
})
