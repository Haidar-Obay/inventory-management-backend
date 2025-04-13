<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

class DynamicExcelImport implements ToModel, WithHeadingRow, SkipsEmptyRows
{
    private int $currentRow = 1;
    private int $imported = 0;
    private array $skipped = [];

    private string $modelClass;
    private array $requiredFields;
    private \Closure $validator;
    private \Closure $mapper;

    public function __construct(string $modelClass, array $requiredFields, \Closure $validator, \Closure $mapper)
    {
        $this->modelClass = $modelClass;
        $this->requiredFields = $requiredFields;
        $this->validator = $validator;
        $this->mapper = $mapper;
    }

    public function model(array $row)
    {
        $this->currentRow++;

        // Collect validation errors from controller-defined validator
        $errors = call_user_func($this->validator, $row);

        if (!empty($errors)) {
            $this->skipped[] = [
                'row' => $this->currentRow,
                'reasons' => $errors,
            ];
            return null;
        }

        $this->imported++;

        $modelData = call_user_func($this->mapper, $row);
        return new $this->modelClass($modelData);
    }

    public function getImportedCount(): int
    {
        return $this->imported;
    }

    public function getSkippedCount(): int
    {
        return count($this->skipped);
    }

    public function getSkippedRows(): array
    {
        return $this->skipped;
    }
}
