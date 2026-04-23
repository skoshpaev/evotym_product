<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last processed order event timestamp and outbox/inbox tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD last_order_event_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        $this->addSql(
            'CREATE TABLE outbox (
                event_id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) DEFAULT NULL,
                `event` JSON NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_67D7686B4584665A (product_id),
                INDEX IDX_67D7686B6BF700BD20D37E491999366 (status, created_at),
                PRIMARY KEY(event_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE inbox (
                event_id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) DEFAULT NULL,
                `event` JSON NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_DD5A75A4584665A (product_id),
                INDEX IDX_DD5A75A6BF700BD20D37E491999366 (status, created_at),
                PRIMARY KEY(event_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql('ALTER TABLE outbox ADD CONSTRAINT FK_67D7686B4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbox ADD CONSTRAINT FK_DD5A75A4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbox DROP FOREIGN KEY FK_67D7686B4584665A');
        $this->addSql('ALTER TABLE inbox DROP FOREIGN KEY FK_DD5A75A4584665A');
        $this->addSql('DROP TABLE outbox');
        $this->addSql('DROP TABLE inbox');
        $this->addSql('ALTER TABLE products DROP last_order_event_at');
    }
}
