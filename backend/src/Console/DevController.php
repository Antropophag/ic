<?php

declare(strict_types=1);

namespace App\Console;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class DevController extends Controller
{
    public function actionSeed(): int
    {
        if (YII_ENV !== 'dev') {
            $this->stderr("Development seed is disabled outside APP_ENV=dev.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        Yii::$app->db->createCommand()->upsert('{{%users}}', [
            'id' => 1,
            'ad_login' => 'dev.user',
            'display_name' => 'Максим Умнов',
            'email' => 'dev.user@example.invalid',
            'position' => 'Разработчик',
            'department' => 'Тестовое подразделение',
            'is_active' => true,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        Yii::$app->db->createCommand()->upsert('{{%users}}', [
            'id' => 2,
            'ad_login' => 'dev.executor',
            'display_name' => 'Сергей Кашин',
            'email' => 'dev.executor@example.invalid',
            'position' => 'Исполнитель ИЦ',
            'department' => 'Испытательный центр',
            'is_active' => true,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        foreach ([[1, 'employee'], [1, 'ic_manager'], [2, 'employee'], [2, 'ic_executor']] as [$userId, $roleCode]) {
            $roleId = Yii::$app->db->createCommand(
                'SELECT id FROM {{%roles}} WHERE code = :code',
                [':code' => $roleCode],
            )->queryScalar();
            Yii::$app->db->createCommand()->upsert('{{%user_roles}}', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_by' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ])->execute();
        }

        $this->stdout("Development users and roles are ready.\n");
        return ExitCode::OK;
    }
}
