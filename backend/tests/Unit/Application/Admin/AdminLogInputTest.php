<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Admin;

use App\Application\Admin\ListAuditEventsInput;
use App\Application\Admin\ListNotificationsInput;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

final class AdminLogInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        new Application(['id' => 'admin-log-input-test', 'basePath' => dirname(__DIR__, 4)]);
    }

    protected function tearDown(): void
    {
        Yii::$app?->errorHandler->unregister();
        Yii::$app = null;
        parent::tearDown();
    }

    public function testAuditInputAcceptsSupportedFilters(): void
    {
        $input = new ListAuditEventsInput();
        $input->setAttributes(['actorId' => '7', 'result' => 'denied', 'dateFrom' => '2026-08-01',
            'dateTo' => '2026-08-04', 'limit' => '100', 'cursor' => 'abc_123-Z']);

        self::assertTrue($input->validate());
    }

    public function testAuditInputRejectsUnsupportedResultAndBounds(): void
    {
        $input = new ListAuditEventsInput();
        $input->setAttributes(['actorId' => '0', 'result' => 'success', 'dateFrom' => '04.08.2026',
            'limit' => '101', 'cursor' => 'not valid!']);

        self::assertFalse($input->validate());
        self::assertEqualsCanonicalizing(['actorId', 'result', 'dateFrom', 'limit', 'cursor'], array_keys($input->errors));
    }

    public function testNotificationInputAcceptsSupportedFilters(): void
    {
        $input = new ListNotificationsInput();
        $input->setAttributes(['status' => 'sending', 'requestId' => '42', 'problematic' => '1',
            'eventType' => 'request.created', 'recipient' => 'user@example.invalid', 'limit' => '50']);

        self::assertTrue($input->validate());
    }

    public function testNotificationInputRejectsInvalidEnumsDatesAndCursor(): void
    {
        $input = new ListNotificationsInput();
        $input->setAttributes(['status' => 'retrying', 'requestId' => '-1', 'problematic' => 'yes',
            'dateTo' => 'tomorrow', 'limit' => '0', 'cursor' => '*']);

        self::assertFalse($input->validate());
        self::assertEqualsCanonicalizing(
            ['status', 'requestId', 'problematic', 'dateTo', 'limit', 'cursor'],
            array_keys($input->errors),
        );
    }
}
