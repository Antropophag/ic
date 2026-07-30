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
     * @param array{number: int, productName: string, manufacturer: string, supplier: string, expertName: string, expertPosition: string, body: string, date: string} $data
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
            . '@page{margin:22mm 19mm 20mm}'
            . 'body{margin:0;color:#172337;font-family:"DejaVu Sans",sans-serif;font-size:11px;line-height:1.5}'
            . '.document-header{padding-bottom:18px;border-bottom:2px solid #253d98}'
            . '.brand{width:100%;border-collapse:collapse}.brand-mark{width:42px;vertical-align:middle}'
            . '.brand-copy{padding-left:12px;vertical-align:middle}.brand-copy strong{display:block;color:#253d98;font-size:12px}'
            . '.brand-copy span{display:block;margin-top:2px;color:#6e7a8b;font-size:9px;letter-spacing:.08em;text-transform:uppercase}'
            . 'h1{margin:22px 0 5px;color:#253d98;font-size:20px;line-height:1.3;font-weight:600}'
            . '.request-number{margin:0;color:#6e7a8b;font-size:10px;letter-spacing:.05em;text-transform:uppercase}'
            . '.section{margin-top:24px}.section-title{margin:0 0 11px;color:#253d98;font-size:12px;font-weight:600}'
            . '.facts{width:100%;border-collapse:collapse;border-top:1px solid #dde3ec;border-bottom:1px solid #dde3ec}'
            . '.facts td{width:33.333%;padding:11px 12px 12px 0;vertical-align:top}'
            . '.facts td+td{padding-left:12px;border-left:1px solid #dde3ec}'
            . '.label{display:block;margin-bottom:4px;color:#8a96a7;font-size:8px;letter-spacing:.08em;text-transform:uppercase}'
            . '.value{display:block;color:#172337;font-size:11px;font-weight:600;line-height:1.4}'
            . '.opinion{min-height:180px;margin:0;padding:15px 0 19px;border-top:1px solid #dde3ec;border-bottom:1px solid #dde3ec;white-space:pre-wrap;font-size:11.5px;line-height:1.65}'
            . '.signoff{width:100%;margin-top:25px;border-collapse:collapse}.signoff td{vertical-align:bottom}'
            . '.expert{padding-right:20px}.date{width:125px;text-align:right}'
            . '.signoff .value{font-weight:500}.position{display:block;margin-top:2px;color:#6e7a8b;font-size:9.5px}'
            . '</style></head><body>'
            . '<header class="document-header"><table class="brand"><tr><td class="brand-mark">'
            . '<svg width="40" height="40" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">'
            . '<rect x="2" y="2" width="36" height="36" rx="10" fill="#253d98"/>'
            . '<path d="M12 25a8 8 0 1 1 16 0" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'
            . '<path d="M12 25h2M26 25h2M20 15v2" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>'
            . '<path d="M20 25l5-6.5" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'
            . '<circle cx="20" cy="25" r="1.6" fill="#fff"/></svg></td>'
            . '<td class="brand-copy"><strong>Испытательный центр</strong><span>АО «ЩЛЗ» · Портал заявок</span></td>'
            . '</tr></table><h1>Экспертное заключение</h1>'
            . '<p class="request-number">Заявка № ' . $data['number'] . '</p></header>'
            . '<section class="section"><h2 class="section-title">Объект испытаний</h2><table class="facts"><tr>'
            . '<td><span class="label">Наименование</span><span class="value">' . $escape($data['productName']) . '</span></td>'
            . '<td><span class="label">Производитель</span><span class="value">' . $escape($data['manufacturer']) . '</span></td>'
            . '<td><span class="label">Поставщик</span><span class="value">' . $escape($data['supplier']) . '</span></td>'
            . '</tr></table></section>'
            . '<section class="section"><h2 class="section-title">Заключение</h2>'
            . '<p class="opinion">' . $escape($data['body']) . '</p></section>'
            . '<table class="signoff"><tr><td class="expert"><span class="label">Эксперт</span>'
            . '<span class="value">' . $escape($data['expertName']) . '</span>'
            . '<span class="position">' . $escape($data['expertPosition']) . '</span></td>'
            . '<td class="date"><span class="label">Дата</span><span class="value">' . $escape($data['date']) . '</span></td>'
            . '</tr></table></body></html>';
    }
}
