<?php

declare(strict_types=1);

use DersRotasi\Yokatlas\YokatlasResponseValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertYokatlas(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$validator = new YokatlasResponseValidator();
$program = [
    'program_code' => '999999991', 'year' => 2025,
    'university_name' => 'DENEME ÜNİVERSİTESİ (ANKARA)',
    'department_name' => 'Deneme Bölümü (%50 İndirimli)',
    'base_score' => '300.12345',
];
$official = [
    'content' => [[
        'kilavuzKodu' => 999999991, 'yil' => 2025,
        'basariSirasi' => '12.345', 'minBasariSirasi' => 1,
        'minPuan' => 300.12345, 'universiteAdi' => 'DENEME ÜNİVERSİTESİ (ANKARA)',
        'birimGrupAdi' => 'Deneme Bölümü',
    ]],
];
$valid = $validator->validate($official, $program, 'https://example.invalid/program', '2025-01-01 00:00:00');
assertYokatlas($valid['status'] === 'valid' && $valid['base_rank'] === 12345, 'Gerçek başarı sırası ayrıştırılamadı.');
assertYokatlas($valid['base_rank'] !== 1, 'Başarı Sırası Şartı yanlışlıkla base_rank olarak kullanıldı.');

$missing = $official;
$missing['content'][0]['basariSirasi'] = 'Dolmadı';
assertYokatlas($validator->validate($missing, $program, '', '')['status'] === 'rank_missing', 'Dolmadı değeri NULL sayılmadı.');

$wrongYear = $official;
$wrongYear['content'][0]['yil'] = 2024;
assertYokatlas($validator->validate($wrongYear, $program, '', '')['status'] === 'year_mismatch', 'Yanlış yıl reddedilmedi.');

$wrongScore = $official;
$wrongScore['content'][0]['minPuan'] = 301.00;
assertYokatlas($validator->validate($wrongScore, $program, '', '')['status'] === 'score_mismatch', 'Taban puanı çakışması reddedilmedi.');

$wrongCode = $official;
$wrongCode['content'][0]['kilavuzKodu'] = 999999992;
assertYokatlas($validator->validate($wrongCode, $program, '', '')['status'] === 'unmatched', 'Farklı program kodu reddedilmedi.');

echo "YokatlasResponseValidatorTest: OK\n";
