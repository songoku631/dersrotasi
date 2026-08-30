<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use RuntimeException;

final class AiSecurityGuard
{
    public const ALLOWED_EDUCATION = 'allowed_education';
    public const ALLOWED_SMALLTALK = 'allowed_smalltalk';
    public const ALLOWED_GENERAL = 'allowed_general';
    public const OUT_OF_SCOPE = 'out_of_scope';
    public const UNSAFE = 'unsafe';
    public const PROMPT_INJECTION = 'prompt_injection';
    public const SENSITIVE_PERSONAL_DATA = 'sensitive_personal_data';

    private const EDUCATION_PATTERNS = [
        '/\b(yks|tyt|ayt|lgs|sayisal|esit agirlik|sozel|yabanci dil|say|ea|soz)\b/u',
        '/\b(ders|sinav|deneme|net|dogru|yanlis|konu|akademik|ogrenci|ogretmen|okul)\w*/u',
        '/\b(matematik|geometri|turkce|edebiyat|fizik|kimya|biyoloji|tarih|cografya|felsefe|paragraf)\w*/u',
        '/\b(muhendislik|bilgisayar|elektrik elektronik|yazilim|tip|dis hekim|eczacilik|hukuk|psikoloji|isletme|mimarlik)\w*/u',
        '/\b(?:\d{1,3}[. ]\d{3}|\d{1,3}\s*(?:k|bin)|\d{5,7})\b/u',
        '/\b(universite|bolum|fakulte|program|tercih|kontenjan|taban puan|basari sira|yerlesen)\w*/u',
        '/\b(calisma program|ders program|zaman yonet|sinav strateji|hedef sira|motivasyon)\w*/u',
        '/(?:\b\d+\s*saat\b.{0,40}\b(?:calis|program|plan)|\bcalis\w*.{0,40}\b(?:program|plan))\w*/u',
        '/\b(verimli calis|calisma teknigi|calisma oner|pomodoro|konu tekrari)\w*/u',
        '/\b(favori|tercih list|calisma plan|haftalik plan|profilimdeki hedef)\w*/u',
        '/\b(burs|ucret|ogretim tur|ogretim dili|hazirlik|orgun|ikinci ogretim)\w*/u',
        '/\b(kariyer|meslek|is imkani|hedef|siralam|puan|filtre|secenek)\w*/u',
    ];

    private const SMALLTALK_PATTERNS = [
        '/^(merhaba|selam|hey|gunaydin|iyi aksamlar|iyi geceler)[!. ]*$/u',
        '/^(nasilsin|ne haber|tesekkur(?:ler)?|sag ol|gorusuruz)[!. ]*$/u',
        '/^(merhaba|selam)[, ]+(nasilsin|ne haber)[?!. ]*$/u',
    ];

    private const FOLLOW_UP_PATTERNS = [
        '/^(peki|neden|nasil|hangisi|biraz daha|devam|baska|ya|bunlari|bunu|onu)\b/u',
        '/\b(karsilastir|aciklar misin|detaylandir|listele|sirala)\b/u',
    ];

    private const GENERAL_PATTERNS = [
        '/\b(yemek tarifi|makarna|kek tarifi|futbol mac|magazin|burc yorumu)\b/u',
        '/\b(kripto|borsa tavsiye|yatirim tavsiye|siyasi parti|secim tahmini)\b/u',
        '/\b(kod yaz|php kod|javascript kod|oyun hilesi|film oner|dizi oner)\b/u',
        '/\b(flort|sevgili bul|romantik mesaj|fal bak)\b/u',
    ];

    public function __construct(private readonly AiContentModerator $moderator)
    {
    }

    /**
     * @param list<array{role: string, content: string}> $history
     * @return array{category: string, risk?: string}
     */
    public function inspectInput(string $message, array $history, string $actorHash): array
    {
        $expanded = $this->expandedText($message);
        if ($this->isPromptInjection($expanded)) {
            $this->telemetry('prompt_injection_blocked', $actorHash);
            return ['category' => self::PROMPT_INJECTION];
        }
        if ($this->containsSensitiveData($message)) {
            $this->telemetry('sensitive_personal_data_blocked', $actorHash);
            return ['category' => self::SENSITIVE_PERSONAL_DATA];
        }

        $risk = $this->unsafeRisk($expanded);
        if ($risk !== null) {
            $this->telemetry('safety_blocked', $actorHash);
            return ['category' => self::UNSAFE, 'risk' => $risk];
        }

        $moderation = $this->moderator->inspect($message);
        if ($moderation['flagged']) {
            $risk = $this->moderationRisk($moderation['categories']);
            $this->telemetry('safety_blocked', $actorHash);
            return ['category' => self::UNSAFE, 'risk' => $risk];
        }

        return ['category' => $this->scopeCategory($message, $history)];
    }

