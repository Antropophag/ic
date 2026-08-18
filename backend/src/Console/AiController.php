<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Ai\AiFileCleanupProcessor;
use App\Infrastructure\Ai\OpenWebUiTransport;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class AiController extends Controller
{
    public function actionCleanup(int $limit = 20): int
    {
        $result = $this->processor()->processAvailableBatch($limit);
        $this->stdout("Удалено: {$result['deleted']}, ошибок: {$result['failed']}, просрочено: {$result['expired']}.\n");
        return $result['failed'] === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionWork(int $limit = 20, int $idleSleep = 10): int
    {
        if (!extension_loaded('pcntl')) {
            $this->stderr("Для ai/work требуется расширение pcntl.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $running = true;
        pcntl_async_signals(true);
        $stop = static function () use (&$running): void {
            $running = false;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        while ($running) {
            try {
                $result = $this->processor()->processAvailableBatch(max(1, min(100, $limit)));
                if ($result['deleted'] + $result['failed'] + $result['expired'] === 0) {
                    sleep(max(1, $idleSleep));
                }
            } catch (\Throwable $error) {
                Yii::error(['event' => 'ai_cleanup_worker_failed', 'error_class' => $error::class], __METHOD__);
                Yii::getLogger()->flush(true);
                sleep(max(1, $idleSleep));
            }
        }
        return ExitCode::OK;
    }

    private function processor(): AiFileCleanupProcessor
    {
        return new AiFileCleanupProcessor(Yii::$app->db, Yii::$container->get(OpenWebUiTransport::class));
    }
}
