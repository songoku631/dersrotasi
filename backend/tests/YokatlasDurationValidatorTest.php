<?php

declare(strict_types=1);

use DersRotasi\Yokatlas\YokatlasDurationValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

function durationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$validator = new YokatlasDurationValidator();
$response = ['content' => [[
    'kilavuzKodu' => 100790686,
    'yil' => 2026,
    'ogrenimSuresi' => 4,
    'universiteAdi' => 'AKDENİZ ÜNİVERSİTESİ (ANTALYA)',
    'fymkAdi' => 'KUMLUCA SAĞLIK BİLİMLERİ FAKÜLTESİ',
    'birimAdi' => 'Hemşirelik',
]]];
$valid = $validator->validate($response, '100790686', 2026);
durationCheck($valid['status'] === 'valid' && $valid['duration_years'] === 4, 'Resmî süre doğrulanmadı.');

$wrongYear = $response;
$wrongYear['content'][0]['yil'] = 2025;
durationCheck($validator->validate($wrongYear, '100790686', 2026)['status'] === 'year_mismatch', 'Yanlış yıl kabul edildi.');

$missing = $response;
$missing['content'][0]['ogrenimSuresi'] = null;
durationCheck($validator->validate($missing, '100790686', 2026)['status'] === 'duration_missing', 'Eksik süre tahmin edildi.');

$duplicate = $response;
$duplicate['content'][] = $duplicate['content'][0];
durationCheck($validator->validate($duplicate, '100790686', 2026)['status'] === 'ambiguous', 'Duplicate resmî kayıt kabul edildi.');

$wrongCode = $response;
$wrongCode['content'][0]['kilavuzKodu'] = 100790687;
durationCheck($validator->validate($wrongCode, '100790686', 2026)['status'] === 'unmatched', 'Yanlış program kodu kabul edildi.');

echo "YokatlasDurationValidatorTest: OK\n";
