<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Anti-Cheat: add cheat tracking columns to quiz_attempts.
 *
 * New columns:
 *   cheat_flags  JSON    — array of {type, source, timestamp} objects
 *   cheat_ended  TINYINT — 1 if quiz was force-terminated due to cheating
 */
final class Version20260503000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anti-cheat columns (cheat_flags, cheat_ended) to quiz_attempts';
    }

    public function up(Schema $schema): void
    {
        // Add cheat_flags — stores JSON array of all cheat events
        $this->addSql(
            'ALTER TABLE quiz_attempts
             ADD COLUMN cheat_flags JSON    NULL     DEFAULT NULL COMMENT "Array of cheat events [{type, source, timestamp}]"
                 AFTER level_result,
             ADD COLUMN cheat_ended TINYINT(1) NOT NULL DEFAULT 0  COMMENT "1 = quiz force-terminated due to cheating"
                 AFTER cheat_flags'
        );

        // Index so admin can quickly find all cheating attempts
        $this->addSql(
            'ALTER TABLE quiz_attempts
             ADD INDEX idx_qa_cheat_ended (cheat_ended)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_attempts DROP INDEX idx_qa_cheat_ended');
        $this->addSql('ALTER TABLE quiz_attempts DROP COLUMN cheat_ended, DROP COLUMN cheat_flags');
    }
}
