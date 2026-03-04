<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class Export implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    protected Builder|Collection $query;

    protected array $columns;

    protected array $headings;

    public function __construct(Builder|Collection $query, array $columns, array $headings)
    {
        $this->query = $query;
        $this->columns = $columns;
        $this->headings = $headings;
    }

    public function collection()
    {
        return $this->query instanceof Collection ? $this->query : $this->query->get();
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function map($row): array
    {
        $mapped = [];
        foreach ($this->columns as $column) {
            $value = data_get($row, $column);
            $mapped[] = $this->formatValue($value);
        }

        return $mapped;
    }

    protected function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        // Format Date/Time values as m/d/Y (e.g., 9/29/2025)
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('n/j/Y');
        }
        if (is_string($value)) {
            // Detect ISO-like timestamp strings and format
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                try {
                    return Carbon::parse($value)->format('n/j/Y');
                } catch (\Throwable $e) {
                    // fall through to raw string
                }
            }
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
