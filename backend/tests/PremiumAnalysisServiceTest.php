<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Repositories\PreferenceRepository;
use DersRotasi\Repositories\ProfileRepository;
use DersRotasi\Repositories\UniversityRepository;
use DersRotasi\Services\PremiumAnalysisService;
use DersRotasi\Subscriptions\PremiumAccessGuard;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

function premiumAnalysisCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function premiumAnalysisThrows(callable $callback, int $status, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        premiumAnalysisCheck($exception->getCode() === $status, $message . ' HTTP kodu hatalı.');
        return;
    }
    throw new RuntimeException($message . ' hata fırlatmadı.');
}

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = new Env($_ENV);
premiumAnalysisCheck(
    $env->appEnv() === 'local' && in_array($env->dbHost(), ['127.0.0.1', 'localhost', '::1'], true),
    'Bu test yalnızca local veritabanında çalışabilir.'
);

$pdo = Connection::make($env);
$pdo->exec("CREATE TEMPORARY TABLE user_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  firebase_uid VARCHAR(128) NOT NULL UNIQUE,
  score_type VARCHAR(32) NOT NULL DEFAULT 'sayisal',
  target_rank INT UNSIGNED NULL,
  target_department VARCHAR(255) NOT NULL DEFAULT '',
  preferred_cities TEXT NOT NULL,
  university_type VARCHAR(32) NOT NULL DEFAULT 'fark_etmez',
  daily_study_hours DECIMAL(4,1) NULL,
  strong_lessons TEXT NOT NULL,
  improvement_lessons TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec("CREATE TEMPORARY TABLE preference_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  firebase_uid VARCHAR(128) NOT NULL,
  university_id BIGINT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec('CREATE TEMPORARY TABLE favorites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  firebase_uid VARCHAR(128) NOT NULL,
  university_id BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB');

$universities = new UniversityRepository($pdo);
$page = $universities->paginate(['page' => 1, 'limit' => 100, 'sort' => 'rank_2026_asc']);
$candidates = array_values(array_filter($page['items'], static function (array $item): bool {
    return count(array_filter($item['rankings'], static fn (mixed $rank): bool => is_int($rank) && $rank > 0)) >= 2;
}));
premiumAnalysisCheck(count($candidates) >= 2, 'Local üniversite verisinde iki çok yıllı program bulunamadı.');
$selected = array_slice($candidates, 0, 2);
$uid = 'premium-analysis-local-test';
$pdo->prepare("INSERT INTO user_profiles (
  firebase_uid, target_rank, preferred_cities, strong_lessons, improvement_lessons
) VALUES (:uid, 100000, '', '', '')")->execute(['uid' => $uid]);
$insertPreference = $pdo->prepare(
    'INSERT INTO preference_items (firebase_uid, university_id, position, note) VALUES (:uid, :id, :position, :note)'
);
foreach ($selected as $index => $program) {
    $insertPreference->execute(['uid' => $uid, 'id' => $program['id'], 'position' => $index + 1, 'note' => 'local test']);
}

$service = new PremiumAnalysisService(new PreferenceRepository($pdo), new ProfileRepository($pdo), $universities);
$analysis = $service->analyzePreferences($uid, null);
premiumAnalysisCheck($analysis['user_rank'] === 100000, 'Profildeki gerçek başarı sırası kullanılmalı.');
premiumAnalysisCheck(count($analysis['items']) === 2, 'İki tercih analiz edilmeliydi.');
premiumAnalysisCheck(array_sum($analysis['counts']) === 2, 'Risk sayıları program sayısıyla eşleşmeli.');
foreach ($analysis['items'] as $item) {
    $source = $universities->findWithHistory((int) $item['id'], $uid);
    premiumAnalysisCheck($source !== null, 'Kaynak program bulunmalı.');
    premiumAnalysisCheck($item['program_code'] === $source['program_code'], 'Program kodu gerçek DB kaydıyla eşleşmeli.');
    premiumAnalysisCheck($item['rankings'] === $source['rankings'], 'Yıllık sıralar gerçek DB kaydıyla eşleşmeli.');
    premiumAnalysisCheck($item['scores'] === $source['scores'], 'Yıllık puanlar gerçek DB kaydıyla eşleşmeli.');
    premiumAnalysisCheck($item['quotas'] === $source['quotas'], 'Yıllık kontenjanlar gerçek DB kaydıyla eşleşmeli.');
}

$comparison = $service->comparePrograms($uid, array_column($selected, 'id'), 80000);
premiumAnalysisCheck(count($comparison['programs']) === 2, 'Karşılaştırma iki program döndürmeli.');
premiumAnalysisCheck($comparison['user_rank'] === 80000, 'İstek sırası profil sırasını geçersiz kılmalı.');
premiumAnalysisCheck(isset($comparison['comparison']['stability_note']), 'Karşılaştırma istikrar notu taşımalı.');

$guard = new PremiumAccessGuard();
premiumAnalysisThrows(fn () => $guard->assertAllowed(['is_premium' => false, 'is_admin' => false]), 403, 'Free Premium endpoint erişimi');
$guard->assertAllowed(['is_premium' => true, 'is_admin' => false]);
$guard->assertAllowed(['is_premium' => false, 'is_admin' => true]);

echo "PremiumAnalysisServiceTest: OK\n";
