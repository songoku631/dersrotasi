<?php

declare(strict_types=1);

use DersRotasi\Repositories\UniversityHistoryPresenter;

require dirname(__DIR__) . '/vendor/autoload.php';

function historyPresenterCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$presented = (new UniversityHistoryPresenter())->present([
    'id' => '42',
    'year' => '2026',
    'source_year' => '2026',
    'base_rank' => '7395',
    'base_score' => '509.08812',
    'quota' => '100',
    'placed_count' => null,
    'duration_years' => '4',
    'is_favorite' => '1',
    'favorite_id' => '37',
    'ranking_2026' => '1',
    'ranking_2025' => '7395',
    'ranking_2024' => '5725',
    'ranking_2023' => '5039',
    'score_2026' => '559.69717',
    'score_2025' => '509.08812',
    'score_2024' => '514.28196',
    'score_2023' => '527.29304',
    'quota_2026' => '3',
    'quota_2025' => '100',
    'quota_2024' => '115',
    'quota_2023' => '110',
]);

historyPresenterCheck($presented['year'] === 2026, '2026 etiketi güncel yıl olarak korunmalı.');
historyPresenterCheck($presented['source_year'] === 2026, 'Kaynak yılı izlenebilir kalmalı.');
historyPresenterCheck($presented['rankings'] === ['2026' => 1, '2025' => 7395, '2024' => 5725, '2023' => 5039], 'Sıralamalar yıllara göre normalize edilmedi.');
historyPresenterCheck($presented['scores']['2024'] === 514.28196, '2024 taban puanı normalize edilmedi.');
historyPresenterCheck($presented['quotas']['2023'] === 110, '2023 kontenjanı normalize edilmedi.');
historyPresenterCheck($presented['favorite_id'] === 37, 'Mevcut favori kimliği korunmadı.');
historyPresenterCheck(!array_key_exists('ranking_2026', $presented), 'İç sorgu alias alanı yanıtta kalmamalı.');

echo "UniversityHistoryPresenterTest: OK\n";
