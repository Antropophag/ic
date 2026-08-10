<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260810_000002_allow_unknown_legacy_sample_quantity extends Migration
{
    public function safeUp(): void
    {
        $this->alterColumn('{{%requests}}', 'sample_quantity', $this->integer()->unsigned());
        $this->addColumn(
            '{{%requests}}',
            'legacy_sample_quantity_raw',
            $this->text()->after('sample_quantity'),
        );
        $this->execute(
            'ALTER TABLE {{%requests}} ADD CONSTRAINT chk_requests_unknown_quantity_is_legacy '
            . 'CHECK (sample_quantity IS NOT NULL OR (is_archived = 1 AND legacy_id IS NOT NULL))',
        );
    }

    public function safeDown(): void
    {
        $unknownLegacyQuantities = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%requests}} WHERE sample_quantity IS NULL',
        )->queryScalar();
        if ($unknownLegacyQuantities > 0) {
            throw new RuntimeException(
                'Cannot restore NOT NULL sample_quantity while imported requests contain unknown quantities.',
            );
        }

        $this->execute(
            'ALTER TABLE {{%requests}} DROP CONSTRAINT chk_requests_unknown_quantity_is_legacy',
        );
        $this->dropColumn('{{%requests}}', 'legacy_sample_quantity_raw');
        $this->alterColumn('{{%requests}}', 'sample_quantity', $this->integer()->unsigned()->notNull());
    }
}
