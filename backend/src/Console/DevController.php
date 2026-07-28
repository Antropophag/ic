<?php

declare(strict_types=1);

namespace App\Console;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class DevController extends Controller
{
    /** @var array<int, array{ad_login: string, display_name: string, email: string, position: string, department: string, roles: list<string>}> */
    private const USERS = [
        1 => [
            'ad_login' => 'dev.user', 'display_name' => 'Максим Умнов',
            'email' => 'dev.user@example.invalid', 'position' => 'Разработчик',
            'department' => 'Тестовое подразделение', 'roles' => ['employee', 'ic_manager'],
        ],
        2 => [
            'ad_login' => 'dev.executor', 'display_name' => 'Сергей Кашин',
            'email' => 'dev.executor@example.invalid', 'position' => 'Исполнитель ИЦ',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        3 => [
            'ad_login' => 'dev.employee', 'display_name' => 'Тестовый сотрудник',
            'email' => 'dev.employee@example.invalid', 'position' => 'Сотрудник',
            'department' => 'Тестовое подразделение', 'roles' => ['employee'],
        ],
        4 => [
            'ad_login' => 'dev.expert', 'display_name' => 'Анна Смирнова',
            'email' => 'dev.expert@example.invalid', 'position' => 'Эксперт',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'expert'],
        ],
        5 => [
            'ad_login' => 'dev.security', 'display_name' => 'Олег Воронцов',
            'email' => 'dev.security@example.invalid', 'position' => 'Сотрудник СБ',
            'department' => 'Служба безопасности', 'roles' => ['employee', 'security_officer'],
        ],
        // Остальные исполнители ИЦ по списку ТЗ (раздел 7.5), помимо Кашина С. И.
        6 => [
            'ad_login' => 'dev.executor.naumov', 'display_name' => 'Сергей Наумов',
            'email' => 'dev.executor.naumov@example.invalid', 'position' => 'Исполнитель ИЦ',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        7 => [
            'ad_login' => 'dev.executor.prikul', 'display_name' => 'Сергей Прикуль',
            'email' => 'dev.executor.prikul@example.invalid', 'position' => 'Исполнитель ИЦ',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        8 => [
            'ad_login' => 'dev.executor.shaposhnikov', 'display_name' => 'Сергей Шапошников',
            'email' => 'dev.executor.shaposhnikov@example.invalid', 'position' => 'Исполнитель ИЦ',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        9 => [
            'ad_login' => 'dev.executor.galkin', 'display_name' => 'Виктор Галкин',
            'email' => 'dev.executor.galkin@example.invalid', 'position' => 'Исполнитель ИЦ',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        10 => [
            'ad_login' => 'dev.executor.kozlov', 'display_name' => 'Виктор Козлов',
            'email' => 'dev.executor.kozlov@example.invalid', 'position' => 'Исполнитель ИЦ',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        11 => [
            'ad_login' => 'dev.executor.nelidova', 'display_name' => 'Ольга Нелидова',
            'email' => 'dev.executor.nelidova@example.invalid', 'position' => 'Исполнитель ИЦ',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
    ];

    public function actionSeed(): int
    {
        if (YII_ENV !== 'dev') {
            $this->stderr("Development seed is disabled outside APP_ENV=dev.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $roleCodes = array_unique(array_merge(...array_column(self::USERS, 'roles')));
        $roleIds = [];
        foreach ($roleCodes as $roleCode) {
            $roleId = Yii::$app->db->createCommand(
                'SELECT id FROM {{%roles}} WHERE code = :code',
                [':code' => $roleCode],
            )->queryScalar();
            if ($roleId === false) {
                $this->stderr(
                    "Role '{$roleCode}' is missing. Run database migrations before dev/seed.\n",
                );
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $roleIds[$roleCode] = (int) $roleId;
        }

        $now = gmdate('Y-m-d H:i:s');
        foreach (self::USERS as $userId => $user) {
            Yii::$app->db->createCommand()->upsert('{{%users}}', [
                'id' => $userId,
                'ad_login' => $user['ad_login'],
                'display_name' => $user['display_name'],
                'email' => $user['email'],
                'position' => $user['position'],
                'department' => $user['department'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            foreach ($user['roles'] as $roleCode) {
                Yii::$app->db->createCommand()->upsert('{{%user_roles}}', [
                    'user_id' => $userId,
                    'role_id' => $roleIds[$roleCode],
                    'assigned_by' => null,
                    'created_at' => $now,
                ])->execute();
            }
        }

        $this->stdout("Development users and roles are ready.\n");
        return ExitCode::OK;
    }
}
