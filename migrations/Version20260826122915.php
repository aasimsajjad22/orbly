<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826122915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD google_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_verified BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE users ALTER password DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E976F5C865 ON users (google_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_1483A5E976F5C865');
        $this->addSql('ALTER TABLE users DROP google_id');
        $this->addSql('ALTER TABLE users DROP email_verified');
        $this->addSql('ALTER TABLE users ALTER password SET NOT NULL');
    }
}
