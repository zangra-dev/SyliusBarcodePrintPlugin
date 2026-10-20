<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the label template, template variable and label printer tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE zangra_barcode_label_template (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, enabled TINYINT(1) NOT NULL, UNIQUE INDEX uniq_zangra_barcode_label_template_code (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE zangra_barcode_label_template_variable (id INT AUTO_INCREMENT NOT NULL, template_id INT NOT NULL, variable_key VARCHAR(64) NOT NULL, label VARCHAR(255) NOT NULL, type VARCHAR(16) NOT NULL, default_value VARCHAR(255) DEFAULT NULL, required TINYINT(1) NOT NULL, position INT NOT NULL, INDEX IDX_2B340BC65DA0FB8 (template_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE zangra_barcode_label_printer (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, transport VARCHAR(32) NOT NULL, agent_url VARCHAR(255) NOT NULL, enabled TINYINT(1) NOT NULL, UNIQUE INDEX uniq_zangra_barcode_label_printer_code (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE zangra_barcode_label_template_variable ADD CONSTRAINT FK_ZANGRA_BARCODE_VARIABLE_TEMPLATE FOREIGN KEY (template_id) REFERENCES zangra_barcode_label_template (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE zangra_barcode_label_template_variable DROP FOREIGN KEY FK_ZANGRA_BARCODE_VARIABLE_TEMPLATE');
        $this->addSql('DROP TABLE zangra_barcode_label_template_variable');
        $this->addSql('DROP TABLE zangra_barcode_label_template');
        $this->addSql('DROP TABLE zangra_barcode_label_printer');
    }
}
