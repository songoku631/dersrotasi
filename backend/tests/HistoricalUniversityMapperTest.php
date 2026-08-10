<?php

declare(strict_types=1);

use DersRotasi\Yokatlas\HistoricalUniversityMapper;

require dirname(__DIR__) . '/vendor/autoload.php';

function historicalMapperCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$mapper = new HistoricalUniversityMapper();
$official = [
    'yil' => 2026,
    'kilavuzKodu' => 110110031,
    'universiteAdi' => 'YILDIZ TEKNİK ÜNİVERSİTESİ (İSTANBUL)',
    'fymkAdi' => 'ELEKTRİK-ELEKTRONİK FAKÜLTESİ',
    'birimAdi' => 'Bilgisayar Mühendisliği',
    'birimGrupAdi' => 'Bilgisayar Mühendisliği',
    'ilAdi' => 'İSTANBUL',
    'universiteTuru' => 'DEVLET',
    'puanTuru' => 'SAY',
    'ogrenimTuruAdi' => 'Örgün Öğretim',
    'ogrenimDiliAdi' => 'İngilizce (%30)',
    'bursOraniAdi' => null,
    'ogrenimSuresi' => 4,
    'minPuan' => 509.08812,
    'basariSirasi' => 7395,
    'gk1' => 100,
    'gkY1' => 100,
    'minPuan1' => '514.28196',
    'basariSirasi1' => 5725,
    'gk2' => 115,
    'minPuan2' => '527.29304',
    'basariSirasi2' => 5039,
    'gk3' => 110,
];

$row2024 = $mapper->map($official, 2024, '2026-08-08 00:00:00');
historicalMapperCheck($row2024 !== null, '2024 satırı eşlenemedi.');
historicalMapperCheck($row2024['program_code'] === '110110031', 'Program kodu yanlış eşlendi.');
historicalMapperCheck($row2024['base_score'] === '514.28196', '2024 minPuan1 eşlemesi yanlış.');
historicalMapperCheck($row2024['base_rank'] === 5725, '2024 basariSirasi1 eşlemesi yanlış.');
historicalMapperCheck($row2024['quota'] === 115, '2024 gk2 eşlemesi yanlış.');
historicalMapperCheck($row2024['placed_count'] === null, '2024 placed_count uydurulmamalı.');
historicalMapperCheck($row2024['year'] === 2024, '2024 hedef yılı yanlış.');

$row2023 = $mapper->map($official, 2023, '2026-08-08 00:00:00');
historicalMapperCheck($row2023 !== null, '2023 satırı eşlenemedi.');
historicalMapperCheck($row2023['base_score'] === '527.29304', '2023 minPuan2 eşlemesi yanlış.');
historicalMapperCheck($row2023['base_rank'] === 5039, '2023 basariSirasi2 eşlemesi yanlış.');
historicalMapperCheck($row2023['quota'] === 110, '2023 gk3 eşlemesi yanlış.');
historicalMapperCheck($row2023['placed_count'] === null, '2023 placed_count uydurulmamalı.');

$missing = $official;
$missing['minPuan1'] = '0';
$missing['basariSirasi1'] = null;
$missing['gk2'] = 0;
historicalMapperCheck(
    $mapper->map($missing, 2024, '2026-08-08 00:00:00') === null,
    'Historical kanıtı olmayan program satırı oluşturulmamalı.',
);

$rankMissing = $official;
$rankMissing['basariSirasi1'] = null;
$rankMissingRow = $mapper->map($rankMissing, 2024, '2026-08-08 00:00:00');
historicalMapperCheck($rankMissingRow['base_rank'] === null, 'Eksik başarı sırası NULL kalmalı.');
historicalMapperCheck($rankMissingRow['rank_source_name'] === null, 'NULL sıralamaya kaynak uydurulmamalı.');

echo "HistoricalUniversityMapperTest: OK\n";
