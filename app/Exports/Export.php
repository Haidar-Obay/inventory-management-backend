<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;


class Export implements FromCollection, WithHeadings, ShouldAutoSize
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
}
