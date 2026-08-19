<?php

declare(strict_types=1);

use DersRotasi\Yokatlas\CurrentUniversityMapper;

require dirname(__DIR__) . '/vendor/autoload.php';

function currentMapperCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$official = [
    'yil' => 2026,
    'kilavuzKodu' => 203110477,
    'universiteAdi' => 'İSTANBUL MEDİPOL ÜNİVERSİTESİ',
    'fymkAdi' => 'ULUSLARARASI TIP FAKÜLTESİ',
    'birimAdi' => 'Tıp (İngilizce) (Burslu)',
    'ilAdi' => 'İSTANBUL',
    'universiteTuru' => 'VAKIF',
    'puanTuru' => 'SAY',
    'ogrenimTuruAdi' => 'Örgün Öğretim',
    'ogrenimDiliAdi' => 'İngilizce',
    'bursOraniAdi' => 'Burslu',
    'ogrenimSuresi' => 6,
    'kontenjan' => 3,
    'gkY' => 3,
    'minPuan' => 559.69717,
    'basariSirasi' => 1,
    'minPuan1' => '551.13218',
    'basariSirasi1' => 38,
];

$row = (new CurrentUniversityMapper())->map($official, 2026, '2026-08-19 12:00:00');
currentMapperCheck($row['year'] === 2026 && $row['source_year'] === 2026, '2026 yılı korunmadı.');
currentMapperCheck($row['program_code'] === '203110477', 'Program kodu string olarak korunmadı.');
currentMapperCheck($row['base_score'] === '559.69717', '2026 minPuan alanı kullanılmadı.');
currentMapperCheck($row['base_rank'] === 1, '2026 basariSirasi alanı kullanılmadı.');
currentMapperCheck($row['base_score'] !== '551.13218', '2025 minPuan1 yanlışlıkla 2026 kabul edildi.');
currentMapperCheck($row['base_rank'] !== 38, '2025 basariSirasi1 yanlışlıkla 2026 kabul edildi.');
currentMapperCheck($row['quota'] === 3 && $row['placed_count'] === 3, 'Kontenjan/yerleşen alanları yanlış.');
currentMapperCheck($row['score_type'] === 'say' && $row['scholarship_type'] === 'burslu', 'Enum eşlemesi yanlış.');
currentMapperCheck($row['identity_hash'] === hash('sha256', '203110477'), 'Kimlik hash’i deterministik değil.');

$notFilled = $official;
$notFilled['kilavuzKodu'] = 100110027;
$notFilled['minPuan'] = 'Dolmadı';
$notFilled['basariSirasi'] = '—';
$notFilled['gkY'] = 0;
$notFilledRow = (new CurrentUniversityMapper())->map($notFilled, 2026, '2026-08-19 12:00:00');
currentMapperCheck($notFilledRow['base_score'] === null, 'Dolmadı puanı NULL olmalı.');
currentMapperCheck($notFilledRow['base_rank'] === null, 'Çizgi başarı sırası NULL olmalı.');
currentMapperCheck($notFilledRow['placed_count'] === 0, 'Sıfır yerleşen korunmalı.');

$wrongYear = $official;
$wrongYear['yil'] = 2025;
try {
    (new CurrentUniversityMapper())->map($wrongYear, 2026, '2026-08-19 12:00:00');
    throw new RuntimeException('Yanlış kaynak yılı kabul edildi.');
} catch (InvalidArgumentException) {
}

echo "CurrentUniversityMapperTest: OK\n";
