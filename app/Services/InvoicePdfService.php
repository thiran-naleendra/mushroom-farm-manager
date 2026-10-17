<?php

namespace App\Services;

use App\Models\Invoice;

class InvoicePdfService
{
    public function render(Invoice $invoice): string
    {
        $summary = $invoice->summary();
        $lines = ['INVOICE '.$invoice->number, strtoupper($invoice->status).' | '.$invoice->sold_on.' | '.$invoice->currency, ''];
        foreach (['seller_snapshot' => 'FROM', 'customer_snapshot' => 'BILL TO'] as $field => $label) {
            $lines[] = $label;
            foreach ($invoice->$field as $value) {
                if ($value) {
                    $lines[] = $value;
                }
            }
            $lines[] = '';
        }
        foreach ($invoice->lines as $i => $line) {
            $lines[] = ($i + 1).'. '.$line->product_name.' ('.$line->sku.')';
            $lines[] = $line->variety.($line->type === 'packaged' ? ' | '.$line->pack_size_grams.'g' : '');
            $lines[] = $line->saleDescription().' @ '.$line->selling_price.' | Discount '.$line->discount.' | Total '.$line->total;
        }
        $lines = [...$lines, '', 'Subtotal: '.$invoice->subtotal, 'Discount: '.$invoice->discount, 'Original total: '.$invoice->total, 'Return credits: '.$summary['returns'], 'Net sales: '.$summary['net'], 'Payments received: '.$summary['paid'], 'Refunds paid: '.$summary['refunds'], 'Balance (negative = refund due): '.$summary['balance'], 'Payment status: '.$summary['payment_status'], ''];
        foreach ($invoice->payments as $payment) {
            $lines[] = $payment->paid_on.' '.$payment->type.' '.$payment->method.' '.$payment->amount.' '.$payment->reference;
        }
        foreach ($invoice->returns as $return) {
            $lines[] = 'Return '.$return->returned_on.' credit '.$return->credit.': '.$return->reason;
        }
        if ($invoice->notes) {
            $lines[] = 'Notes: '.$invoice->notes;
        }
        if ($invoice->void_reason) {
            $lines[] = 'Void reason: '.$invoice->void_reason;
        }
        $wrapped = [];
        foreach ($lines as $line) {
            // Standard PDF fonts use Latin text; the browser print view preserves original Unicode names.
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line);
            foreach (explode("\n", wordwrap(str_replace("\r", '', $text === false ? '' : $text), 85, "\n", true)) as $row) {
                $wrapped[] = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $row);
            }
        }
        $pages = array_chunk($wrapped, 50);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>'];
        $kids = [];
        foreach ($pages as $index => $rows) {
            $pageId = 4 + $index * 2;
            $streamId = $pageId + 1;
            $kids[] = "$pageId 0 R";
            $stream = "BT /F1 10 Tf 14 TL 40 790 Td\n";
            foreach ([...$rows, '', 'Page '.($index + 1).' / '.count($pages)] as $row) {
                $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $row).") Tj T*\n";
            }
            $stream .= 'ET';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents $streamId 0 R >>";
            $objects[$streamId] = '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$object\nendobj\n";
        }
        $start = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n$start\n%%EOF\n";
    }
}
