<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Development\DevelopmentRequestSeeder;
use App\Infrastructure\Deployment\DatabasePurpose;
use App\Infrastructure\Document\DocumentStorage;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class DevController extends Controller
{
    /**
     * Основные шесть профилей закреплены за фиксированными id: на них ссылаются
     * E2E. Фиксированный id не гарантирован на давно живущей development-БД
     * (см. actionSeed() ниже), поэтому переключатель резолвит актуальные id
     * через DevController::actionUsers(), которая читает ad_login отсюда же.
     *
     * @var array<int, array{ad_login: string, display_name: string, email: string, position: string, department: string, roles: list<string>}>
     */
    public const CORE_USERS = [
        1 => [
            'ad_login' => 'dev.user', 'display_name' => 'Максим Умнов',
            'email' => 'dev.user@example.invalid', 'position' => 'Руководитель ИЦ',
            'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_manager'],
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
            'department' => 'СГК', 'roles' => ['employee', 'expert'],
        ],
        5 => [
            'ad_login' => 'dev.security', 'display_name' => 'Олег Воронцов',
            'email' => 'dev.security@example.invalid', 'position' => 'Сотрудник СБ',
            'department' => 'Служба безопасности', 'roles' => ['employee', 'security_officer'],
        ],
        6 => [
            'ad_login' => 'dev.admin', 'display_name' => 'Дарья Королёва',
            'email' => 'dev.admin@example.invalid', 'position' => 'Администратор портала',
            'department' => 'ИТ', 'roles' => ['employee', 'administrator'],
        ],
        7 => [
            'ad_login' => 'dev.laboratory_manager', 'display_name' => 'Ирина Лебедева',
            'email' => 'dev.laboratory_manager@example.invalid', 'position' => 'Руководитель лаборатории',
            'department' => 'Лаборатория', 'roles' => ['employee', 'laboratory_manager'],
        ],
    ];

    /**
     * Остальные исполнители ИЦ по списку ТЗ (раздел 7.5), помимо Кашина С. И.
     * Без фиксированного id: на персистентной development-БД эти числа мог уже
     * занять реально созданный пользователь (например, технический профиль
     * из импорта Bitrix24), и upsert по id молча переписал бы его личность.
     * Идентифицируются уникальным ad_login, реальный id резолвится после записи.
     *
     * @var array<string, array{display_name: string, email: string, position: string, department: string, roles: list<string>}>
     */
    private const ADDITIONAL_EXECUTORS = [
        'dev.executor.naumov' => [
            'display_name' => 'Сергей Наумов', 'email' => 'dev.executor.naumov@example.invalid',
            'position' => 'Исполнитель ИЦ', 'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        'dev.executor.prikul' => [
            'display_name' => 'Сергей Прикуль', 'email' => 'dev.executor.prikul@example.invalid',
            'position' => 'Исполнитель ИЦ', 'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        'dev.executor.shaposhnikov' => [
            'display_name' => 'Сергей Шапошников', 'email' => 'dev.executor.shaposhnikov@example.invalid',
            'position' => 'Исполнитель ИЦ', 'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        'dev.executor.galkin' => [
            'display_name' => 'Виктор Галкин', 'email' => 'dev.executor.galkin@example.invalid',
            'position' => 'Исполнитель ИЦ', 'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        'dev.executor.kozlov' => [
            'display_name' => 'Виктор Козлов', 'email' => 'dev.executor.kozlov@example.invalid',
            'position' => 'Исполнитель ИЦ', 'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
        'dev.executor.nelidova' => [
            'display_name' => 'Ольга Нелидова', 'email' => 'dev.executor.nelidova@example.invalid',
            'position' => 'Исполнитель ИЦ', 'department' => 'Испытательный центр', 'roles' => ['employee', 'ic_executor'],
        ],
    ];

    /**
     * Второй тестовый эксперт — нужен только для проверки переназначения и
     * перехвата заявки между экспертами, в ТЗ 7.5 не входит. Без
     * фиксированного id по той же причине, что и ADDITIONAL_EXECUTORS: на
     * персистентной development-БД фиксированный id мог уже органически занять
     * другой пользователь.
     *
     * @var array<string, array{display_name: string, email: string, position: string, department: string, roles: list<string>}>
     */
    private const ADDITIONAL_EXPERTS = [
        'dev.expert2' => [
            'display_name' => 'Виктор Дорохов', 'email' => 'dev.expert2@example.invalid',
            'position' => 'Эксперт', 'department' => 'СГК', 'roles' => ['employee', 'expert'],
        ],
    ];

    public function actionSeed(): int
    {
        if (!$this->isDevelopmentDatabase()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return $this->seedUsers();
    }

    /**
     * Shared only with the physically mounted test reset command.
     * Deployment controllers must validate their own database before calling it.
     */
    public function seedUsers(): int
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $result = $this->seedUsersInTransaction();
            if ($result !== ExitCode::OK) {
                $transaction->rollBack();
                return $result;
            }

            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        }
    }

    private function seedUsersInTransaction(): int
    {
        $allRoles = array_merge(
            array_column(self::CORE_USERS, 'roles'),
            array_column(self::ADDITIONAL_EXECUTORS, 'roles'),
            array_column(self::ADDITIONAL_EXPERTS, 'roles'),
        );
        $roleCodes = array_unique(array_merge(...$allRoles));
        $roleIds = [];
        foreach ($roleCodes as $roleCode) {
            $roleId = Yii::$app->db->createCommand(
                'SELECT id FROM {{%roles}} WHERE code = :code',
                [':code' => $roleCode],
            )->queryScalar();
            if ($roleId === false) {
                $this->stderr(
                    "Роль '{$roleCode}' отсутствует. Выполните миграции перед dev/seed.\n",
                );
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $roleIds[$roleCode] = (int) $roleId;
        }

        $now = gmdate('Y-m-d H:i:s');
        foreach (self::CORE_USERS as $userId => $user) {
            // Фиксированный id безопасен только пока под ним либо ещё нет
            // строки, либо уже сидированный тем же ad_login профиль. Список
            // CORE_USERS со временем растёт (см. #84, где добавился
            // dev.admin=6) — на давно живущей development-БД этот id мог уже
            // раньше органически достаться ADDITIONAL_EXECUTORS/EXPERTS
            // через автоинкремент, когда CORE_USERS был короче. Отказ от
            // всего сида в этом случае оставлял бы dev.admin несозданным
            // навсегда — вместо этого сидируем именно этот профиль по
            // ad_login, как ADDITIONAL_EXECUTORS/EXPERTS ниже, не трогая
            // чужую строку на конфликтующем id.
            $existingAdLogin = Yii::$app->db->createCommand(
                'SELECT ad_login FROM {{%users}} WHERE id = :id',
                [':id' => $userId],
            )->queryScalar();
            if ($existingAdLogin !== false && $existingAdLogin !== $user['ad_login']) {
                $this->stdout(
                    "Идентификатор основного пользователя id={$userId} уже занят '{$existingAdLogin}'; "
                    . "профиль '{$user['ad_login']}' будет создан по ad_login без изменения чужой записи.\n",
                );
                $this->seedByAdLogin([$user['ad_login'] => $user], $roleIds, $now);
                continue;
            }
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
            $this->assignRoles($userId, $user['roles'], $roleIds, $now);
        }

        $this->seedByAdLogin(self::ADDITIONAL_EXECUTORS, $roleIds, $now);
        $this->seedByAdLogin(self::ADDITIONAL_EXPERTS, $roleIds, $now);

        $this->stdout("Пользователи и роли среды разработки подготовлены.\n");
        return ExitCode::OK;
    }

    public function actionSeedRequests(): int
    {
        if (!$this->isDevelopmentDatabase()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $result = (new DevelopmentRequestSeeder(
                Yii::$app->db,
                new DocumentStorage(getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents'),
            ))->seed();
        } catch (\Throwable $error) {
            $this->stderr($error->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(
            "Реестр среды разработки сброшен: заявок — {$result['requests']}, "
            . "комментариев — {$result['comments']}, документов — {$result['documents']}.\n",
        );
        return ExitCode::OK;
    }

    private function isDevelopmentDatabase(): bool
    {
        $database = Yii::$app->db->createCommand('SELECT DATABASE()')->queryScalar();
        if (!is_string($database) || !DatabasePurpose::isDevelopment($database)) {
            $actual = is_string($database) && $database !== '' ? $database : '(unknown)';
            $this->stderr(
                "Заполнение запрещено: имя подключённой БД '{$actual}' должно оканчиваться на _dev.\n",
            );
            return false;
        }

        return true;
    }

    /**
     * @param array<string, array{display_name: string, email: string, position: string, department: string, roles: list<string>}> $users
     * @param array<string, int> $roleIds
     */
    private function seedByAdLogin(array $users, array $roleIds, string $now): void
    {
        foreach ($users as $adLogin => $user) {
            Yii::$app->db->createCommand()->upsert('{{%users}}', [
                'ad_login' => $adLogin,
                'display_name' => $user['display_name'],
                'email' => $user['email'],
                'position' => $user['position'],
                'department' => $user['department'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            $userId = (int) Yii::$app->db->createCommand(
                'SELECT id FROM {{%users}} WHERE ad_login = :ad_login',
                [':ad_login' => $adLogin],
            )->queryScalar();
            $this->assignRoles($userId, $user['roles'], $roleIds, $now);
        }
    }

    /**
     * @param list<string> $roleCodes
     * @param array<string, int> $roleIds
     */
    private function assignRoles(int $userId, array $roleCodes, array $roleIds, string $now): void
    {
        foreach ($roleCodes as $roleCode) {
            Yii::$app->db->createCommand()->upsert('{{%user_roles}}', [
                'user_id' => $userId,
                'role_id' => $roleIds[$roleCode],
                'assigned_by' => null,
                'created_at' => $now,
            ])->execute();
        }
    }
}