    /**
     * @param list<array{role: string, content: string}> $history
     * @return list<array{role: string, content: string}>
     */
    public function sanitizeHistory(array $history, string $actorHash): array
    {
        $sanitized = [];
        $removed = false;
        foreach ($history as $item) {
            $content = $item['content'];
            $expanded = $this->expandedText($content);
            $blocked = $this->isPromptInjection($expanded)
                || $this->containsSensitiveData($content)
                || $this->unsafeRisk($expanded) !== null;
            if (!$blocked) {
                $category = $this->scopeCategory($content, $sanitized);
                $blocked = $category === self::OUT_OF_SCOPE
                    || ($item['role'] === 'assistant' && $this->containsInternalLeak($content));
            }
            if ($blocked) {
                $removed = true;
                continue;
            }
            $sanitized[] = $item;
        }

        if ($sanitized !== []) {
            $joined = implode("\n", array_map(
                static fn (array $item): string => $item['role'] . ': ' . $item['content'],
                $sanitized
            ));
            try {
                if ($this->moderator->inspect($joined)['flagged']) {
                    $sanitized = [];
                    $removed = true;
                }
            } catch (RuntimeException) {
                // Fail closed for history: no unreviewed history is sent to the model.
                $sanitized = [];
                $removed = true;
            }
        }

        if ($removed) {
            $this->telemetry('history_sanitized', $actorHash);
        }

        return $sanitized;
    }

    /** @return array{allowed: bool, code: string, risk?: string} */
    public function inspectOutput(string $answer, string $actorHash, bool $allowGeneral = false): array
    {
        if ($this->containsInternalLeak($answer)) {
            $this->telemetry('output_blocked', $actorHash);
            return ['allowed' => false, 'code' => self::PROMPT_INJECTION];
        }
        $risk = $this->unsafeRisk($this->expandedText($answer));
        if ($risk !== null) {
            $this->telemetry('output_blocked', $actorHash);
            return ['allowed' => false, 'code' => self::UNSAFE, 'risk' => $risk];
        }

        $moderation = $this->moderator->inspect($answer);
        if ($moderation['flagged']) {
            $this->telemetry('output_blocked', $actorHash);
            return [
                'allowed' => false,
                'code' => self::UNSAFE,
                'risk' => $this->moderationRisk($moderation['categories']),
            ];
        }

        $normalized = $this->normalize($answer);
        if (!$allowGeneral && !$this->matchesAny($normalized, self::EDUCATION_PATTERNS)) {
            $this->telemetry('output_blocked', $actorHash);
            return ['allowed' => false, 'code' => self::OUT_OF_SCOPE];
        }

        return ['allowed' => true, 'code' => 'ok'];
    }

    /**
     * @param list<array{role: string, content: string}> $history
     */
    private function scopeCategory(string $message, array $history): string
    {
        $normalized = $this->normalize($message);
        if ($this->matchesAny($normalized, self::SMALLTALK_PATTERNS)) {
            return self::ALLOWED_SMALLTALK;
        }
        if ($this->matchesAny($normalized, self::GENERAL_PATTERNS)) {
            return self::ALLOWED_GENERAL;
        }
        if ($this->matchesAny($normalized, self::EDUCATION_PATTERNS)) {
            return self::ALLOWED_EDUCATION;
        }
        if ($history !== [] && $this->length($message) <= 160
            && $this->matchesAny($normalized, self::FOLLOW_UP_PATTERNS)) {
            return self::ALLOWED_EDUCATION;
        }

        return self::ALLOWED_GENERAL;
    }

