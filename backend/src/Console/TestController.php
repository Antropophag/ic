<?php

declare(strict_types=1);

namespace App\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yii;
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
        $this->clearStorage();
        $this->clearMailpit();

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

    private function clearStorage(): void
    {
        $path = getenv('DOCUMENT_STORAGE_PATH') ?: '/app/storage/documents';
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
    }

    private function clearMailpit(): void
    {
        $baseUrl = rtrim(getenv('MAILPIT_API_URL') ?: '', '/');
        if ($baseUrl === '') {
            return;
        }
        $context = stream_context_create(['http' => [
            'method' => 'DELETE',
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($baseUrl . '/api/v1/messages', false, $context);
        if ($result === false) {
            $this->stderr("Mailpit mailbox could not be cleared.\n");
        }
    }
}
