<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Identity\AdministratorBootstrap;
use App\Infrastructure\Identity\BreakGlassConfiguration;
use App\Infrastructure\Identity\BreakGlassIdentityProvisioner;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class AdminController extends Controller
{
    public function actionProvisionBreakGlass(): int
    {
        try {
            $result = (new BreakGlassIdentityProvisioner(
                Yii::$app->db,
                BreakGlassConfiguration::fromEnvironment(),
            ))->provision();
        } catch (\Throwable $error) {
            Yii::error($this->failureDiagnostic($error, 'Break-glass provisioning'), 'admin.break_glass');
            $this->stderr("Подготовка аварийной учётной записи завершилась с ошибкой.\n");
            return ExitCode::DATAERR;
        }

        if (!$result['enabled']) {
            $this->stdout("Аварийный вход выключен; подготовка identity не требуется.\n");
            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "Аварийная учётная запись подготовлена: создана — %s, роль назначена — %s, лишних ролей удалено — %d.\n",
            $result['userCreated'] ? 'да' : 'нет',
            $result['roleAssigned'] ? 'да' : 'нет',
            $result['rolesRemoved'],
        ));
        return ExitCode::OK;
    }

    public function actionBootstrap(): int
    {
        $rawConfigured = getenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        $configured = trim($rawConfigured === false ? '' : $rawConfigured);
        /** @var list<string> $adLogins */
        $adLogins = $configured === '' ? [] : explode(',', $configured);

        try {
            $result = (new AdministratorBootstrap(Yii::$app->db))->bootstrap($adLogins);
        } catch (\Throwable $error) {
            Yii::error($this->failureDiagnostic($error), 'admin.bootstrap');
            $message = $error instanceof \RuntimeException
                && $error->getMessage() === 'No active local administrator exists; configure BOOTSTRAP_ADMIN_AD_LOGINS.'
                ? "Не найден активный локальный администратор; настройте BOOTSTRAP_ADMIN_AD_LOGINS.\n"
                : "Первичная настройка администраторов завершилась с ошибкой; "
                    . "подробности см. в журналах приложения.\n";
            $this->stderr($message);
            return ExitCode::DATAERR;
        }

        if ($adLogins === []) {
            $this->stderr(
                "Первичная настройка администраторов пропущена: "
                . "активный локальный администратор уже существует.\n",
            );
            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "Первичная настройка администраторов завершена: "
            . "создано пользователей — %d, назначено ролей — %d.\n",
            $result['usersCreated'],
            $result['rolesAssigned'],
        ));
        return ExitCode::OK;
    }

    private function failureDiagnostic(\Throwable $error, string $operation = 'Administrator bootstrap'): string
    {
        if ($error instanceof \yii\db\Exception) {
            $sqlState = (string) ($error->errorInfo[0] ?? 'unknown');
            $driverCode = (string) ($error->errorInfo[1] ?? 'unknown');
            return sprintf(
                $operation . ' failed (%s, SQLSTATE %s, driver code %s).',
                $error::class,
                $sqlState,
                $driverCode,
            );
        }

        if ($error instanceof \InvalidArgumentException || $error instanceof \RuntimeException) {
            return sprintf(
                $operation . ' failed (%s): %s',
                $error::class,
                $error->getMessage(),
            );
        }

        return sprintf($operation . ' failed (%s).', $error::class);
    }
}
