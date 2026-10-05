<?php

declare(strict_types=1);

use yii\db\Migration;

final class m261005_000001_create_request_objects extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%request_objects}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'position' => $this->smallInteger()->notNull(),
            'product_name' => $this->string(2000)->notNull(),
            'sample_quantity' => $this->text(),
        ]);
        $this->createIndex('uq_request_object_position', '{{%request_objects}}', ['request_id', 'position'], true);
        $this->addForeignKey('fk_request_object_request', '{{%request_objects}}', 'request_id', '{{%requests}}', 'id', 'CASCADE');
        $this->execute(
            'INSERT INTO {{%request_objects}} (request_id, position, product_name, sample_quantity) '
            . 'SELECT id, 1, product_name, COALESCE(CAST(sample_quantity AS CHAR), legacy_sample_quantity_raw) FROM {{%requests}}',
        );
        $this->execute('ALTER TABLE {{%requests}} DROP CONSTRAINT chk_requests_unknown_quantity_is_legacy');
    }

    public function safeDown(): void
    {
        $multiple = $this->db->createCommand('SELECT 1 FROM {{%request_objects}} GROUP BY request_id HAVING COUNT(*) > 1 LIMIT 1')->queryScalar();
        $textQuantity = $this->db->createCommand(
            "SELECT 1 FROM {{%requests}} WHERE sample_quantity IS NULL AND NOT (source = 'bitrix24' AND is_archived = 1 AND legacy_id IS NOT NULL) LIMIT 1",
        )->queryScalar();
        if ($multiple !== false || $textQuantity !== false) {
            throw new RuntimeException('Cannot roll back request objects without losing positions or textual quantities; restore the pre-migration backup.');
        }
        $this->execute(
            'ALTER TABLE {{%requests}} ADD CONSTRAINT chk_requests_unknown_quantity_is_legacy '
            . "CHECK (sample_quantity IS NOT NULL OR (source = 'bitrix24' AND is_archived = 1 AND legacy_id IS NOT NULL))",
        );
        $this->dropTable('{{%request_objects}}');
    }
}
