<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the default flag to the label template and label printer tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE zangra_barcode_label_template ADD is_default TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE zangra_barcode_label_printer ADD is_default TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE zangra_barcode_label_template DROP is_default');
        $this->addSql('ALTER TABLE zangra_barcode_label_printer DROP is_default');
    }
}
