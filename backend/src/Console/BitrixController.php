<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\Import\BitrixSnapshotInventory;
use App\Application\Import\LegacyRequestMapper;
use App\Application\Import\LegacyRequestImporter;
use App\Application\Import\LegacyUserData;
use App\Application\Import\LegacyUserMapper;
use App\Infrastructure\Bitrix\BitrixListClient;
use App\Infrastructure\Bitrix\BitrixSnapshotExporter;
use App\Infrastructure\Bitrix\BitrixTransport;
use App\Infrastructure\Bitrix\BitrixUserClient;
use App\Infrastructure\Bitrix\NativeBitrixTransport;
use App\Infrastructure\Bitrix\PrivateJsonReportWriter;
use App\Infrastructure\Import\DatabaseLegacyRequestWriter;
use Throwable;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class BitrixController extends Controller
{
    public int $maxPages = 1;
    public string $apply = '0';
    public string $output = '';
    public string $snapshot = '';

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return match ($actionID) {
            'import' => [...$options, 'maxPages', 'apply'],
            'inspect' => [...$options, 'maxPages'],
            'snapshot' => [...$options, 'maxPages', 'output'],
            'inventory' => [...$options, 'snapshot', 'output'],
            default => $options,
        };
    }

    public function actionInspect(): int
    {
        if (!$this->validMaxPages()) {
            return ExitCode::USAGE;
        }
        $client = $this->client();
        $elements = iterator_to_array($client->elements($this->maxPages), false);
        $users = $this->usersForElements($elements);
        $mapper = new LegacyRequestMapper($users);
        $summary = [
            'mode' => 'read-only',
            'fieldCount' => count($client->fields()),
            'records' => 0,
            'valid' => 0,
            'invalid' => 0,
            'statuses' => [],
            'supportingDocuments' => 0,
            'reports' => 0,
            'users' => count($users),
        ];

        foreach ($elements as $element) {
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

    public function actionImport(): int
    {
        if (!$this->validMaxPages()) {
            return ExitCode::USAGE;
        }
        if (!in_array($this->apply, ['0', '1'], true)) {
            $this->stderr("--apply accepts only 0 or 1; database was not changed.\n");
            return ExitCode::USAGE;
        }

        $shouldApply = $this->apply === '1';
        $elements = iterator_to_array($this->client()->elements($this->maxPages), false);
        $users = $this->usersForElements($elements);
        $writer = $shouldApply ? new DatabaseLegacyRequestWriter(Yii::$app->db) : null;
        $summary = (new LegacyRequestImporter(new LegacyRequestMapper($users)))->import(
            $elements,
            $this->listId(),
            $writer,
        );
        $result = [
            'mode' => $shouldApply ? 'apply' : 'dry-run',
            ...$summary,
            'users' => [
                'total' => count($users),
                'active' => count(array_filter($users, static fn (LegacyUserData $user): bool => $user->active)),
                'inactive' => count(array_filter($users, static fn (LegacyUserData $user): bool => !$user->active)),
            ],
        ];
        $this->stdout(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

        return $summary['invalid'] === 0 ? ExitCode::OK : ExitCode::DATAERR;
    }

    public function actionSnapshot(): int
    {
        if ($this->output === '') {
            $this->stderr("--output is required; no snapshot was created.\n");
            return ExitCode::USAGE;
        }
        if (!$this->validMaxPages()) {
            return ExitCode::USAGE;
        }

        $manifest = (new BitrixSnapshotExporter())->export(
            $this->client(),
            new BitrixUserClient($this->transport()),
            $this->output,
            $this->iblockType(),
            $this->listId(),
            $this->maxPages,
        );
        $this->stdout(json_encode([
            'mode' => 'read-only snapshot',
            'complete' => true,
            'pages' => $manifest['pages'],
            'records' => $manifest['records'],
            'users' => $manifest['users'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        return ExitCode::OK;
    }

    public function actionInventory(): int
    {
        if ($this->snapshot === '' || $this->output === '') {
            $this->stderr("--snapshot and --output are required; no report was created.\n");
            return ExitCode::USAGE;
        }

        $report = (new BitrixSnapshotInventory())->inspect($this->snapshot);
        (new PrivateJsonReportWriter())->write($this->output, $report);
        $this->stdout(json_encode([
            'mode' => 'offline inventory',
            'integrityVerified' => true,
            'records' => $report['snapshot']['records'],
            'invalidDetails' => count($report['details']['invalidElementIds']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        return ExitCode::OK;
    }

    private function client(): BitrixListClient
    {
        return new BitrixListClient(
            $this->transport(),
            $this->iblockType(),
            $this->listId(),
        );
    }

    private function validMaxPages(): bool
    {
        if ($this->maxPages >= 0) {
            return true;
        }
        $this->stderr("--max-pages must be zero or a positive integer.\n");
        return false;
    }

    /** @param list<array<string, mixed>> $elements
     *  @return array<int|string, LegacyUserData>
     */
    private function usersForElements(array $elements): array
    {
        $ids = [];
        foreach ($elements as $element) {
            try {
                $details = json_decode((string) ($element['DETAIL_TEXT'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $id = is_array($details) && is_array($details['creator'] ?? null)
                ? trim((string) ($details['creator']['ID'] ?? ''))
                : '';
            if (preg_match('/^\d+$/', $id) !== 1) {
                continue;
            }
            $ids[] = $id;
        }

        $rawUsers = (new BitrixUserClient($this->transport()))->usersById($ids);
        $mapper = new LegacyUserMapper();
        $users = [];
        $logins = [];
        foreach ($rawUsers as $id => $rawUser) {
            $bitrixId = (string) $id;
            $user = $mapper->map($rawUser, $bitrixId);
            if (isset($logins[$user->adLogin]) && $logins[$user->adLogin] !== $bitrixId) {
                throw new \UnexpectedValueException('Several Bitrix24 users map to the same AD login.');
            }
            $logins[$user->adLogin] = $bitrixId;
            $users[$bitrixId] = $user;
        }
        return $users;
    }

    private function transport(): BitrixTransport
    {
        $url = getenv('BITRIX24_WEBHOOK_URL');
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException('BITRIX24_WEBHOOK_URL is required.');
        }
        return new NativeBitrixTransport($url);
    }

    private function iblockType(): string
    {
        $type = getenv('BITRIX24_REQUESTS_IBLOCK_TYPE');
        return is_string($type) && $type !== '' ? $type : 'lists';
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
