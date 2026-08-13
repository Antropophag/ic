<?php

declare(strict_types=1);

namespace App\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ArchitectureGuard
{
    private const PHP_FILE_LIMIT = 500;
    private const JAVASCRIPT_FILE_LIMIT = 500;
    private const VUE_FILE_LIMIT = 700;
    private const PHP_METHOD_LIMIT = 80;

    /**
     * @param array{
     *     dependencies: array<string, list<string>>,
     *     files: array<string, int>,
     *     methods: array<string, array<string, int>>
     * } $baseline
     * @return list<string>
     */
    public function check(string $projectRoot, array $baseline): array
    {
        $actual = $this->measure($projectRoot);
        $errors = [];

        $this->compareDependencies($actual['dependencies'], $baseline['dependencies'], $errors);
        $this->compareSizes('file', $actual['files'], $baseline['files'], $errors);

        $actualMethods = $this->flattenMethods($actual['methods']);
        $baselineMethods = $this->flattenMethods($baseline['methods']);
        $this->compareSizes('method', $actualMethods, $baselineMethods, $errors);

        sort($errors);

        return $errors;
    }

    /**
     * @return array{
     *     dependencies: array<string, list<string>>,
     *     files: array<string, int>,
     *     methods: array<string, array<string, int>>
     * }
     */
    public function measure(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/');
        $dependencies = [];
        $files = [];
        $methods = [];

        foreach ($this->sourceFiles($root . '/backend/src', ['php']) as $path) {
            $relativePath = $this->relativePath($root, $path);
            $source = $this->read($path);
            $lineCount = $this->lineCount($source);

            if ($lineCount > self::PHP_FILE_LIMIT) {
                $files[$relativePath] = $lineCount;
            }

            $php = $this->inspectPhp($source);
            $forbidden = $this->forbiddenDependencies($php['namespace'], $php['dependencies']);
            if ($forbidden !== []) {
                $dependencies[$relativePath] = $forbidden;
            }

            foreach ($php['methods'] as $method => $methodLines) {
                if ($methodLines > self::PHP_METHOD_LIMIT) {
                    $methods[$relativePath][$method] = $methodLines;
                }
            }
        }

        foreach ($this->sourceFiles($root . '/frontend/src', ['js', 'mjs', 'cjs', 'vue']) as $path) {
            if ($this->isFrontendTest($path)) {
                continue;
            }

            $relativePath = $this->relativePath($root, $path);
            $lineCount = $this->lineCount($this->read($path));
            $limit = pathinfo($path, PATHINFO_EXTENSION) === 'vue'
                ? self::VUE_FILE_LIMIT
                : self::JAVASCRIPT_FILE_LIMIT;

            if ($lineCount > $limit) {
                $files[$relativePath] = $lineCount;
            }
        }

        ksort($dependencies);
        ksort($files);
        ksort($methods);
        foreach ($methods as &$fileMethods) {
            ksort($fileMethods);
        }

        return [
            'dependencies' => $dependencies,
            'files' => $files,
            'methods' => $methods,
        ];
    }

    /**
     * @return array{namespace: string, dependencies: list<string>, methods: array<string, int>}
     */
    private function inspectPhp(string $source): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $dependencies = [];
        $methods = [];
        $classStack = [];
        $braceDepth = 0;
        $pendingClassName = null;
        $lineAt = [];
        $line = 1;

        foreach ($tokens as $index => $token) {
            $lineAt[$index] = $line;
            $text = is_array($token) ? $token[1] : $token;
            $line += substr_count($text, "\n");
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                if ($token === '{') {
                    ++$braceDepth;
                    if ($pendingClassName !== null) {
                        $classStack[] = ['name' => $pendingClassName, 'depth' => $braceDepth];
                        $pendingClassName = null;
                    }
                } elseif ($token === '}') {
                    $currentClass = end($classStack);
                    if ($currentClass !== false && $currentClass['depth'] === $braceDepth) {
                        array_pop($classStack);
                    }
                    --$braceDepth;
                }
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                [$namespace] = $this->readNameUntil($tokens, $index + 1, [';', '{']);
                continue;
            }

