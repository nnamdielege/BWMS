<?php

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\Setting;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a PO as PDF with mPDF. NFR-6: fonts come only from mPDF's bundled
 * DejaVu set on local disk, and the template references no remote resources
 * (enforced by a test), so rendering never touches the network.
 */
class PurchaseOrderPdf
{
    public function render(PurchaseOrder $po): string
    {
        $po->loadMissing(['supplier', 'site', 'lines.product', 'lines.unit']);

        $tempDir = storage_path('framework/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'fontDir' => (new ConfigVariables)->getDefaults()['fontDir'], // bundled fonts only
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'],
            'default_font' => 'dejavusans',
            'margin_top' => 14,
            'margin_bottom' => 16,
            'margin_left' => 12,
            'margin_right' => 12,
        ]);
        $mpdf->SetTitle($po->number);
        $mpdf->SetCreator(config('app.name'));
        $mpdf->SetHTMLFooter('<div style="font-size:8pt;color:#666;text-align:center">'.e($po->number).' · page {PAGENO} of {nbpg}</div>');

        $mpdf->WriteHTML(view('pdf.purchase-order', [
            'po' => $po,
            'company' => Setting::all_(),
        ])->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function filename(PurchaseOrder $po): string
    {
        return $po->number.'.pdf';
    }
}
