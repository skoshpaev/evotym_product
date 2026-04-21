<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create products table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE products (
                id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10, 2) NOT NULL,
                quantity INT NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE products');
    }
}
