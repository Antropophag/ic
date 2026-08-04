<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Deployment\DatabasePurpose;
use App\Infrastructure\Identity\BreakGlassConfiguration;
use App\Infrastructure\Identity\BreakGlassIdentityProvisioner;
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

        if (!$this->dropTestTables()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = Yii::$app->runAction('migrate/up', ['interactive' => false]);
        if ($result !== null && (int) $result !== ExitCode::OK) {
            return (int) $result;
        }
        if (!$this->clearStorage() || !$this->clearMailpit()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $seedResult = (new DevController('dev-seeder', $this->module))->seedUsers();
        if ($seedResult !== ExitCode::OK) {
            return $seedResult;
        }

        (new BreakGlassIdentityProvisioner(
            Yii::$app->db,
            BreakGlassConfiguration::fromEnvironment(),
        ))->provision();
        return ExitCode::OK;
    }

    public function actionSeed(): int
    {
        if (!$this->isSafe()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return (new DevController('dev-seeder', $this->module))->seedUsers();
    }

    private function isSafe(): bool
    {
        $database = Yii::$app->db->createCommand('SELECT DATABASE()')->queryScalar();
        if (!is_string($database) || !DatabasePurpose::isTest($database)) {
            $actual = is_string($database) && $database !== '' ? $database : '(unknown)';
            $this->stderr("Refusing test reset: connected database '{$actual}' must end with _test.\n");
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

    private function dropTestTables(): bool
    {
        $db = Yii::$app->db;
        $schema = $db->schema;

        try {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
            foreach ($schema->getTableNames('', true) as $table) {
                $db->createCommand()->dropTable($table)->execute();
            }
        } catch (\Throwable $error) {
            $this->stderr("Test database could not be reset: {$error->getMessage()}\n");
            return false;
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
            $schema->refresh();
        }

        return true;
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
