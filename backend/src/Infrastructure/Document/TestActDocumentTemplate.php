<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use App\Application\Document\TestActDocumentData;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Element\Section;

final class TestActDocumentTemplate
{
    public function __construct(
        private readonly string $logoPath = __DIR__ . '/../../../resources/documents/shlz-logo.png',
    ) {
    }

    public function build(PhpWord $document, TestActDocumentData $data): void
    {
        $document->setDefaultFontName('Times New Roman');
        $document->setDefaultFontSize(12);
        $section = $document->addSection([
            'marginTop' => Converter::cmToTwip(2),
            'marginRight' => Converter::cmToTwip(1),
            'marginBottom' => Converter::cmToTwip(2),
            'marginLeft' => Converter::cmToTwip(2.2),
        ]);

        $header = $section->addTable(['cellMargin' => 0]);
        $header->addRow();
        $logoCell = $header->addCell((int) Converter::cmToTwip(12.4), ['valign' => 'top']);
        $headerBlockHeightPoints = 84;
        $logoCell->addImage($this->logoPath, [
            'width' => 212,
            'height' => $headerBlockHeightPoints,
        ]);
        $rightCell = $header->addCell((int) Converter::cmToTwip(5.4), ['valign' => 'top']);
        $contacts = $rightCell->addTable(['cellMargin' => 0]);
        $contacts->addRow((int) Converter::pointToTwip($headerBlockHeightPoints), ['exactHeight' => true]);
        $contacts->addCell((int) Converter::cmToTwip(0.12), ['bgColor' => '0B1893']);
        $contacts->addCell((int) Converter::cmToTwip(0.29));
        $contactCell = $contacts->addCell((int) Converter::cmToTwip(4.99), ['valign' => 'top']);
        $contactParagraph = ['spaceAfter' => 0, 'lineHeight' => 1.0];
        foreach (
            [
                'АО «ЩЛЗ»',
                'ОГРН 1025007512474',
                'ИНН 5051000880',
                '108851, г. Москва, г. Щербинка,',
                'ул. Первомайская, д. 6, стр. 2',
                'тел. (495)739-67-02',
                'e-mail: ' . $data->contactEmail,
            ] as $contactLine
        ) {
            $contactCell->addText($contactLine, ['size' => 10], $contactParagraph);
        }

        $rightCell->addText('УТВЕРЖДАЮ', ['bold' => true, 'size' => 12], [
            'spaceBefore' => Converter::pointToTwip(11),
            'spaceAfter' => 0,
            'alignment' => Jc::CENTER,
        ]);
        $rightCell->addText('Руководитель ИЦ', ['size' => 12], [
            'spaceAfter' => Converter::pointToTwip(2),
            'alignment' => Jc::CENTER,
        ]);
        $approvalSignature = $rightCell->addTable(['cellMargin' => 0]);
        $approvalSignature->addRow();
        $approvalSignature->addCell((int) Converter::cmToTwip(2.1), [
            'borderBottomSize' => 6,
            'borderBottomColor' => '000000',
        ])->addText(
            '',
            [],
            ['spaceAfter' => 0, 'alignment' => Jc::CENTER],
        );
        $approvalSignature->addCell((int) Converter::cmToTwip(3.3), [
            'borderBottomSize' => 6,
            'borderBottomColor' => '000000',
        ])->addText(
            $data->testCenterManagerName,
            ['size' => 10],
            ['spaceAfter' => 0, 'alignment' => Jc::CENTER],
        );
        $approvalSignature->addRow();
        $approvalSignature->addCell()->addText(
            'подпись',
            ['size' => 10],
            ['spaceAfter' => 0, 'alignment' => Jc::CENTER],
        );
        $approvalSignature->addCell()->addText(
            'ФИО',
            ['size' => 10],
            ['spaceAfter' => 0, 'alignment' => Jc::CENTER],
        );
        $rightCell->addText('«____» ______________ 20___ г.', ['size' => 11], [
            'spaceAfter' => 0,
            'alignment' => Jc::CENTER,
        ]);

        $section->addText('АКТ ИСПЫТАНИЙ № ' . $data->actNumber, ['bold' => true, 'size' => 12], [
            'alignment' => Jc::CENTER,
            'spaceBefore' => Converter::pointToTwip(12),
            'spaceAfter' => Converter::pointToTwip(4),
        ]);
        $section->addText(
            'от ' . $data->actDate,
            ['bold' => true],
            ['alignment' => Jc::CENTER, 'spaceAfter' => Converter::pointToTwip(18)],
        );

        $this->addField($section, '1   Наименование образца испытаний:', $data->sampleName);
        $this->addField($section, '2   Основание проведения испытаний:', $data->basis);
        $this->addField($section, '3   Результат испытаний:', $data->result);

        $section->addTextBreak(3);
        $signatures = $section->addTable(['cellMargin' => 80]);
        $signatures->addRow();
        $signatureValues = [
            $data->executorPosition !== '' ? $data->executorPosition : ' ',
            ' ',
            $data->executorName,
        ];
        foreach ($signatureValues as $index => $value) {
            $signatures->addCell((int) Converter::cmToTwip(4.9), [
                'borderBottomSize' => 6,
                'borderBottomColor' => '000000',
            ])->addText($value, [], ['spaceAfter' => 0, 'alignment' => Jc::CENTER]);
            if ($index < 2) {
                $signatures->addCell((int) Converter::cmToTwip(0.65));
            }
        }
        $signatures->addRow();
        foreach (['должность', 'подпись', 'ФИО'] as $index => $label) {
            $signatures->addCell((int) Converter::cmToTwip(4.9))->addText($label, ['size' => 9], ['alignment' => Jc::CENTER]);
            if ($index < 2) {
                $signatures->addCell((int) Converter::cmToTwip(0.65));
            }
        }
    }

    private function addField(Section $section, string $label, string $value): void
    {
        $paragraph = $section->addTextRun([
            'spaceAfter' => Converter::pointToTwip(8),
            'lineHeight' => 1.15,
        ]);
        $paragraph->addText($label . ' ', ['bold' => true]);
        $paragraph->addText($value);
    }
}
