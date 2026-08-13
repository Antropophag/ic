<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Tools\ArchitectureGuard;
use PHPUnit\Framework\TestCase;

final class ArchitectureGuardTest extends TestCase
{
    private string $projectRoot;
    private ArchitectureGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = sys_get_temp_dir() . '/ic-architecture-guard-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/backend/src', 0777, true);
        mkdir($this->projectRoot . '/frontend/src', 0777, true);
        $this->guard = new ArchitectureGuard();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
        parent::tearDown();
    }

    public function testAllowedDependencyPasses(): void
    {
        $this->write('backend/src/Application/Example.php', <<<'PHP'
<?php
namespace App\Application;
use App\Domain\Request\Request;
final class Example {}
PHP);

        self::assertSame([], $this->guard->check($this->projectRoot, $this->emptyBaseline()));
    }

    public function testNewForbiddenDependencyFails(): void
    {
        $this->writeApplicationDependencies(['App\Infrastructure\Clock']);

        self::assertContains(
            'New forbidden dependency: backend/src/Application/Example.php -> App\Infrastructure\Clock',
            $this->guard->check($this->projectRoot, $this->emptyBaseline()),
        );
    }

    public function testGroupedForbiddenImportsFailIndividually(): void
    {
        $this->write('backend/src/Application/Example.php', <<<'PHP'
<?php
namespace App\Application;
use App\Infrastructure\Request\{RequestQuery, RequestRepository as Repository};
final class Example {}
PHP);

        $errors = $this->guard->check($this->projectRoot, $this->emptyBaseline());

        self::assertContains(
            'New forbidden dependency: backend/src/Application/Example.php -> App\Infrastructure\Request\RequestQuery',
            $errors,
        );
        self::assertContains(
            'New forbidden dependency: backend/src/Application/Example.php -> App\Infrastructure\Request\RequestRepository',
            $errors,
        );
    }

    public function testExistingBaselineDependencyPasses(): void
    {
        $this->writeApplicationDependencies(['yii\base\Model']);
        $baseline = $this->emptyBaseline();
        $baseline['dependencies']['backend/src/Application/Example.php'] = ['yii\base\Model'];

        self::assertSame([], $this->guard->check($this->projectRoot, $baseline));
    }

    public function testNewDependencyInAllowlistedFileStillFails(): void
    {
        $this->writeApplicationDependencies(['yii\base\Model', 'App\Infrastructure\Clock']);
        $baseline = $this->emptyBaseline();
        $baseline['dependencies']['backend/src/Application/Example.php'] = ['yii\base\Model'];

        self::assertContains(
            'New forbidden dependency: backend/src/Application/Example.php -> App\Infrastructure\Clock',
            $this->guard->check($this->projectRoot, $baseline),
        );
    }

    public function testStaleDependencyBaselineFails(): void
    {
        $this->writeApplicationDependencies([]);
        $baseline = $this->emptyBaseline();
        $baseline['dependencies']['backend/src/Application/Example.php'] = ['yii\base\Model'];

        self::assertContains(
            'Stale dependency baseline: backend/src/Application/Example.php -> yii\base\Model',
            $this->guard->check($this->projectRoot, $baseline),
        );
    }

    public function testNewOversizedFileFails(): void
    {
        $this->write('frontend/src/large.js', $this->lines(501));

        self::assertContains(
            'New oversized file: frontend/src/large.js (501 lines)',
            $this->guard->check($this->projectRoot, $this->emptyBaseline()),
        );
    }

    public function testExistingOversizedFilePassesAtBaseline(): void
    {
        $this->write('frontend/src/large.js', $this->lines(501));
        $baseline = $this->emptyBaseline();
        $baseline['files']['frontend/src/large.js'] = 501;

        self::assertSame([], $this->guard->check($this->projectRoot, $baseline));
    }

    public function testOversizedFileGrowthFails(): void
    {
        $this->write('frontend/src/large.js', $this->lines(502));
        $baseline = $this->emptyBaseline();
        $baseline['files']['frontend/src/large.js'] = 501;

        self::assertContains(
            'Oversized file grew: frontend/src/large.js (502 > 501 lines)',
            $this->guard->check($this->projectRoot, $baseline),
        );
    }

    public function testLongPhpMethodFails(): void
    {
        $this->writeLongMethod(81);

        self::assertContains(
            'New oversized method: backend/src/Domain/LongExample.php:LongExample::run (81 lines)',
            $this->guard->check($this->projectRoot, $this->emptyBaseline()),
        );
    }

    public function testExistingLongPhpMethodPassesAtBaseline(): void
    {
        $this->writeLongMethod(81);
        $baseline = $this->emptyBaseline();
        $baseline['methods']['backend/src/Domain/LongExample.php']['LongExample::run'] = 81;

        self::assertSame([], $this->guard->check($this->projectRoot, $baseline));
    }

    public function testAnonymousClassDoesNotHideFollowingLongMethod(): void
    {
        $body = implode("\n", array_fill(0, 78, '        $value = 1;'));
        $this->write('backend/src/Domain/OuterExample.php', <<<PHP
<?php
namespace App\Domain;
final class OuterExample
{
    public function helper()
    {
        return new class {
            public function value(): int { return 1; }
        };
    }

    public function run()
    {
{$body}
    }
}
PHP);

        self::assertContains(
            'New oversized method: backend/src/Domain/OuterExample.php:OuterExample::run (81 lines)',
            $this->guard->check($this->projectRoot, $this->emptyBaseline()),
        );
    }

    /**
     * @param list<string> $dependencies
     */
    private function writeApplicationDependencies(array $dependencies): void
    {
        $uses = implode("\n", array_map(static fn (string $dependency): string => "use {$dependency};", $dependencies));
        $this->write('backend/src/Application/Example.php', "<?php\nnamespace App\\Application;\n{$uses}\nfinal class Example {}\n");
    }

    private function writeLongMethod(int $methodLines): void
    {
        $bodyLines = $methodLines - 3;
        $body = implode("\n", array_fill(0, $bodyLines, '        $value = 1;'));
        $source = "<?php\nnamespace App\\Domain;\nfinal class LongExample\n{\n    public function run()\n    {\n{$body}\n    }\n}\n";
        $this->write('backend/src/Domain/LongExample.php', $source);
    }

    private function lines(int $count): string
    {
        return implode("\n", array_fill(0, $count, '// line'));
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->projectRoot . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @return array{dependencies: array<string, list<string>>, files: array<string, int>, methods: array<string, array<string, int>>}
     */
    private function emptyBaseline(): array
    {
        return ['dependencies' => [], 'files' => [], 'methods' => []];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
