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
            . '@page{margin:17mm 18mm 18mm}'
            . 'body{margin:0;color:#142033;font-family:"DejaVu Sans",sans-serif;font-size:10px;line-height:1.55}'
            . '.accent-rail{position:fixed;top:-17mm;bottom:-18mm;left:-18mm;width:4mm;background:#253d98}'
            . '.document{margin-left:2mm}'
            . '.brand{width:100%;border-collapse:collapse;margin-bottom:18mm}'
            . '.brand-mark{width:28px;height:28px;background:#253d98;color:#fff;text-align:center;font-size:12px;font-weight:bold}'
            . '.brand-copy{padding-left:9px;color:#142033;font-size:10px;font-weight:600;letter-spacing:.02em}'
            . '.brand-copy span{display:block;color:#7a8698;font-size:7px;font-weight:normal;letter-spacing:.08em;text-transform:uppercase}'
            . '.document-kind{text-align:right;color:#7a8698;font-size:8px;letter-spacing:.07em;text-transform:uppercase}'
            . '.kicker{margin-bottom:6px;color:#657286;font-size:8px;letter-spacing:.09em;text-transform:uppercase}'
            . 'h1{margin:0;color:#142033;font-size:23px;line-height:1.25;font-weight:600;letter-spacing:-.02em}'
            . '.request-number{display:inline-block;margin:9px 0 22px;padding:5px 10px;border-radius:11px;background:#e8ebfb;color:#29449f;font-size:9px;font-weight:600}'
            . '.facts{padding:14px 16px;border:1px solid #e6eaf0;border-radius:10px;background:#f8f9fb}'
            . '.fact{margin:0;padding:7px 0;border-bottom:1px solid #e6eaf0}.fact:first-child{padding-top:0}.fact:last-child{padding-bottom:0;border-bottom:0}'
            . '.label{display:inline-block;width:118px;color:#7a8698;font-size:7.5px;letter-spacing:.04em;text-transform:uppercase}'
            . '.value{color:#263348}.primary-value{font-weight:600}'
            . '.section-title{margin:22px 0 8px;color:#142033;font-size:11px;font-weight:600}'
            . '.section-title:before{content:"";display:inline-block;width:14px;height:3px;margin:0 7px 2px 0;background:#253d98}'
            . '.opinion{min-height:185px;margin:0;white-space:pre-wrap;color:#263348;font-size:11px;line-height:1.75}'
            . '.signoff{width:100%;border-collapse:collapse;border-top:1px solid #dfe4eb}'
            . '.signoff td{padding-top:12px;vertical-align:top}'
            . '.expert-name{font-weight:600}.position{display:block;margin-top:2px;color:#7a8698;font-size:8px}'
            . '.date{width:145px;text-align:right;color:#536075}'
            . '</style></head><body><div class="accent-rail"></div><main class="document">'
            . '<table class="brand"><tr><td class="brand-mark">ИЦ</td><td class="brand-copy">АО «ЩЛЗ»<span>Испытательный центр</span></td>'
            . '<td class="document-kind">Экспертный документ</td></tr></table>'
            . '<header><div class="kicker">Результат рассмотрения заявки</div><h1>Экспертное заключение</h1>'
            . '<p class="request-number">Заявка № ' . $data['number'] . '</p></header>'
            . '<section class="facts">'
            . '<div class="fact"><span class="label">Объект испытаний</span><span class="value primary-value">'
            . $escape($data['productName']) . '</span></div>'
            . '<div class="fact"><span class="label">Производитель</span><span class="value">'
            . $escape($data['manufacturer']) . '</span></div>'
            . '<div class="fact"><span class="label">Поставщик</span><span class="value">'
            . $escape($data['supplier']) . '</span></div>'
            . '</section><section><h2 class="section-title">Заключение эксперта</h2>'
            . '<p class="opinion">' . $escape($data['body']) . '</p></section>'
            . '<table class="signoff"><tr><td><span class="expert-name">' . $escape($data['expertName']) . '</span>'
            . '<span class="position">' . $escape($data['expertPosition']) . '</span></td>'
            . '<td class="date">Дата заключения<br><strong>' . $escape($data['date']) . '</strong></td>'
            . '</tr></table></main></body></html>';
    }
}
