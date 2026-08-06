<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000001_create_request_core extends Migration
{
    public function safeUp(): void
    {
        $options = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        $this->createTable('{{%users}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'ad_login' => $this->string(128)->notNull()->unique(),
            'display_name' => $this->string(255)->notNull(),
            'email' => $this->string(255),
            'position' => $this->string(255),
            'department' => $this->string(255),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->dateTime(6)->notNull(),
            'updated_at' => $this->dateTime(6)->notNull(),
        ], $options);
        $this->createTable('{{%request_number_sequence}}', [
            'id' => $this->tinyInteger()->unsigned()->notNull(),
            'value' => $this->bigInteger()->unsigned()->notNull(),
            'PRIMARY KEY ([[id]])',
        ], $options);
        $this->insert('{{%request_number_sequence}}', ['id' => 1, 'value' => 0]);
        $this->createTable('{{%requests}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'number' => $this->bigInteger()->unsigned()->notNull()->unique(),
            'legacy_id' => $this->string(128)->unique(),
            'initiator_id' => $this->bigInteger()->unsigned()->notNull(),
            'status' => $this->string(32)->notNull(),
            'product_name' => $this->string(500)->notNull(),
            'manufacturer' => $this->string(500)->notNull(),
            'supplier' => $this->string(500)->notNull(),
            'sample_quantity' => $this->integer()->unsigned()->notNull(),
            'test_method' => $this->text()->notNull(),
            'revision' => $this->integer()->unsigned()->notNull()->defaultValue(1),
            'lock_version' => $this->integer()->unsigned()->notNull()->defaultValue(1),
            'created_at' => $this->dateTime(6)->notNull(),
            'updated_at' => $this->dateTime(6)->notNull(),
        ], $options);
        $this->addForeignKey('fk_requests_initiator', '{{%requests}}', 'initiator_id', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('idx_requests_status_created', '{{%requests}}', ['status', 'created_at']);
        $this->createTable('{{%request_assignments}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'assignment_type' => $this->string(16)->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'assigned_by' => $this->bigInteger()->unsigned()->notNull(),
            'valid_from' => $this->dateTime(6)->notNull(),
            'valid_to' => $this->dateTime(6),
        ], $options);
        $this->addForeignKey('fk_assignment_request', '{{%request_assignments}}', 'request_id', '{{%requests}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_assignment_user', '{{%request_assignments}}', 'user_id', '{{%users}}', 'id', 'RESTRICT');
        $this->addForeignKey('fk_assignment_author', '{{%request_assignments}}', 'assigned_by', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('idx_assignment_current', '{{%request_assignments}}', ['request_id', 'assignment_type', 'valid_to']);
        $this->createTable('{{%request_transitions}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'actor_id' => $this->bigInteger()->unsigned()->notNull(),
            'from_status' => $this->string(32),
            'to_status' => $this->string(32)->notNull(),
            'action' => $this->string(32)->notNull(),
            'reason' => $this->text(),
            'rule_id' => $this->string(16)->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], $options);
        $this->addForeignKey('fk_transition_request', '{{%request_transitions}}', 'request_id', '{{%requests}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_transition_actor', '{{%request_transitions}}', 'actor_id', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('idx_transition_history', '{{%request_transitions}}', ['request_id', 'created_at']);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%request_transitions}}');
        $this->dropTable('{{%request_assignments}}');
        $this->dropTable('{{%requests}}');
        $this->dropTable('{{%request_number_sequence}}');
        $this->dropTable('{{%users}}');
    }
}