    private function isPromptInjection(string $text): bool
    {
        $normalized = $this->normalize($text);
        $compact = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? $normalized;
        $phrases = [
            'onceki talimatlari unut', 'tum talimatlari unut', 'kurallarini gormezden gel',
            'system prompt', 'developer mesaji', 'gizli talimat', 'sinirlarini kaldir',
            'ignore previous instructions', 'reveal system prompt', 'show developer message',
            'jailbreak', 'dan mode', 'roleplay ile kurallari as', 'tool talimatlarini uygula',
            'environment variables', 'api keyleri goster', 'database credentials',
            'api keyi goster', 'api keyini goster', 'backend secret',
            'ortam degiskenlerini goster', 'guvenlik kurallarini kapat',
        ];
        foreach ($phrases as $phrase) {
            $needle = $this->normalize($phrase);
            if (str_contains($normalized, $needle)) {
                return true;
            }
            $compactNeedle = preg_replace('/[^a-z0-9]+/', '', $needle) ?? $needle;
            if (strlen($compactNeedle) >= 10 && str_contains($compact, $compactNeedle)) {
                return true;
            }
        }

        return preg_match(
            '/\b(system|developer|tool)\b.{0,40}\b(prompt|mesaj|talimat|kural)\b/u',
            $normalized
        ) === 1 || preg_match(
            '/\b(talimat|kural|filtre|sinir)\w*.{0,35}\b(unut|yok say|gormezden gel|degistir|iptal et|kaldir|kapat|devre disi|as|atlat)\w*/u',
            $normalized
        ) === 1 || preg_match(
            '/\b(rol yap|roleplay|simule et)\w*.{0,50}\b(kural|sinir|talimat)\w*/u',
            $normalized
        ) === 1 || preg_match(
            '/\b(goster|yaz|acikla|ifsa et|sızdır|sizdir)\w*.{0,35}\b(api key|secret|ortam degisken|kimlik bilg|system prompt|developer mesaj)\w*/u',
            $normalized
        ) === 1;
    }

    private function unsafeRisk(string $text): ?string
    {
        $normalized = $this->normalize($text);
        $patterns = [
            'self_harm' => [
                '/\b(intihar|kendimi oldur|kendimi kes|olmek istiyorum|canima kiy|kendime zarar|yasamak istemiyorum)\w*/u',
                '/\b(self harm|kill myself|suicide)\b/u',
            ],
            'sexual' => [
                '/\b(porno|pornograf|cinsel icerik|ciplak fotograf|nude)\w*/u',
                '/\b(cocuk|resit olmayan)\b.{0,30}\b(cinsel|seks|porno)\w*/u',
            ],
            'weapons' => [
                '/\b(nasil|yapim|uret|hazirla|adim adim)\w*.{0,35}\b(bomba|patlayici|silah|molotof)\w*/u',
                '/\b(bomba|patlayici|silah|molotof)\w*.{0,35}\b(nasil|yapim|uret|hazirla)\w*/u',
            ],
            'drugs' => [
                '/\b(uyusturucu|metamfetamin|kokain|eroin)\w*.{0,35}\b(yap|uret|kullan|sakla|sat)\w*/u',
            ],
            'violence' => [
                '/\b(nasil|yontem|adim adim)\w*.{0,35}\b(oldur|yarala|iskence|ciddi zarar)\w*/u',
            ],
            'crime' => [
                '/\b(hesap cal|sifre kir|kart dolandir|delil\w* yok et|polisten.{0,20}kac|kilit ac)\w*/u',
                '/\b(suc|hirsizlik|dolandiricilik)\w*.{0,30}\b(nasil|yontem|plan)\w*/u',
                '/\b(sahte belge|sahte kimlik|phishing sayfa|oltalama mesaji)\w*.{0,35}\b(yap|hazirla|uret|gonder)\w*/u',
            ],
            'cyber' => [
                '/\b(ransomware|keylogger|malware|zararli yazilim|ddos|botnet)\w*.{0,35}\b(yap|yaz|uret|kur|dagit|calistir)\w*/u',
                '/\b(token|oturum|hesap|sifre)\w*.{0,30}\b(cal|ele gecir|sizdir|kir)\w*/u',
                '/\b(sql injection|xss|acik istismar)\w*.{0,35}\b(saldir|ele gecir|veri cal|atlat)\w*/u',
            ],
            'privacy' => [
                '/\b(doxx|doxing|kisisel veri|ev adres|telefon numara|konum)\w*.{0,35}\b(bul|cal|sizdir|ifsa et|takip et)\w*/u',
                '/\b(birinin|baskasinin)\b.{0,30}\b(adres|telefon|kimlik|hesap bilgi)\w*.{0,30}\b(bul|cikar|ele gecir)\w*/u',
            ],
            'dangerous_activity' => [
                '/\b(tehlikeli challenge|bayilma challenge|nefes kesme oyunu)\w*/u',
                '/\b(guvenlik onlemini|korumayi|filtreyi)\b.{0,25}\b(as|devre disi|atlat)\w*/u',
            ],
        ];
        foreach ($patterns as $risk => $riskPatterns) {
            if ($this->matchesAny($normalized, $riskPatterns)) {
                return $risk;
            }
        }

        return null;
    }

