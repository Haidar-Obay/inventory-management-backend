<?php

namespace App\Imports;

use App\Helpers\MigrationHeaderExtractor;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Events\BeforeImport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class DynamicExcelImport implements SkipsEmptyRows, ToModel, WithEvents, WithHeadingRow, WithValidation
{
    private int $currentRow = 1;

    private int $imported = 0;

    private array $skipped = [];

    private bool $headerValidated = false;

    private array $headerValidationResult = [];

    private bool $headersAreValid = true;

    private string $modelClass;

    private array $requiredFields;

    private \Closure $validator;

    private \Closure $mapper;

    private bool $validateHeaders;

    private bool $skipDuplicateCheck;

    public function __construct(string $modelClass, array $requiredFields, \Closure $validator, \Closure $mapper, bool $validateHeaders = true, bool $skipDuplicateCheck = false)
    {
        $this->modelClass = $modelClass;
        $this->requiredFields = $requiredFields;
        $this->validator = $validator;
        $this->mapper = $mapper;
        $this->validateHeaders = $validateHeaders;
        $this->skipDuplicateCheck = $skipDuplicateCheck;
    }

    public function model(array $row)
    {
        $this->currentRow++;

        // If headers are invalid, skip all rows
        if (! $this->headersAreValid) {
            return;
        }

        // Collect validation errors from controller-defined validator
        $errors = call_user_func($this->validator, $row);

        if (! empty($errors)) {
            $this->skipped[] = [
                'row' => $this->currentRow,
                'reasons' => $errors,
            ];

            return;
        }

        $modelData = call_user_func($this->mapper, $row);
        // Normalize scalar string fields to avoid false duplicate positives/negatives
        foreach ($modelData as $key => $value) {
            if (is_string($value)) {
                $normalized = trim($value);
                // Convert common excel boolean-like strings
                if (strtolower($normalized) === 'true') {
                    $modelData[$key] = true;
                } elseif (strtolower($normalized) === 'false') {
                    $modelData[$key] = false;
                } else {
                    $modelData[$key] = $normalized;
                }
            }
        }

        // Check if record already exists based on unique fields (unless explicitly disabled)
        $existingRecord = $this->skipDuplicateCheck ? null : $this->findExistingRecord($modelData);

        if ($existingRecord) {
            // Log the duplicate detection for debugging
            Log::info('Duplicate record detected during import', [
                'row' => $this->currentRow,
                'modelData' => $modelData,
                'existingRecord' => [
                    'id' => $existingRecord->id,
                    'code' => $existingRecord->code ?? 'N/A',
                    'name' => $existingRecord->name ?? 'N/A',
                ],
            ]);

            $this->skipped[] = [
                'row' => $this->currentRow,
                'reasons' => ['Record already exists'],
            ];

            return;
        }

        $this->imported++;

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

    /**
     * Get header validation result
     */
    public function getHeaderValidationResult(): array
    {
        return $this->headerValidationResult;
    }

    /**
     * Check if headers are valid
     */
    public function areHeadersValid(): bool
    {
        return $this->headersAreValid;
    }

    /**
     * Get validation rules for headers
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Register events
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                if ($this->validateHeaders) {
                    $this->validateHeadersBeforeImport($event);
                }
            },
        ];
    }

    /**
     * Find existing record based on unique fields
     *
     * @return mixed
     */
    public function findExistingRecord(array $modelData)
    {
        try {
            $model = new $this->modelClass;
            $table = $model->getTable();

            // Get unique fields from the model's fillable attributes and database constraints
            $uniqueFields = $this->getUniqueFields($table);

            if (empty($uniqueFields)) {
                return;
            }

            $query = $model->newQuery();

            foreach ($uniqueFields as $field) {
                if (isset($modelData[$field]) && $modelData[$field] !== null && $modelData[$field] !== '') {
                    $value = $modelData[$field];
                    if (is_string($value)) {
                        // Case-insensitive, trimmed comparison for strings (portable across DBs)
                        $query->whereRaw('LOWER(TRIM('.$field.')) = ?', [mb_strtolower(trim($value))]);
                    } else {
                        $query->where($field, $value);
                    }
                }
            }

            return $query->first();
        } catch (\Exception $e) {
            // If there's any error in the query, return null to avoid false positives
            Log::warning('Error in findExistingRecord: '.$e->getMessage(), [
                'modelData' => $modelData,
                'modelClass' => $this->modelClass,
            ]);

            return;
        }
    }

    /**
     * Get unique fields for a table
     */
    private function getUniqueFields(string $table): array
    {
        // Define unique fields for each table
        $uniqueFieldsMap = [
            'customers' => ['phone1', 'phone2', 'phone3'],
            'suppliers' => ['phone1', 'phone2', 'phone3'],
            // Composite/aliased tables
            'projects_jobs' => ['code'],
            'items' => ['code'],
            'salesmen' => ['code'],
            'product_lines' => ['code'],
            'trades' => ['code'],
            'company_codes' => ['code'],
            'brands' => ['code'],
            'categories' => ['code'],
            'customer_groups' => ['code'],
            'item_groups' => ['code'],
            'supplier_groups' => ['code'],
            'payment_terms' => ['code'],
            'branches' => ['code'],
            'adjustment_types' => ['code'],
            'countries' => ['name'],
            'cities' => ['name'],
            'districts' => ['name'],
            'zones' => ['name'],
            'warehouses' => ['code'],
            'rooms' => ['code'],
            'sections' => ['code'],
            'cost_centers' => ['code'],
            'departments' => ['code'],
            'jobs' => ['code'],
            'projects' => ['name'],
            'sales_channels' => ['code'],
            'distribution_channels' => ['code'],
            'transportation_channels' => ['code'],
            'media_channels' => ['code'],
            'payment_methods' => ['code'],
            'refer_bys' => ['code'],
            'business_types' => ['code'],
            'transaction_series' => ['code'],
        ];

        return $uniqueFieldsMap[$table] ?? [];
    }

    /**
     * Validate headers before import starts
     */
    private function validateHeadersBeforeImport(BeforeImport $event): void
    {
        $reader = $event->getReader();
        $worksheet = $reader->getActiveSheet();
        $highestColumn = $worksheet->getHighestColumn();
        $highestRow = $worksheet->getHighestRow();

        if ($highestRow < 1) {
            $this->headersAreValid = false;
            $this->headerValidationResult = [
                'valid' => false,
                'missing' => [],
                'extra' => [],
                'matches' => [],
                'excel_headers' => [],
                'expected_headers' => [],
                'error' => 'No data found in Excel file',
            ];

            return;
        }

        // Get the first row (headers) across all columns, even beyond 'Z'
        $excelHeaders = [];
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
            $cellValue = $worksheet->getCellByColumnAndRow($colIndex, 1)->getValue();
            if ($cellValue !== null && $cellValue !== '') {
                $excelHeaders[] = trim((string) $cellValue);
            }
        }

        // Get expected headers from migration
        $expectedHeaders = MigrationHeaderExtractor::getExpectedHeadersForModel($this->modelClass);

        // Compare headers: require only the fields the controller marked as required
        $this->headerValidationResult = MigrationHeaderExtractor::compareHeaders($excelHeaders, $expectedHeaders, $this->requiredFields);
        $this->headersAreValid = $this->headerValidationResult['valid'];
    }
}
