<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\RequestFingerprint;
use PHPUnit\Framework\TestCase;

final class RequestFingerprintTest extends TestCase
{
    public function testJsonObjectKeyOrderDoesNotChangeFingerprint(): void
    {
        self::assertSame(
            RequestFingerprint::json('{"outer":{"b":2,"a":1},"name":"test"}'),
            RequestFingerprint::json("{\n  \"name\": \"test\", \"outer\": {\"a\": 1, \"b\": 2}\n}"),
        );
        self::assertNotSame(RequestFingerprint::json('{"items":[1,2]}'), RequestFingerprint::json('{"items":[2,1]}'));
    }

    public function testMultipartIncludesFieldsAndStreamsAllFilesIndependentlyOfTechnicalOrder(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'fingerprint-');
        $second = tempnam(sys_get_temp_dir(), 'fingerprint-');
        self::assertIsString($first);
        self::assertIsString($second);
        file_put_contents($first, str_repeat('first-content', 10_000));
        file_put_contents($second, 'second-content');
        try {
            $files = ['documents' => [
                'name' => ['first.pdf', 'second.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'size' => [filesize($first), filesize($second)],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'tmp_name' => [$first, $second],
            ]];
            $reordered = ['documents' => [
                'name' => ['second.pdf', 'first.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'size' => [filesize($second), filesize($first)],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'tmp_name' => [$second, $first],
            ]];
            $fingerprint = RequestFingerprint::multipart(['z' => 'last', 'a' => 'first'], $files);
            self::assertSame(
                $fingerprint,
                RequestFingerprint::multipart(['a' => 'first', 'z' => 'last'], $reordered),
            );
            self::assertNotSame($fingerprint, RequestFingerprint::multipart(['a' => 'changed', 'z' => 'last'], $files));
            file_put_contents($second, 'changed-content');
            self::assertNotSame($fingerprint, RequestFingerprint::multipart(['a' => 'first', 'z' => 'last'], $files));
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }
}