    private function containsSensitiveData(string $text): bool
    {
        if (preg_match('/\b(?:sk|rk|pk)-[A-Za-z0-9_-]{16,}\b/', $text) === 1
            || preg_match('/\bAIza[A-Za-z0-9_-]{20,}\b/', $text) === 1
            || preg_match('/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/', $text) === 1
            || preg_match('/\b(?:password|parola|sifre|secret|api[_ -]?key)\s*[:=]\s*\S+/ui', $text) === 1
            || preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/ui', $text) === 1
            || preg_match('/(?<!\d)(?:\+?90\s*)?(?:0?5\d{2})[\s.-]*\d{3}[\s.-]*\d{2}[\s.-]*\d{2}(?!\d)/', $text) === 1
            || preg_match('/\b(adresim|ev adresim)\b.{0,100}\b(mahalle|mahallesi|sokak|caddesi|no:)\b/ui', $text) === 1) {
            return true;
        }

        if (preg_match_all('/(?<!\d)\d{11}(?!\d)/', $text, $identifiers)) {
            foreach ($identifiers[0] as $identifier) {
                if ($this->isTurkishIdentityNumber($identifier)) {
                    return true;
                }
            }
        }
        if (preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $text, $cards)) {
            foreach ($cards[0] as $card) {
                $digits = preg_replace('/\D/', '', $card) ?? '';
                if (strlen($digits) >= 13 && strlen($digits) <= 19 && $this->passesLuhn($digits)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function containsInternalLeak(string $text): bool
    {
        return $this->containsSensitiveData($text)
            || preg_match('/\b(system prompt|developer prompt|gizli talimat|internal tool|hidden context)\b/ui', $text) === 1
            || preg_match('/\b(?:OPENAI_API_KEY|DB_PASSWORD|FIREBASE_PRIVATE_KEY)\s*=/i', $text) === 1
            || preg_match('#(?:[A-Z]:\\\\Users\\\\|/var/www/|/home/[^/]+/)#i', $text) === 1;
    }

    private function moderationRisk(array $categories): string
    {
        foreach ($categories as $category) {
            if (str_starts_with($category, 'self-harm')) {
                return 'self_harm';
            }
            if (str_starts_with($category, 'sexual')) {
                return 'sexual';
            }
            if (str_contains($category, 'violence')) {
                return 'violence';
            }
            if (str_starts_with($category, 'illicit')) {
                return 'crime';
            }
        }

        return 'general';
    }

    private function expandedText(string $text): string
    {
        $clean = html_entity_decode(rawurldecode($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $clean) ?? $clean;
        if (preg_match_all('/(?<![A-Za-z0-9+\/=])[A-Za-z0-9+\/]{20,}={0,2}(?![A-Za-z0-9+\/=])/', $clean, $matches)) {
            foreach (array_slice($matches[0], 0, 3) as $encoded) {
                $decoded = base64_decode($encoded, true);
                if (is_string($decoded) && preg_match('//u', $decoded) === 1
                    && preg_match('/[A-Za-z]/', $decoded) === 1) {
                    $clean .= "\n" . $decoded;
                }
            }
        }

        return $clean;
    }

    private function normalize(string $value): string
    {
        $value = strtolower(strtr($value, [
            'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's',
            'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o',
            'ö' => 'o', 'Ç' => 'c', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u',
            '@' => 'a', '$' => 's', '0' => 'o', '3' => 'e', '4' => 'a',
        ]));
        $value = preg_replace('/[\p{C}]+/u', '', $value) ?? $value;

        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isTurkishIdentityNumber(string $value): bool
    {
        if ($value[0] === '0') {
            return false;
        }
        $digits = array_map('intval', str_split($value));
        $odd = $digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8];
        $even = $digits[1] + $digits[3] + $digits[5] + $digits[7];

        return (($odd * 7 - $even) % 10 + 10) % 10 === $digits[9]
            && array_sum(array_slice($digits, 0, 10)) % 10 === $digits[10];
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $alternate = false;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];
            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $alternate = !$alternate;
        }

        return $sum > 0 && $sum % 10 === 0;
    }

    private function telemetry(string $event, string $actorHash): void
    {
        $safeEvent = preg_replace('/[^a-z_]/', '', $event) ?: 'unknown';
        $safeActor = preg_match('/^[a-f0-9]{32,128}$/', $actorHash) === 1
            ? substr($actorHash, 0, 16)
            : 'anonymous';
        error_log("[AI_SECURITY] event={$safeEvent} actor={$safeActor}");
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
