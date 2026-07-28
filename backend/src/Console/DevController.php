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

        $this->stdout("Development user is ready.\n");
        return ExitCode::OK;
    }
}
