<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;


class Export implements FromCollection, WithHeadings, ShouldAutoSize, WithMapping
{
    protected $query;
    protected $columns;
    protected $headings;

    public function __construct(Builder $query, array $columns, array $headings)
    {
        $this->query = $query;
        $this->columns = $columns;
        $this->headings = $headings;
    }

    public function collection()
    {
        return $this->query->get($this->columns);
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
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string)$value;
    }
}
