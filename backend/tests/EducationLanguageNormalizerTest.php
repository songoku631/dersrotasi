<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use DersRotasi\Import\EducationLanguageNormalizer;

function assertLanguage(string $expected, string $departmentName, ?string $provided = null): void
{
    $actual = EducationLanguageNormalizer::normalize($departmentName, $provided);
    if ($actual !== $expected) {
        throw new RuntimeException("Expected {$expected}, got {$actual}");
    }
}

assertLanguage('Türkçe', 'Bilgisayar Mühendisliği');
assertLanguage('İngilizce', 'Bilgisayar Mühendisliği (%100 İngilizce)');
assertLanguage('Almanca', 'Almanca Öğretmenliği');
assertLanguage('Fransızca', 'Fransız Dili ve Edebiyatı');
assertLanguage('Türkçe', 'Hukuk', 'Türkçe');
assertLanguage('İngilizce', 'Hukuk', 'İngilizce');

echo "EducationLanguageNormalizerTest: OK\n";
