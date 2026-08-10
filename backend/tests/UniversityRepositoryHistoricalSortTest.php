<?php

declare(strict_types=1);

use DersRotasi\Repositories\UniversityRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

function historicalSortCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction(
    'CHAR_LENGTH',
    static fn (?string $value): int => mb_strlen((string) $value),
    1
);

$pdo->exec(
    'CREATE TABLE universities (
        id INTEGER PRIMARY KEY,
        program_code TEXT NOT NULL,
        year INTEGER NOT NULL,
        university_name TEXT NOT NULL,
        faculty_name TEXT,
        department_name TEXT NOT NULL,
        city TEXT,
        base_score REAL,
        base_rank INTEGER,
        score_type TEXT,
        university_type TEXT,
        education_type TEXT,
        education_language TEXT,
        scholarship_type TEXT,
        duration_years INTEGER,
        quota INTEGER,
        placed_count INTEGER,
        UNIQUE (program_code, year)
    )'
);

$insert = $pdo->prepare(
    'INSERT INTO universities (
        id, program_code, year, university_name, faculty_name, department_name,
        city, base_score, base_rank, score_type, university_type, education_type,
        education_language, scholarship_type, duration_years, quota, placed_count
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$university = 'İSTANBUL MEDİPOL ÜNİVERSİTESİ';
$department = 'Tıp (İngilizce) (Burslu)';
$common = ['İSTANBUL', 'say', 'vakif', 'orgun', 'İngilizce', 'burslu', 6, 10, 10];
$fixtures = [
    [1, '203110477', 2025, $university, 'ULUSLARARASI TIP FAKÜLTESİ', $department, ...$common, 551.13218, 38],
    [21493, '203110477', 2024, $university, 'ULUSLARARASI TIP FAKÜLTESİ', $department, ...$common, 554.91557, 31],
    [21492, '203110477', 2023, $university, 'ULUSLARARASI TIP FAKÜLTESİ', $department, ...$common, 555.35802, 30],
    [78, '203190967', 2025, $university, 'TIP FAKÜLTESİ', $department, ...$common, 533.83234, 1321],
    [21637, '203190967', 2024, $university, 'TIP FAKÜLTESİ', $department, ...$common, 554.91557, 31],
];

foreach ($fixtures as $fixture) {
    [$id, $code, $year, $name, $faculty, $program, $city, $scoreType,
        $universityType, $educationType, $language, $scholarship, $duration,
        $quota, $placed, $score, $rank] = $fixture;
    $insert->execute([
        $id, $code, $year, $name, $faculty, $program, $city, $score, $rank,
        $scoreType, $universityType, $educationType, $language, $scholarship,
        $duration, $quota, $placed,
    ]);
}

$expected = [
    '203110477' => [
        'id' => 1,
        'faculty' => 'ULUSLARARASI TIP FAKÜLTESİ',
        'rankings' => [2025 => 38, 2024 => 31, 2023 => 30],
    ],
    '203190967' => [
        'id' => 78,
        'faculty' => 'TIP FAKÜLTESİ',
        'rankings' => [2025 => 1321, 2024 => 31, 2023 => null],
    ],
];
$baseline = null;
$repository = new UniversityRepository($pdo);
$rankSorts = [
    'rank_2025_asc', 'rank_2025_desc',
    'rank_2024_asc', 'rank_2024_desc',
    'rank_2023_asc', 'rank_2023_desc',
];

foreach ($rankSorts as $sort) {
    $result = $repository->paginate([
        'limit' => 100,
        'sort' => $sort,
        'university' => [$university],
        'department' => [$department],
    ]);
    historicalSortCheck(count($result['items']) === 2, "{$sort}: iki ayrı program satırı dönmeli.");

    $projection = [];
    foreach ($result['items'] as $item) {
        $code = (string) $item['program_code'];
        historicalSortCheck(isset($expected[$code]), "{$sort}: beklenmeyen program kodu döndü.");
        historicalSortCheck($item['id'] === $expected[$code]['id'], "{$sort}: ana satır kimliği değişti.");
        historicalSortCheck(
            $item['faculty_name'] === $expected[$code]['faculty'],
            "{$sort}: fakülte başka programa bağlandı."
        );
        historicalSortCheck(
            $item['rankings'] === $expected[$code]['rankings'],
            "{$sort}: historical sıralamalar başka programa veya yıla bağlandı."
        );
        $projection[$code] = [
            'id' => $item['id'],
            'faculty_name' => $item['faculty_name'],
            'rankings' => $item['rankings'],
            'scores' => $item['scores'],
        ];
    }

    ksort($projection);
    if ($baseline === null) {
        $baseline = $projection;
    } else {
        historicalSortCheck($projection === $baseline, "{$sort}: sort satır payload'unu değiştirdi.");
    }
}

$filterCases = [
    ['rank_2025_asc', 30, 40, ['203110477']],
    ['rank_2024_desc', 30, 40, ['203110477', '203190967']],
    ['rank_2023_asc', 29, 31, ['203110477']],
];
foreach ($filterCases as [$sort, $minRank, $maxRank, $expectedCodes]) {
    $result = $repository->paginate([
        'limit' => 100,
        'sort' => $sort,
        'min_rank' => $minRank,
        'max_rank' => $maxRank,
        'university' => [$university],
        'department' => [$department],
    ]);
    historicalSortCheck(
        array_column($result['items'], 'program_code') === $expectedCodes,
        "{$sort}: min/max rank filtresi aktif sort yılını kullanmadı."
    );
}

$sortUniversity = 'SIRALAMA TEST ÜNİVERSİTESİ';
$sortDepartment = 'Sıralama Test Programı';
$sortFixtures = [
    [30001, '900000001', 2025, 38, 551.1],
    [30002, '900000001', 2024, 43, 550.1],
    [30003, '900000001', 2023, 64, 549.1],
    [30004, '900000002', 2025, 2754617, 201.1],
    [30005, '900000002', 2024, 2753685, 202.1],
    [30006, '900000002', 2023, 2753424, 203.1],
    [30007, '900000003', 2025, 0, 0.0],
    [30008, '900000003', 2024, 0, 0.0],
    [30009, '900000003', 2023, 0, 0.0],
    [30010, '900000004', 2025, null, null],
    [30011, '900000004', 2024, null, null],
    [30012, '900000004', 2023, null, null],
];

foreach ($sortFixtures as [$id, $code, $year, $rank, $score]) {
    $insert->execute([
        $id, $code, $year, $sortUniversity, 'TEST FAKÜLTESİ', $sortDepartment,
        'ANKARA', $score, $rank, 'say', 'devlet', 'orgun', 'Türkçe', 'ucretsiz',
        4, 10, 10,
    ]);
}

$sortPayloadBaseline = null;
foreach ($rankSorts as $sort) {
    preg_match('/^rank_(202[345])_(asc|desc)$/', $sort, $matches);
    $year = (int) $matches[1];
    $direction = $matches[2];
    $result = $repository->paginate([
        'limit' => 100,
        'sort' => $sort,
        'university' => [$sortUniversity],
        'department' => [$sortDepartment],
    ]);
    $items = $result['items'];
    historicalSortCheck(count($items) === 4, "{$sort}: dört sıralama fixture'ı dönmeli.");

    $expectedValidCodes = $direction === 'asc'
        ? ['900000001', '900000002']
        : ['900000002', '900000001'];
    historicalSortCheck(
        array_slice(array_column($items, 'program_code'), 0, 2) === $expectedValidCodes,
        "{$sort}: geçerli rank sırası yanlış."
    );
    foreach (array_slice($items, 2) as $invalidItem) {
        $rank = $invalidItem['rankings'][$year];
        historicalSortCheck(
            $rank === null || $rank <= 0,
            "{$sort}: NULL/0 rank listenin sonunda değil."
        );
    }

    $projection = [];
    foreach ($items as $item) {
        $projection[$item['program_code']] = [
            'id' => $item['id'],
            'rankings' => $item['rankings'],
            'scores' => $item['scores'],
        ];
    }
    ksort($projection);
    if ($sortPayloadBaseline === null) {
        $sortPayloadBaseline = $projection;
    } else {
        historicalSortCheck(
            $projection === $sortPayloadBaseline,
            "{$sort}: sort historical payload'u değiştirdi."
        );
    }
}

echo "UniversityRepositoryHistoricalSortTest: OK\n";
