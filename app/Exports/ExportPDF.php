<?php

namespace App\Exports;

use Barryvdh\DomPDF\Facade\Pdf;

class ExportPDF
{
    public function generatePdf(string $title, array $headers, array $data)
    {
        $html = "<h1 style='text-align: center; margin-bottom: 30px;'>{$title}</h1>";
        $html .= '<table border="1" cellpadding="8" cellspacing="0" width="100%" style="border-collapse: collapse; text-align: center;">';
        $html .= '<thead><tr>';

        // Headers with centered style
        foreach ($headers as $key => $label) {
            $html .= "<th style='text-align: center; font-weight: bold; background-color: #f2f2f2;'>{$label}</th>";
        }

        $html .= '</tr></thead><tbody>';

        // Data rows with centered cells
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($headers as $key => $label) {
                $html .= '<td style="text-align: center;">' . ($row[$key] ?? '-') . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return Pdf::loadHTML($html)
            ->setPaper('a2', 'landscape');
    }

}
