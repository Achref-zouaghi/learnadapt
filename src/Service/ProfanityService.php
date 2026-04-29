<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Snipe\BanBuilder\CensorWords;

class ProfanityService
{
    private Connection $connection;
    private CensorWords $censor;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->censor = new CensorWords();
        $this->censor->setDictionary(['en-us', 'fr', 'es']);
        // Demo word — remove after presentation
        $this->censor->addFromArray(['test']);
    }

    /**
     * Check text for profanity. Returns ['hasProfanity' => bool, 'matched' => [...], 'clean' => string]
     */
    public function check(string $text): array
    {
        if (trim($text) === '') {
            return ['hasProfanity' => false, 'matched' => [], 'clean' => $text];
        }

        $result = $this->censor->censorString($text);

        return [
            'hasProfanity' => !empty($result['matched']),
            'matched' => array_values($result['matched']),
            'clean' => $result['clean'],
        ];
    }

    /**
     * Record a strike for a user. Returns the new total strike count.
     */
    public function addStrike(int $userId, string $reason, string $detectedWords): int
    {
        $this->ensureStrikesTable();

        $this->connection->executeStatement(
            'INSERT INTO user_strikes (user_id, reason, detected_words, created_at) VALUES (?, ?, ?, NOW())',
            [$userId, $reason, $detectedWords]
        );

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_strikes WHERE user_id = ?',
            [$userId]
        );

        // Auto-mute if 3+ strikes: set a muted_until timestamp
        if ($count >= 3) {
            $this->connection->executeStatement(
                'UPDATE users SET muted_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?',
                [$this->getMuteDays($count), $userId]
            );
        }

        return $count;
    }

    /**
     * Check if a user is currently muted.
     */
    public function isUserMuted(int $userId): bool
    {
        $this->ensureMutedColumn();

        $mutedUntil = $this->connection->fetchOne(
            'SELECT muted_until FROM users WHERE id = ?',
            [$userId]
        );

        if (!$mutedUntil) {
            return false;
        }

        return new \DateTime($mutedUntil) > new \DateTime();
    }

    /**
     * Get user's strike count.
     */
    public function getStrikeCount(int $userId): int
    {
        $this->ensureStrikesTable();

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_strikes WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * Get escalating mute duration in days based on strike count.
     */
    private function getMuteDays(int $strikes): int
    {
        if ($strikes >= 10) return 30;
        if ($strikes >= 7) return 14;
        if ($strikes >= 5) return 7;
        if ($strikes >= 3) return 1;
        return 0;
    }

    private function ensureStrikesTable(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['user_strikes'])) {
            $this->connection->executeStatement('
                CREATE TABLE user_strikes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    reason VARCHAR(255) NOT NULL,
                    detected_words TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_strikes_user (user_id)
                )
            ');
        }
    }

    private function ensureMutedColumn(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('users');
        if (!isset($columns['muted_until'])) {
            $this->connection->executeStatement('ALTER TABLE users ADD muted_until DATETIME DEFAULT NULL');
        }
    }
}
