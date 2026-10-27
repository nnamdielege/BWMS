<?php

namespace App\Services\Documents;

use App\Support\Branding;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Shared mPDF setup for warehouse documents (packing slips, bin labels).
 * NFR-6: bundled DejaVu fonts only and no remote resources in templates,
 * so rendering never touches the network.
 */
class PdfRenderer
{
    public function render(string $view, array $data, string $title, array $options = []): string
    {
        $tempDir = storage_path('framework/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $mpdf = new Mpdf($options + [
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'fontDir' => (new ConfigVariables)->getDefaults()['fontDir'],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'],
            'default_font' => 'dejavusans',
            'margin_top' => 10, 'margin_bottom' => 12, 'margin_left' => 10, 'margin_right' => 10,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator(config('app.name'));
        $mpdf->WriteHTML(view($view, $data + ['logoPath' => Branding::logoPath()])->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
