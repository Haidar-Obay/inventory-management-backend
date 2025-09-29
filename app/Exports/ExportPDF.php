<?php

namespace App\Exports;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ExportPDF
{
    public function generatePdf(string $title, array $headers, array $data, array $options = [])
    {
        // Pull defaults from request if not explicitly provided
        $req = function_exists('request') ? request() : null;
        $orientation = $options['orientation'] ?? ($req ? $req->input('orientation', 'landscape') : 'landscape');
        $fit = $options['fit'] ?? ($req ? $req->input('fit', 'scale') : 'scale'); // 'scale' | 'wrap'
        $fontSizeInput = $options['fontSize'] ?? ($req ? $req->input('fontSize') : null);
        $fontSize = max(6, min(14, (int)($fontSizeInput ?? 9)));

        $cellPadding = $fit === 'scale' ? 4 : 6;
        $titleMargin = $fit === 'scale' ? 16 : 24;

        $html = "<h1 style=\"text-align: center; margin-bottom: {$titleMargin}px; font-size: " . ($fontSize + 6) . "px;\">{$title}</h1>";
        $html .= '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse; table-layout: fixed;">';
        $html .= '<thead><tr>';

        // Headers with centered style
        foreach ($headers as $key => $label) {
            $html .= "<th style=\"text-align: center; font-weight: bold; background-color: #f2f2f2; padding: {$cellPadding}px; font-size: {$fontSize}px;\">{$label}</th>";
        }

        $html .= '</tr></thead><tbody>';

        // Data rows with centered cells
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($headers as $key => $label) {
                $cellStyles = [
                    'text-align: left',
                    "padding: {$cellPadding}px",
                    "font-size: {$fontSize}px",
                    'white-space: normal',
                    'word-wrap: break-word',
                    'overflow-wrap: anywhere',
                ];
                $rawValue = $row[$key] ?? null;
                $displayValue = $this->formatValue($rawValue);
                $html .= '<td style="' . implode('; ', $cellStyles) . '">' . htmlspecialchars($displayValue) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return Pdf::loadHTML($html)
            ->setPaper('a2', $orientation);
    }

    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null || $value === '') {
            return '-';
        }
        // Format Date/Time values as m/d/Y (e.g., 9/29/2025)
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('n/j/Y');
        }
        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                try {
                    return Carbon::parse($value)->format('n/j/Y');
                } catch (\Throwable $e) {}
            }
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string)$value;
    }
 
}
