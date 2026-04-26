<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260426091328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // NOTE: user_payment_methods, user_profile_photos, user_subscriptions, webauthn_credentials
        // are intentionally kept — they have no ORM Entity but are used via raw SQL queries.
        $this->addSql('ALTER TABLE courses ADD slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A9A55A4C989D9B62 ON courses (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_payment_methods (id BIGINT AUTO_INCREMENT NOT NULL, user_id BIGINT NOT NULL, card_holder VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, card_last4 CHAR(4) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, card_brand VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, expiry_month CHAR(2) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, expiry_year CHAR(2) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, is_default TINYINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX fk_upm_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE user_profile_photos (id BIGINT AUTO_INCREMENT NOT NULL, user_id BIGINT NOT NULL, photo_data LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, is_active TINYINT DEFAULT 0 NOT NULL, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE user_subscriptions (id BIGINT AUTO_INCREMENT NOT NULL, user_id BIGINT NOT NULL, plan VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, billing_cycle VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT \'monthly\' NOT NULL COLLATE `utf8mb4_general_ci`, amount NUMERIC(8, 2) NOT NULL, currency CHAR(3) CHARACTER SET utf8mb4 DEFAULT \'USD\' NOT NULL COLLATE `utf8mb4_general_ci`, payment_method_id BIGINT NOT NULL, status VARCHAR(20) CHARACTER SET utf8mb4 DEFAULT \'active\' NOT NULL COLLATE `utf8mb4_general_ci`, started_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, expires_at DATETIME NOT NULL, INDEX fk_us_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE webauthn_credentials (id BIGINT AUTO_INCREMENT NOT NULL, user_id BIGINT NOT NULL, credential_id VARCHAR(512) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, public_key TEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, counter BIGINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE INDEX idx_cred_id (credential_id(255)), INDEX idx_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE user_payment_methods ADD CONSTRAINT `fk_upm_user` FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_subscriptions ADD CONSTRAINT `fk_us_user` FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_feedback ADD media_type VARCHAR(16) DEFAULT NULL, ADD media_path VARCHAR(255) DEFAULT NULL, ADD media_files TEXT DEFAULT NULL');
        $this->addSql('DROP INDEX UNIQ_A9A55A4C989D9B62 ON courses');
        $this->addSql('ALTER TABLE courses DROP slug');
        $this->addSql('ALTER TABLE forum_posts ADD media_files TEXT DEFAULT NULL, ADD parent_post_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_answers DROP FOREIGN KEY FK_428A6BA6B191BE6B');
        $this->addSql('ALTER TABLE quiz_answers DROP FOREIGN KEY FK_428A6BA61E27F6BF');
        $this->addSql('DROP INDEX idx_428a6ba61e27f6bf ON quiz_answers');
        $this->addSql('CREATE INDEX FK_428A6BA61E27F6BF ON quiz_answers (question_id)');
        $this->addSql('DROP INDEX idx_428a6ba6b191be6b ON quiz_answers');
        $this->addSql('CREATE INDEX FK_428A6BA6B191BE6B ON quiz_answers (attempt_id)');
        $this->addSql('ALTER TABLE quiz_answers ADD CONSTRAINT FK_428A6BA6B191BE6B FOREIGN KEY (attempt_id) REFERENCES quiz_attempts (id)');
        $this->addSql('ALTER TABLE quiz_answers ADD CONSTRAINT FK_428A6BA61E27F6BF FOREIGN KEY (question_id) REFERENCES quiz_questions (id)');
        $this->addSql('ALTER TABLE users ADD muted_until DATETIME DEFAULT NULL, ADD plan VARCHAR(20) DEFAULT \'free\' NOT NULL, ADD plan_expires_at DATETIME DEFAULT NULL');
    }
}
