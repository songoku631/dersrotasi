<?php

declare(strict_types=1);

namespace DersRotasi\AI;

final class AiIntent
{
    public function requiresDatabase(string $message): bool
    {
        return $this->signals($message) !== [];
    }

    public function requestsFavorites(string $message): bool
    {
        return (bool) preg_match('/\bfavori\w*/ui', $message);
    }

    public function signals(string $message): array
    {
        $normalized = $this->normalize($message);
        $signals = [];
        if ($this->requestsFavorites($message)) {
            $signals[] = 'favorites';
        }
        if ($this->detectRank($normalized) !== null) {
            $signals[] = 'rank';
        }
        if ($this->detectScoreType($normalized) !== null) {
            $signals[] = 'score_type';
        }
        if (preg_match(
            '/\b(meslek|kariyer|para\s+kazan|kazanc|maaş|maas|iş\s+imkanı|is\s+imkani|'
            . 'geleceği\s+iyi|gelecegi\s+iyi)\w*/u',
            $normalized
        )) {
            $signals[] = 'career';
        }
        if (preg_match(
            '/\b(sıra|siral|bölüm|bolum|program|üniversite|universite|mühendis|muhendis|'
            . 'tercih|burs|taban|puan|karşılaştır|karsilastir|geçen\s+yıl|gecen\s+yil|'
            . 'yüksel|yuksel|ne\s+gelir|ne\s+yazılır|ne\s+yazilir|ne\s+okuyabilirim|'
            . 'ne\s+okunur|hangi\s+meslek)\w*/u',
            $normalized
        )) {
            $signals[] = 'preference';
        }

        return array_values(array_unique($signals));
    }

    public function detectRank(string $message): ?int
    {
        $normalized = $this->normalize($message);
        if (preg_match('/\b(\d{1,3})\s*(?:k|bin)(?:le|lik|de|den)?\b/', $normalized, $match)) {
            return (int) $match[1] * 1000;
        }
        if (preg_match('/\b(\d{1,3})[.\s](\d{3})\b/', $normalized, $match)) {
            return (int) ($match[1] . $match[2]);
        }
        if (preg_match('/\b(\d{5,7})\b/', $normalized, $match)) {
            return (int) $match[1];
        }
        return null;
    }

    public function detectScoreType(string $message): ?string
    {
        $normalized = $this->normalize($message);
        $types = [
            'say' => ['sayisal', 'say'],
            'ea' => ['esit agirlik', 'ea'],
            'soz' => ['sozel', 'soz'],
            'dil' => ['yabanci dil', 'dil'],
            'tyt' => ['tyt', 'onlisans'],
        ];
        foreach ($types as $type => $needles) {
            foreach ($needles as $needle) {
                if (preg_match('/\b' . preg_quote($needle, '/') . '\b/', $normalized)) {
                    return $type;
                }
            }
        }
        return null;
    }

    public function normalize(string $value): string
    {
        return strtolower(strtr($value, [
            'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's',
            'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o',
            'ö' => 'o', 'Ç' => 'c', 'ç' => 'c',
        ]));
    }
}
