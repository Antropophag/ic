<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000008_add_request_color extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%requests}}',
            'color',
            $this->string(16)->notNull()->defaultValue('white')->after('lock_version'),
        );
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%requests}}', 'color');
    }
}
