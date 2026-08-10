<?php

declare(strict_types=1);

use App\Infrastructure\Import\LegacyProductNameSchemaGuard;
use yii\db\Migration;

final class m260810_000003_expand_legacy_product_name extends Migration
{
    public function safeUp(): void
    {
        $this->alterColumn('{{%requests}}', 'product_name', $this->string(2000)->notNull());
    }

    public function safeDown(): void
    {
        LegacyProductNameSchemaGuard::assertCanRestoreLimit($this->db, 500);
        $this->alterColumn('{{%requests}}', 'product_name', $this->string(500)->notNull());
    }
}
