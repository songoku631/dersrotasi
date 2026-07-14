<?php

declare(strict_types=1);

use DersRotasi\Config\Env;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

require dirname(__DIR__) . '/vendor/autoload.php';

const GOOGLE_CERT_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
const WINDOWS_DEFAULT_CA_BUNDLE = 'C:\\php\\cacert.pem';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = new Env($_ENV);
$configuredBundle = $env->sslCaBundle();
$windowsBundleExists = is_file(WINDOWS_DEFAULT_CA_BUNDLE);
$windowsBundleContents = $windowsBundleExists
    ? file_get_contents(WINDOWS_DEFAULT_CA_BUNDLE)
    : false;
$windowsBundleHasCertificate = is_string($windowsBundleContents)
    && str_contains($windowsBundleContents, '-----BEGIN CERTIFICATE-----');

function outputCheck(string $label, string|bool|null $value): void
{
    if (is_bool($value)) {
        $value = $value ? 'evet' : 'hayir';
    }

    if ($value === null || $value === '') {
        $value = '(bos)';
    }

    echo str_pad($label . ':', 34) . $value . PHP_EOL;
}

echo 'Ders Rotasi backend SSL kontrolu' . PHP_EOL;
echo str_repeat('-', 52) . PHP_EOL;
outputCheck('Yuklenen php.ini', php_ini_loaded_file() ?: '(yok)');
outputCheck('curl.cainfo', ini_get('curl.cainfo'));
outputCheck('openssl.cafile', ini_get('openssl.cafile'));
outputCheck('APP_ENV', $env->appEnv());
outputCheck('SSL_CA_BUNDLE (etkin)', $configuredBundle);
outputCheck(WINDOWS_DEFAULT_CA_BUNDLE . ' var', $windowsBundleExists);
outputCheck('PEM sertifika blogu var', $windowsBundleHasCertificate);

if ($configuredBundle !== null) {
    if (!is_file($configuredBundle) || !is_readable($configuredBundle)) {
        fwrite(STDERR, PHP_EOL . 'HATA: SSL_CA_BUNDLE dosyasi bulunamadi veya okunamiyor.' . PHP_EOL);
        exit(1);
    }

    $configuredContents = file_get_contents($configuredBundle);
    if ($configuredContents === false || !str_contains($configuredContents, '-----BEGIN CERTIFICATE-----')) {
        fwrite(STDERR, PHP_EOL . 'HATA: SSL_CA_BUNDLE gecerli bir PEM sertifika blogu icermiyor.' . PHP_EOL);
        exit(1);
    }
}

try {
    $client = new Client([
        'connect_timeout' => 10,
        'timeout' => 10,
        // A path verifies against that bundle; true verifies against the
        // platform store. The diagnostic never turns TLS verification off.
        'verify' => $configuredBundle ?? true,
    ]);
    $response = $client->get(GOOGLE_CERT_URL);
    $certificates = json_decode((string) $response->getBody(), true);

    if ($response->getStatusCode() !== 200 || !is_array($certificates) || $certificates === []) {
        throw new RuntimeException('Google yaniti beklenen sertifika listesini icermiyor.');
    }

    outputCheck('Google HTTPS durum kodu', (string) $response->getStatusCode());
    outputCheck('Google sertifika sayisi', (string) count($certificates));
    echo PHP_EOL . 'BASARILI: TLS sertifika dogrulamasi acik ve Google istegi guvenli.' . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, PHP_EOL . 'BASARISIZ: Google HTTPS istegi tamamlanamadi.' . PHP_EOL);
    fwrite(STDERR, 'Neden: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'php.ini veya .env degisti ise PHP gelistirme sunucusunu yeniden baslatin.' . PHP_EOL);
    exit(1);
}
