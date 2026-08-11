<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260811_000001_add_user_activity_timestamps extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%users}}', 'last_login_at', $this->dateTime(6)->null()->after('is_active'));
        $this->addColumn('{{%users}}', 'last_activity_at', $this->dateTime(6)->null()->after('last_login_at'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%users}}', 'last_activity_at');
        $this->dropColumn('{{%users}}', 'last_login_at');
    }
}
