<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Document;

use App\Application\Document\TestActDocumentData;
use App\Infrastructure\Document\TestActDocumentGenerator;
use PHPUnit\Framework\TestCase;

final class TestActDocumentGeneratorTest extends TestCase
{
    public function testGeneratesEditableDocxWithAuthoritativeFormDetailsAndSafeCyrillicText(): void
    {
        $content = (new TestActDocumentGenerator())->generate(new TestActDocumentData(
            42,
            '42-А',
            '11.08.2026',
            'Образец <лифта> & подъёмника',
            'Заявка № 42',
            'Маркировка соответствует. <w:t>Не разметка</w:t>',
            'Иван Иванов',
            'manager@example.test',
            'Петров П.П.',
            'Инженер-испытатель',
        ));

        self::assertStringStartsWith('PK', $content);
        $path = tempnam(sys_get_temp_dir(), 'test-act-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $content);
            $archive = new \ZipArchive();
            self::assertTrue($archive->open($path));
            $xml = $archive->getFromName('word/document.xml');
            self::assertNotFalse($xml);
            $hasEmbeddedImage = false;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->getNameIndex($index);
                $hasEmbeddedImage = $hasEmbeddedImage || ($entry !== false && str_starts_with($entry, 'word/media/'));
            }
            self::assertTrue($hasEmbeddedImage);
            $archive->close();
        } finally {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam-created test fixture
            unlink($path);
        }

        self::assertStringContainsString('АКТ ИСПЫТАНИЙ № 42-А', $xml);
        self::assertStringContainsString('АО «ЩЛЗ»', $xml);
        self::assertStringContainsString('УТВЕРЖДАЮ', $xml);
        self::assertStringContainsString('w:fill="0B1893"', $xml);
        self::assertStringContainsString('<w:trHeight w:val="1680" w:hRule="exact"/>', $xml);
        self::assertStringContainsString('<w:spacing w:after="0" w:line="240" w:lineRule="auto"/>', $xml);
        self::assertStringContainsString('Иван Иванов', $xml);
        self::assertStringContainsString('e-mail: manager@example.test', $xml);
        self::assertStringContainsString('Петров П.П.', $xml);
        self::assertStringContainsString('Инженер-испытатель', $xml);
        self::assertStringContainsString('1   Наименование образца испытаний:', $xml);
        self::assertStringContainsString('2   Основание проведения испытаний:', $xml);
        self::assertStringContainsString('3   Результат испытаний:', $xml);
        self::assertStringContainsString('Образец &lt;лифта&gt; &amp; подъёмника', $xml);
        self::assertStringContainsString('&lt;w:t&gt;Не разметка&lt;/w:t&gt;', $xml);
        self::assertStringNotContainsString('<w:t>Не разметка</w:t>', $xml);
        self::assertStringContainsString('должность', $xml);
        self::assertStringContainsString('подпись', $xml);
        self::assertStringContainsString('ФИО', $xml);
    }
}
