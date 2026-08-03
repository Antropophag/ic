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
        $configured = trim((string) (getenv('BOOTSTRAP_ADMIN_AD_LOGINS') ?: ''));
        if ($configured === '') {
            $this->stdout("No bootstrap administrators configured.\n");
            return ExitCode::OK;
        }

        /** @var list<string> $adLogins */
        $adLogins = preg_split('/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        try {
            $result = (new AdministratorBootstrap(Yii::$app->db))->bootstrap($adLogins);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            $this->stderr("Administrator bootstrap failed: {$error->getMessage()}\n");
            return ExitCode::DATAERR;
        }

        $this->stdout(sprintf(
            "Administrator bootstrap complete: %d user(s) created, %d role(s) assigned.\n",
            $result['usersCreated'],
            $result['rolesAssigned'],
        ));
        return ExitCode::OK;
    }
}
