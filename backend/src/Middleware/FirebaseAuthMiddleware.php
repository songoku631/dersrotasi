<?php

declare(strict_types=1);

namespace DersRotasi\Middleware;

use DersRotasi\Http\Request;
use DersRotasi\Services\FirebaseTokenVerifier;
use RuntimeException;

final class FirebaseAuthMiddleware
{
    public function __construct(private readonly FirebaseTokenVerifier $verifier)
    {
    }

    public function authenticate(Request $request): array
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            throw new RuntimeException('Yetkilendirme tokenı bulunamadı.', 401);
        }

        return $this->verifier->verify($token);
    }
}
