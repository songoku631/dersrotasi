<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Repositories\ProfileRepository;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$pdo = Connection::make(new Env($_ENV));
$repository = new ProfileRepository($pdo);
$suffix = bin2hex(random_bytes(5));
$firstUid = 'username-test-a-' . $suffix;
$secondUid = 'username-test-b-' . $suffix;
$username = 'user_' . $suffix;

$pdo->beginTransaction();
try {
    $repository->save($firstUid, [
        'target_department' => 'Tıp',
        'first_name' => 'Deniz',
        'birth_year' => 2005,
        'education_status' => 'universite',
        'city' => 'İstanbul',
    ]);
    $profile = $repository->save($firstUid, ['username' => $username]);
    assert($profile['username'] === $username);
    assert($profile['target_department'] === 'Tıp');
    assert($profile['first_name'] === 'Deniz');
    assert((int) $profile['birth_year'] === 2005);
    assert($profile['education_status'] === 'universite');
    assert((int) $profile['email_public'] === 0);
    assert((int) $profile['birth_year_public'] === 0);

    try {
        $repository->save($secondUid, ['username' => $username]);
        throw new RuntimeException('Duplicate username was accepted.');
    } catch (RuntimeException $exception) {
        assert($exception->getCode() === 409);
    }
} finally {
    $pdo->rollBack();
}

echo "ProfileUsernameTest: OK\n";
