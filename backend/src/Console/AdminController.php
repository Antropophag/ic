<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Identity\AdministratorBootstrap;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class AdminController extends Controller
{
    public function actionBootstrap(): int
    {
        $rawConfigured = getenv('BOOTSTRAP_ADMIN_AD_LOGINS');
        $configured = trim($rawConfigured === false ? '' : $rawConfigured);
        if ($configured === '') {
            $this->stderr("Administrator bootstrap skipped: no logins configured.\n");
            return ExitCode::OK;
        }

        /** @var list<string> $adLogins */
        $adLogins = explode(',', $configured);
        if (array_filter($adLogins, static fn (string $login): bool => trim($login) !== '') === []) {
            $this->stderr("Administrator bootstrap skipped: no logins configured.\n");
            return ExitCode::OK;
        }

        try {
            $result = (new AdministratorBootstrap(Yii::$app->db))->bootstrap($adLogins);
        } catch (\Throwable $error) {
            Yii::error($this->failureDiagnostic($error), 'admin.bootstrap');
            $this->stderr("Administrator bootstrap failed; see application logs for details.\n");
            return ExitCode::DATAERR;
        }

        $this->stdout(sprintf(
            "Administrator bootstrap complete: %d user(s) created, %d role(s) assigned.\n",
            $result['usersCreated'],
            $result['rolesAssigned'],
        ));
        return ExitCode::OK;
    }

    private function failureDiagnostic(\Throwable $error): string
    {
        if ($error instanceof \yii\db\Exception) {
            $sqlState = (string) ($error->errorInfo[0] ?? 'unknown');
            $driverCode = (string) ($error->errorInfo[1] ?? 'unknown');
            return sprintf(
                'Administrator bootstrap failed (%s, SQLSTATE %s, driver code %s).',
                $error::class,
                $sqlState,
                $driverCode,
            );
        }

        if ($error instanceof \InvalidArgumentException || $error instanceof \RuntimeException) {
            return sprintf(
                'Administrator bootstrap failed (%s): %s',
                $error::class,
                $error->getMessage(),
            );
        }

        return sprintf('Administrator bootstrap failed (%s).', $error::class);
    }
}
