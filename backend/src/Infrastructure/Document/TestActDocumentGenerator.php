<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use App\Application\Document\TestActDocumentData;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

final class TestActDocumentGenerator
{
    public function __construct(private readonly TestActDocumentTemplate $template = new TestActDocumentTemplate())
    {
    }

    public function generate(TestActDocumentData $data): string
    {
        Settings::setOutputEscapingEnabled(true);
        $document = new PhpWord();
        $document->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('ru-RU'));
        $this->template->build($document, $data);

        $writer = IOFactory::createWriter($document, 'Word2007');
        ob_start();
        try {
            $writer->save('php://output');
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if ($content === false || $content === '') {
            throw new \RuntimeException('Не удалось сформировать акт испытаний.');
        }

        return $content;
    }
}
