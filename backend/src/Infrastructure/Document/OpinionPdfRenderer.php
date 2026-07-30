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
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->renderHtml($data), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }

    /**
     * @param array{
     *     number: int,
     *     productName: string,
     *     manufacturer: string,
     *     supplier: string,
     *     expertName: string,
     *     expertPosition: string,
     *     body: string,
     *     date: string
     * } $data
     * @internal Public to keep the document template directly testable without parsing a PDF binary.
     */
    public function renderHtml(array $data): string
    {
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>'
            . '@page{margin:20mm}'
            . 'body{margin:0;color:#243047;font-family:"DejaVu Sans",sans-serif;font-size:10.5px;line-height:1.55}'
            . '.accent-rail{position:fixed;top:-20mm;bottom:-20mm;left:-20mm;width:12mm;background:#253d98}'
            . '.document{margin-left:7mm}'
            . '.kicker{color:#253d98;font-size:9px;letter-spacing:.12em;text-transform:uppercase}'
            . 'h1{margin:10px 0 4px;color:#243047;font-size:24px;line-height:1.25;font-weight:600}'
            . '.request-number{margin:0 0 28px;color:#768196;font-size:13px}'
            . '.facts{padding:15px 17px;border-radius:8px;background:#f0f3f8}'
            . '.fact{margin:0 0 9px}.fact:last-child{margin-bottom:0}'
            . '.label{display:inline-block;width:120px;color:#7a8596;font-size:8px;letter-spacing:.04em;'
            . 'text-transform:uppercase}'
            . '.value{color:#243047}.primary-value{font-weight:600}'
            . '.section-title{margin:27px 0 10px;color:#253d98;font-size:12px;font-weight:600}'
            . '.opinion{min-height:195px;margin:0;white-space:pre-wrap;font-size:12px;line-height:1.8}'
            . '.signoff{width:100%;border-collapse:collapse;border-top:2px solid #253d98}'
            . '.signoff td{padding-top:13px;vertical-align:top}'
            . '.expert-name{font-weight:600}.position{display:block;color:#7a8596;font-size:9px}'
            . '.date{width:145px;text-align:right}'
            . '</style></head><body><div class="accent-rail"></div><main class="document">'
            . '<header><div class="kicker">АО «ЩЛЗ» · Испытательный центр</div>'
            . '<h1>Экспертное заключение</h1>'
            . '<p class="request-number">Заявка № ' . $data['number'] . '</p></header>'
            . '<section class="facts">'
            . '<div class="fact"><span class="label">Изделие</span><span class="value primary-value">'
            . $escape($data['productName']) . '</span></div>'
            . '<div class="fact"><span class="label">Производитель</span><span class="value">'
            . $escape($data['manufacturer']) . '</span></div>'
            . '<div class="fact"><span class="label">Поставщик</span><span class="value">'
            . $escape($data['supplier']) . '</span></div>'
            . '</section><section><h2 class="section-title">Заключение</h2>'
            . '<p class="opinion">' . $escape($data['body']) . '</p></section>'
            . '<table class="signoff"><tr><td><span class="expert-name">' . $escape($data['expertName']) . '</span>'
            . '<span class="position">' . $escape($data['expertPosition']) . '</span></td>'
            . '<td class="date">' . $escape($data['date']) . '</td>'
            . '</tr></table></main></body></html>';
    }
}
