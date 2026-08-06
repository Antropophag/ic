<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000002_create_access_and_audit extends Migration
{
    public function safeUp(): void
    {
        $options = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        $this->createTable('{{%roles}}', [
            'id' => $this->primaryKey()->unsigned(),
            'code' => $this->string(64)->notNull()->unique(),
            'name' => $this->string(128)->notNull(),
        ], $options);
        $this->batchInsert('{{%roles}}', ['code', 'name'], [
            ['employee', 'Сотрудник'],
            ['ic_executor', 'Исполнитель ИЦ'],
            ['expert', 'Эксперт'],
            ['ic_manager', 'Руководитель ИЦ'],
            ['laboratory_manager', 'Руководитель лаборатории'],
            ['security_officer', 'Сотрудник СБ'],
            ['administrator', 'Администратор'],
        ]);

        $this->createTable('{{%user_roles}}', [
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'role_id' => $this->integer()->unsigned()->notNull(),
            'assigned_by' => $this->bigInteger()->unsigned(),
            'created_at' => $this->dateTime(6)->notNull(),
            'PRIMARY KEY ([[user_id]], [[role_id]])',
        ], $options);
        $this->addForeignKey('fk_user_role_user', '{{%user_roles}}', 'user_id', '{{%users}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_user_role_role', '{{%user_roles}}', 'role_id', '{{%roles}}', 'id', 'RESTRICT');
        $this->addForeignKey('fk_user_role_author', '{{%user_roles}}', 'assigned_by', '{{%users}}', 'id', 'SET NULL');

        $this->createTable('{{%audit_events}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'event_type' => $this->string(64)->notNull(),
            'entity_type' => $this->string(64)->notNull(),
            'entity_id' => $this->bigInteger()->unsigned()->notNull(),
            'actor_id' => $this->bigInteger()->unsigned()->notNull(),
            'rule_id' => $this->string(16)->notNull(),
            'payload_json' => $this->json()->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], $options);
        $this->addForeignKey('fk_audit_actor', '{{%audit_events}}', 'actor_id', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('idx_audit_entity', '{{%audit_events}}', ['entity_type', 'entity_id', 'created_at']);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%audit_events}}');
        $this->dropTable('{{%user_roles}}');
        $this->dropTable('{{%roles}}');
    }
}
