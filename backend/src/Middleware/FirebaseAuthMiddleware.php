<?php

declare(strict_types=1);

namespace DersRotasi\Middleware;

use DersRotasi\Http\Request;
use DersRotasi\Services\FirebaseTokenVerifier;
use RuntimeException;

final class FirebaseAuthMiddleware
{
    private readonly EmailVerificationPolicy $emailVerificationPolicy;

    public function __construct(
        private readonly FirebaseTokenVerifier $verifier,
        ?EmailVerificationPolicy $emailVerificationPolicy = null
    ) {
        $this->emailVerificationPolicy = $emailVerificationPolicy ?? new EmailVerificationPolicy();
    }

    public function authenticate(Request $request): array
    {
        $authorization = $request->authorizationHeader();

        if ($authorization === null) {
            error_log('[Firebase Auth] missing_authorization_header');
            throw new RuntimeException('Yetkilendirme tokenı bulunamadı.', 401);
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) || trim($matches[1]) === '') {
            error_log('[Firebase Auth] invalid_bearer_token');
            throw new RuntimeException('Yetkilendirme tokenı geçersiz.', 401);
        }

        $identity = $this->verifier->verify(trim($matches[1]));
        $this->emailVerificationPolicy->assertAllowed($identity);

        return $identity;
    }
}
