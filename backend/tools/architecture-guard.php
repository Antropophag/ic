<?php

declare(strict_types=1);

use App\Tools\ArchitectureGuard;

require_once __DIR__ . '/ArchitectureGuard.php';

$projectRoot = getenv('PROJECT_ROOT') ?: dirname(__DIR__, 2);
$baseline = require_once __DIR__ . '/architecture-baseline.php';
$guard = new ArchitectureGuard();

if (($argv[1] ?? null) === '--print-baseline') {
    echo "<?php\n\ndeclare(strict_types=1);\n\nreturn ";
    var_export($guard->measure($projectRoot));
    echo ";\n";
    exit(0);
}

$errors = $guard->check($projectRoot, $baseline);
if ($errors !== []) {
    fwrite(STDERR, "Architecture guard failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Architecture guard passed.\n";
