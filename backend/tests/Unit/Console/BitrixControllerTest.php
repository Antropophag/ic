<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\BitrixController;
use PHPUnit\Framework\TestCase;
use yii\base\Module;
use yii\console\Request;
use yii\console\Response;

final class BitrixControllerTest extends TestCase
{
    public function testImportFilesDeclaresSnapshotAsConsoleOption(): void
    {
        $controller = new BitrixController('bitrix', new Module('test'), [
            'request' => new Request(),
            'response' => new Response(),
        ]);

        self::assertContains('snapshot', $controller->options('import-files'));
    }
}
