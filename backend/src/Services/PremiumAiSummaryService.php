<?php

declare(strict_types=1);

namespace DersRotasi\Services;

use DersRotasi\AI\OpenAiClient;

final class PremiumAiSummaryService
{
    public function __construct(private readonly OpenAiClient $client)
    {
    }

    public function preferenceSummary(array $analysis, string $safetyIdentifier): array
    {
        return $this->summarize(
            'Tercih listesi analizi',
            $analysis,
            $safetyIdentifier,
            'Kullanıcının liste dağılımını ve önemli uyarıları 2-4 kısa Türkçe cümleyle yorumla.'
        );
    }

    public function comparisonSummary(array $comparison, string $safetyIdentifier): array
    {
        return $this->summarize(
            'İki program karşılaştırması',
            $comparison,
            $safetyIdentifier,
            'İki programın yıllara göre istikrarını ve kullanıcı sırası varsa risk konumunu 2-4 kısa Türkçe cümleyle karşılaştır.'
        );
    }

    private function summarize(
        string $purpose,
        array $facts,
        string $safetyIdentifier,
        string $task
    ): array {
        $instructions = <<<'PROMPT'
Sen Dersrotası Premium analiz asistanısın. Yalnızca verilen doğrulanmış JSON verisini kullan.
JSON'da bulunmayan üniversite, program, sayı, yıl, başarı sırası, puan, kontenjan veya yerleşme olasılığı üretme.
Eksik değerleri tahmin etme. Kesin yerleşme garantisi verme. Öneriyi kısa, dengeli ve Türkçe yaz.
PROMPT;
        $payload = json_encode(
            ['purpose' => $purpose, 'task' => $task, 'verified_facts' => $facts],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $response = $this->client->respond(
            $instructions,
            [['role' => 'user', 'content' => $payload]],
            $safetyIdentifier
        );

        return [
            'summary' => $response['answer'],
            'meta' => $response['meta'],
        ];
    }
}
