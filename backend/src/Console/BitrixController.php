<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\Import\LegacyRequestMapper;
use App\Infrastructure\Bitrix\BitrixListClient;
use App\Infrastructure\Bitrix\NativeBitrixTransport;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;

final class BitrixController extends Controller
{
    public int $maxPages = 1;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'maxPages'];
    }

    public function actionInspect(): int
    {
        $client = $this->client();
        $mapper = new LegacyRequestMapper();
        $summary = [
            'mode' => 'read-only',
            'fieldCount' => count($client->fields()),
            'records' => 0,
            'valid' => 0,
            'invalid' => 0,
            'statuses' => [],
            'supportingDocuments' => 0,
            'reports' => 0,
        ];

        foreach ($client->elements($this->maxPages) as $element) {
            ++$summary['records'];
            try {
                $request = $mapper->map($element, $this->listId());
                ++$summary['valid'];
                $summary['statuses'][$request->status->value] =
                    ($summary['statuses'][$request->status->value] ?? 0) + 1;
                $summary['supportingDocuments'] += $request->supportingDocumentCount;
                $summary['reports'] += $request->reportCount;
            } catch (Throwable) {
                ++$summary['invalid'];
            }
        }

        ksort($summary['statuses']);
        $this->stdout(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        return $summary['invalid'] === 0 ? ExitCode::OK : ExitCode::DATAERR;
    }

    private function client(): BitrixListClient
    {
        $url = getenv('BITRIX24_WEBHOOK_URL');
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException('BITRIX24_WEBHOOK_URL is required.');
        }

        $type = getenv('BITRIX24_REQUESTS_IBLOCK_TYPE');
        return new BitrixListClient(
            new NativeBitrixTransport($url),
            is_string($type) && $type !== '' ? $type : 'lists',
            $this->listId(),
        );
    }

    private function listId(): int
    {
        $value = getenv('BITRIX24_REQUESTS_LIST_ID');
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new \RuntimeException('BITRIX24_REQUESTS_LIST_ID must be a positive integer.');
        }
        return $id;
    }
}
