<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use Dompdf\Dompdf;
use Dompdf\Options;

final class OpinionPdfRenderer
{
    /** @param array{number: int, productName: string, manufacturer: string, supplier: string, expertName: string, expertPosition: string, body: string, date: string} $data */
    public function render(array $data): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml('<!doctype html><html lang="ru"><meta charset="utf-8"><style>body{font-family:"DejaVu Sans";font-size:12px}h1{text-align:center;font-size:18px}dt{font-weight:bold;margin-top:8px}p{white-space:pre-wrap;line-height:1.5}</style><body>'
            . '<h1>Экспертное заключение по заявке № ' . $data['number'] . '</h1>'
            . '<dl><dt>Объект испытаний</dt><dd>' . $escape($data['productName']) . '</dd>'
            . '<dt>Производитель</dt><dd>' . $escape($data['manufacturer']) . '</dd>'
            . '<dt>Поставщик</dt><dd>' . $escape($data['supplier']) . '</dd></dl>'
            . '<h2>Заключение</h2><p>' . $escape($data['body']) . '</p>'
            . '<p>Эксперт: ' . $escape($data['expertName']) . ', ' . $escape($data['expertPosition']) . '<br>Дата: ' . $escape($data['date']) . '</p>'
            . '</body></html>', 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }
}