            if (
                in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && $this->previousSignificantTokenId($tokens, $index) !== T_DOUBLE_COLON
            ) {
                $pendingClassName = $this->nextClassName($tokens, $index + 1);
                continue;
            }

            if ($token[0] === T_USE && $classStack === []) {
                [$use] = $this->readNameUntil($tokens, $index + 1, [';']);
                foreach ($this->splitUseStatement($use) as $dependency) {
                    $dependencies[$dependency] = true;
                }
                continue;
            }

            if ($token[0] === T_NAME_FULLY_QUALIFIED) {
                $dependencies[ltrim($token[1], '\\')] = true;
            }

            $currentClass = end($classStack);
            if ($token[0] === T_FUNCTION && $currentClass !== false) {
                $methodName = $this->functionName($tokens, $index + 1);
                if ($methodName === null) {
                    continue;
                }

                $endIndex = $this->functionEnd($tokens, $index + 1);
                if ($endIndex !== null) {
                    $methods[$currentClass['name'] . '::' . $methodName] = $lineAt[$endIndex] - $token[2] + 1;
                }
            }
        }

        $dependencyNames = array_keys($dependencies);
        sort($dependencyNames);
        ksort($methods);

        return [
            'namespace' => $namespace,
            'dependencies' => $dependencyNames,
            'methods' => $methods,
        ];
    }

    /**
     * @param list<string> $dependencies
     * @return list<string>
     */
    private function forbiddenDependencies(string $namespace, array $dependencies): array
    {
        $rules = match (true) {
            $this->isNamespace($namespace, 'App\\Domain') => [
                'App\\Application', 'App\\Infrastructure', 'App\\Http', 'App\\Console', 'yii\\',
            ],
            $this->isNamespace($namespace, 'App\\Application') => [
                'App\\Infrastructure', 'App\\Http', 'App\\Console', 'yii\\',
            ],
            $this->isNamespace($namespace, 'App\\Infrastructure') => ['App\\Http', 'App\\Console'],
            $this->isNamespace($namespace, 'App\\Http') => ['App\\Infrastructure'],
            default => [],
        };

        $forbidden = [];
        foreach ($dependencies as $dependency) {
            foreach ($rules as $prefix) {
                if ($dependency === rtrim($prefix, '\\') || str_starts_with($dependency, $prefix)) {
                    $forbidden[] = $dependency;
                    break;
                }
            }
        }
        sort($forbidden);

        return array_values(array_unique($forbidden));
    }

    private function isNamespace(string $namespace, string $layer): bool
    {
        return $namespace === $layer || str_starts_with($namespace, $layer . '\\');
    }

    /**
     * @param array<string, list<string>> $actual
     * @param array<string, list<string>> $baseline
     * @param list<string> $errors
     */
    private function compareDependencies(array $actual, array $baseline, array &$errors): void
    {
        foreach ($actual as $file => $dependencies) {
            $allowed = $baseline[$file] ?? [];
            foreach (array_diff($dependencies, $allowed) as $dependency) {
                $errors[] = "New forbidden dependency: {$file} -> {$dependency}";
            }
        }

        foreach ($baseline as $file => $dependencies) {
            $present = $actual[$file] ?? [];
            foreach (array_diff($dependencies, $present) as $dependency) {
                $errors[] = "Stale dependency baseline: {$file} -> {$dependency}";
            }
        }
    }

    /**
     * @param array<string, int> $actual
     * @param array<string, int> $baseline
     * @param list<string> $errors
     */
    private function compareSizes(string $kind, array $actual, array $baseline, array &$errors): void
    {
        foreach ($actual as $name => $size) {
            if (!isset($baseline[$name])) {
                $errors[] = "New oversized {$kind}: {$name} ({$size} lines)";
                continue;
            }
            if ($size > $baseline[$name]) {
                $errors[] = "Oversized {$kind} grew: {$name} ({$size} > {$baseline[$name]} lines)";
            } elseif ($size < $baseline[$name]) {
                $errors[] = "Stale {$kind} baseline: {$name} ({$size} < {$baseline[$name]} lines)";
            }
        }

        foreach (array_diff_key($baseline, $actual) as $name => $size) {
            $errors[] = "Stale {$kind} baseline: {$name} is no longer oversized (was {$size} lines)";
        }
    }

    /**
     * @param array<string, array<string, int>> $methods
     * @return array<string, int>
     */
    private function flattenMethods(array $methods): array
    {
        $flat = [];
        foreach ($methods as $file => $fileMethods) {
            foreach ($fileMethods as $method => $lines) {
                $flat[$file . ':' . $method] = $lines;
            }
        }

        return $flat;
    }

    /**
     * @param list<string> $extensions
     * @return list<string>
     */
    private function sourceFiles(string $directory, array $extensions): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function isFrontendTest(string $path): bool
    {
        return preg_match('/(?:^|\/)(?:__tests__|tests?)(?:\/|$)/', $path) === 1
            || preg_match('/\.(?:test|spec)\.[^.]+$/', $path) === 1;
    }

    private function relativePath(string $root, string $path): string
    {
        return ltrim(substr($path, strlen($root)), '/');
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        return $source;
    }

    private function lineCount(string $source): int
    {
        if ($source === '') {
            return 0;
        }

        return substr_count($source, "\n") + (str_ends_with($source, "\n") ? 0 : 1);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param list<string> $terminators
     * @return array{string, int}
     */
    private function readNameUntil(array $tokens, int $start, array $terminators): array
    {
        $name = '';
        $index = $start;
        for (; isset($tokens[$index]); ++$index) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($text, $terminators, true)) {
                break;
            }
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $name .= $text;
            }
        }

        return [trim($name, " \t\n\r\0\x0B\\"), $index];
    }

    /** @return list<string> */
    private function splitUseStatement(string $use): array
    {
        if (preg_match('/^(.+)\\\\\{(.+)\}$/', $use, $matches) === 1) {
            $prefix = rtrim($matches[1], '\\') . '\\';

            return array_map(
                fn (string $part): string => $prefix . $this->importName($part),
                explode(',', $matches[2]),
            );
        }

        $dependencies = [];
        foreach (explode(',', $use) as $part) {
            $part = $this->importName($part);
            if ($part !== '') {
                $dependencies[] = trim($part, '\\');
            }
        }

        return $dependencies;
    }

    private function importName(string $part): string
    {
        $part = preg_replace('/^(?:function|const)/', '', trim($part)) ?? '';

        return trim(trim(preg_split('/\bas\b/i', $part)[0] ?? ''), '\\');
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function nextClassName(array $tokens, int $start): string
    {
        for ($index = $start; isset($tokens[$index]); ++$index) {
            if (is_array($tokens[$index]) && $tokens[$index][0] === T_STRING) {
                return $tokens[$index][1];
            }
            if ($tokens[$index] === '{' || $tokens[$index] === '(') {
                return 'anonymous@' . $start;
            }
        }

        return 'anonymous@' . $start;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function previousSignificantTokenId(array $tokens, int $start): ?int
    {
        for ($index = $start - 1; $index >= 0; --$index) {
            if (is_array($tokens[$index])) {
                if (in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $tokens[$index][0];
            }
            return null;
        }

        return null;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function functionName(array $tokens, int $start): ?string
    {
        for ($index = $start; isset($tokens[$index]); ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
            if ($token === '(') {
                return null;
            }
        }

        return null;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function functionEnd(array $tokens, int $start): ?int
    {
        $depth = 0;
        $opened = false;
        for ($index = $start; isset($tokens[$index]); ++$index) {
            $token = $tokens[$index];
            if ($token === ';' && !$opened) {
                return null;
            }
            if ($token === '{') {
                ++$depth;
                $opened = true;
            } elseif ($token === '}' && $opened) {
                --$depth;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }
}
