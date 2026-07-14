<?php

declare(strict_types=1);

namespace DersRotasi\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final class FirebaseTokenVerifier
{
    private const CERT_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    public function __construct(
        private readonly string $projectId,
        private readonly ?string $sslCaBundle = null
    ) {
    }

    public function verify(string $token): array
    {
        if ($this->projectId === '') {
            throw new RuntimeException('Firebase proje kimliği backend .env içinde tanımlı değil.', 500);
        }

        $header = $this->decodeHeader($token);
        $kid = $header['kid'] ?? null;

        if (!is_string($kid) || $kid === '') {
            throw new RuntimeException('Firebase token başlığı geçersiz.', 401);
        }

        $certificates = $this->certificates();
        if (!isset($certificates[$kid])) {
            throw new RuntimeException('Firebase token sertifikası bulunamadı.', 401);
        }

        $decoded = (array) JWT::decode($token, new Key($certificates[$kid], 'RS256'));

        $expectedIssuer = 'https://securetoken.google.com/' . $this->projectId;
        if (($decoded['iss'] ?? '') !== $expectedIssuer) {
            throw new RuntimeException('Firebase token issuer değeri geçersiz.', 401);
        }

        if (($decoded['aud'] ?? '') !== $this->projectId) {
            throw new RuntimeException('Firebase token audience değeri geçersiz.', 401);
        }

        if (!isset($decoded['sub']) || !is_string($decoded['sub']) || $decoded['sub'] === '') {
            throw new RuntimeException('Firebase token kullanıcı kimliği içermiyor.', 401);
        }

        return [
            'uid' => $decoded['sub'],
            'email' => $decoded['email'] ?? null,
            'name' => $decoded['name'] ?? null,
            'picture' => $decoded['picture'] ?? null,
        ];
    }

    private function certificates(): array
    {
        $clientOptions = [
            'connect_timeout' => 5,
            'timeout' => 5,
            // A path verifies with that CA bundle; true uses the system CA
            // store. TLS verification is never disabled.
            'verify' => $this->sslCaBundle ?? true,
        ];

        if ($this->sslCaBundle !== null) {
            $this->assertValidCaBundle($this->sslCaBundle);
        }

        try {
            $client = new Client($clientOptions);
            $response = $client->get(self::CERT_URL);
        } catch (GuzzleException $exception) {
            $caSource = $this->sslCaBundle ?? 'sistem CA deposu';
            error_log(sprintf(
                '[Firebase TLS] Google sertifikalari alinamadi. CA kaynagi: %s. Hata: %s',
                $caSource,
                $exception->getMessage()
            ));

            if (str_contains($exception->getMessage(), 'cURL error 60')) {
                throw new RuntimeException(
                    'Firebase sertifikalari alinirken TLS sertifika zinciri dogrulanamadi. '
                    . 'SSL_CA_BUNDLE ve PHP CA ayarlarini "php scripts/check_ssl.php" ile kontrol edin.',
                    500,
                    $exception
                );
            }

            throw new RuntimeException(
                'Firebase sertifikalarina guvenli HTTPS baglantisi kurulamadi. Backend loglarini kontrol edin.',
                500,
                $exception
            );
        }

        $certificates = json_decode((string) $response->getBody(), true);

        if (!is_array($certificates)) {
            throw new RuntimeException('Firebase sertifikaları alınamadı.', 500);
        }

        return $certificates;
    }

    private function assertValidCaBundle(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            error_log(sprintf(
                '[Firebase TLS] SSL_CA_BUNDLE dosyasi bulunamadi veya okunamiyor: %s',
                $path
            ));

            throw new RuntimeException(
                'Yerel SSL CA bundle dosyasi bulunamadi veya okunamiyor. '
                . 'SSL_CA_BUNDLE degerini kontrol edin.',
                500
            );
        }

        $contents = file_get_contents($path);
        if ($contents === false || !str_contains($contents, '-----BEGIN CERTIFICATE-----')) {
            error_log(sprintf(
                '[Firebase TLS] SSL_CA_BUNDLE gecerli bir PEM sertifikasi icermiyor: %s',
                $path
            ));

            throw new RuntimeException(
                'Yerel SSL CA bundle dosyasi gecerli PEM sertifikasi icermiyor.',
                500
            );
        }
    }

    private function decodeHeader(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            throw new RuntimeException('Firebase token formatı geçersiz.', 401);
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($json === false) {
            throw new RuntimeException('Firebase token başlığı okunamadı.', 401);
        }

        $header = json_decode($json, true);
        if (!is_array($header)) {
            throw new RuntimeException('Firebase token başlığı çözümlenemedi.', 401);
        }

        return $header;
    }
}
