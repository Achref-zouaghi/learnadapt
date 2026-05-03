<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Calls the Python AI service for content toxicity analysis.
 * Provides graceful degradation — if Python is unreachable, posts are allowed.
 *
 * Actions returned:
 *   allow   → publish immediately (score < 0.45)
 *   pending → put in admin review queue (0.45 ≤ score < 0.80)
 *   block   → reject + add profanity strike (score ≥ 0.80)
 */
class ContentModerationService
{
    private const PYTHON_URL      = 'http://127.0.0.1:8765';
    private const ENDPOINT        = '/moderate-content';
    private const REQUEST_TIMEOUT = 4;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Analyze text for toxicity.
     *
     * @return array{
     *   action: 'allow'|'pending'|'block',
     *   classification: 'normal'|'toxic'|'hateful'|'spam',
     *   toxicity_score: float,
     *   reason: string,
     * }
     */
    public function analyze(string $text, string $language = 'en'): array
    {
        // Empty text is always safe
        if (trim($text) === '') {
            return $this->safe();
        }

        $secret = (string) ($_ENV['ANTI_CHEAT_SECRET'] ?? getenv('ANTI_CHEAT_SECRET') ?? 'dev_secret_change_in_production');

        $payload = json_encode(['text' => $text, 'language' => $language]);
        $signature = hash_hmac('sha256', $payload, $secret);
        $url = self::PYTHON_URL . self::ENDPOINT;

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-Anti-Cheat-Signature: ' . $signature,
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'       => $payload,
                'timeout'       => self::REQUEST_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        try {
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                $this->logger->warning('ContentModeration: Python service unreachable — allowing content');
                return $this->safe();
            }
            $result = json_decode($raw, true);
            if (!is_array($result) || !isset($result['action'])) {
                $this->logger->warning('ContentModeration: Unexpected response', ['raw' => $raw]);
                return $this->safe();
            }
            return [
                'action'         => $result['action'],
                'classification' => $result['classification'] ?? 'normal',
                'toxicity_score' => (float) ($result['toxicity_score'] ?? 0.0),
                'reason'         => $result['reason'] ?? '',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('ContentModeration: ' . $e->getMessage());
            return $this->safe();
        }
    }

    private function safe(): array
    {
        return ['action' => 'allow', 'classification' => 'normal', 'toxicity_score' => 0.0, 'reason' => ''];
    }
}
