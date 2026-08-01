<?php

declare(strict_types=1);

namespace App\Console;

use Yii;
use yii\helpers\FileHelper;
use yii\console\Controller;
use yii\console\ExitCode;

final class TestController extends Controller
{
    public function actionReset(): int
    {
        if (!$this->isSafe()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = Yii::$app->runAction('migrate/fresh');
        if ($result !== null && (int) $result !== ExitCode::OK) {
            return (int) $result;
        }
        if (!$this->clearStorage() || !$this->clearMailpit()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return (int) Yii::$app->runAction('dev/seed');
    }

    public function actionSeed(): int
    {
        if (!$this->isSafe()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return (int) Yii::$app->runAction('dev/seed');
    }

    private function isSafe(): bool
    {
        $database = (string) getenv('DB_NAME');
        if (YII_ENV !== 'test' || !str_contains($database, '_test')) {
            $this->stderr("Refusing test reset: APP_ENV=test and DB_NAME containing _test are required.\n");
            return false;
        }

        return true;
    }

    private function clearStorage(): bool
    {
        $path = (string) getenv('DOCUMENT_STORAGE_PATH');
        $expectedPath = '/app/storage/test-documents/data';
        if ($path !== $expectedPath || is_link($path)) {
            $this->stderr("Refusing test storage cleanup: DOCUMENT_STORAGE_PATH must be $expectedPath.\n");
            return false;
        }

        if (is_dir($path)) {
            foreach (FileHelper::findDirectories($path) as $directory) {
                if (is_link($directory)) {
                    $this->stderr("Refusing test storage cleanup: symlinks are not allowed.\n");
                    return false;
                }
            }
            FileHelper::removeDirectory($path);
        }

        return FileHelper::createDirectory($path);
    }

    private function clearMailpit(): bool
    {
        $baseUrl = rtrim(getenv('MAILPIT_API_URL') ?: '', '/');
        if ($baseUrl === '') {
            return true;
        }
        $context = stream_context_create(['http' => [
            'method' => 'DELETE',
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($baseUrl . '/api/v1/messages', false, $context);
        $status = $http_response_header[0] ?? '';
        if ($result === false || preg_match('/\s2\d\d(?:\s|$)/', $status) !== 1) {
            $this->stderr("Mailpit mailbox could not be cleared.\n");
            return false;
        }

        return true;
    }
}
