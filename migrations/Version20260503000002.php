<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DEFAULT 0 to earned_points and score_percent to fix INSERT without those columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_attempts ALTER COLUMN earned_points SET DEFAULT 0');
        $this->addSql('ALTER TABLE quiz_attempts ALTER COLUMN score_percent SET DEFAULT 0.00');
        $this->addSql('ALTER TABLE quiz_answers ALTER COLUMN earned_points SET DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_attempts ALTER COLUMN earned_points DROP DEFAULT');
        $this->addSql('ALTER TABLE quiz_attempts ALTER COLUMN score_percent DROP DEFAULT');
        $this->addSql('ALTER TABLE quiz_answers ALTER COLUMN earned_points DROP DEFAULT');
    }
}
