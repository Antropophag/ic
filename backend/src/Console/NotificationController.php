<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Notification\NotificationOutboxProcessor;
use App\Infrastructure\Notification\NotificationWorker;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class NotificationController extends Controller
{
    private const SEND_BATCH_SIZE = 50;
    private const WORK_BATCH_SIZE = 20;

    /** @var int|string|null */
    public $limit;
    /** @var int|string */
    public $idleSleep = 2;
    /** @var int|string */
    public $errorSleep = 5;
    /** @var bool|int|string */
    public $includeFailed = false;

    public function options($actionID): array
    {
        $options = match ($actionID) {
            'send' => ['limit', 'includeFailed'],
            'work' => ['limit', 'idleSleep', 'errorSleep'],
            default => [],
        };

        return array_merge(parent::options($actionID), $options);
    }

    public function actionSend(): int
    {
        $settings = $this->sendSettings();
        if ($settings === null) {
            return ExitCode::USAGE;
        }

        $result = $this->processor()->processAvailableBatch($settings['limit'], $settings['includeFailed']);
        $this->stdout("Отправлено: {$result['sent']}, ошибок: {$result['failed']}.\n");
        return ExitCode::OK;
    }

    public function actionWork(): int
    {
        $settings = $this->workSettings();
        if ($settings === null) {
            return ExitCode::USAGE;
        }
        if (!extension_loaded('pcntl')) {
            $this->stderr("Для notification/work требуется расширение pcntl.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->configureWorkerLogging();
        $processor = $this->processor();
        $worker = new NotificationWorker(
            function (callable $shouldContinue) use ($processor, $settings): int {
                $result = $processor->processAvailableBatch(
                    $settings['limit'],
                    false,
                    $shouldContinue,
                );
                return $result['sent'] + $result['failed'] + $result['skipped'];
            },
            static function (int $seconds): void {
                sleep($seconds);
            },
            static function (\Throwable $error): void {
                Yii::error([
                    'message' => 'Notification worker iteration failed',
                    'exception' => $error,
                ], __METHOD__);
                Yii::getLogger()->flush(true);
                if (Yii::$app->db->isActive) {
                    Yii::$app->db->close();
                }
            },
            $settings['idleSleep'],
            $settings['errorSleep'],
        );

        pcntl_async_signals(true);
        $stop = static function () use ($worker): void {
            $worker->requestShutdown();
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        Yii::info([
            'message' => 'Notification worker started',
            ...$settings,
        ], __METHOD__);
        Yii::getLogger()->flush(true);
        $worker->run();
        Yii::info(['message' => 'Notification worker stopped'], __METHOD__);
        Yii::getLogger()->flush(true);
        return ExitCode::OK;
    }

    /**
     * @return null|array{limit: int, includeFailed: bool}
     */
    private function sendSettings(): ?array
    {
        $limit = $this->integerOption($this->limit ?? self::SEND_BATCH_SIZE, 'limit', 1);
        $includeFailed = filter_var($this->includeFailed, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($includeFailed === null) {
            $this->stderr("Параметр --includeFailed должен быть 0 или 1.\n");
        }
        if ($limit === null || $includeFailed === null) {
            return null;
        }

        return compact('limit', 'includeFailed');
    }

    /**
     * @return null|array{limit: int, idleSleep: int, errorSleep: int}
     */
    private function workSettings(): ?array
    {
        $limit = $this->integerOption($this->limit ?? self::WORK_BATCH_SIZE, 'limit', 1);
        $idleSleep = $this->integerOption($this->idleSleep, 'idleSleep', 1);
        $errorSleep = $this->integerOption($this->errorSleep, 'errorSleep', 1);
        if ($limit === null || $idleSleep === null || $errorSleep === null) {
            return null;
        }

        return compact('limit', 'idleSleep', 'errorSleep');
    }

    private function integerOption(mixed $value, string $name, int $minimum): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $minimum) {
            $this->stderr("Параметр --{$name} должен быть положительным целым числом.\n");
            return null;
        }

        return $parsed;
    }

    private function processor(): NotificationOutboxProcessor
    {
        return new NotificationOutboxProcessor(Yii::$app->db);
    }

    private function configureWorkerLogging(): void
    {
        foreach (Yii::$app->log->targets as $target) {
            $target->except[] = 'yii\db\Command::query';
        }
    }
}
