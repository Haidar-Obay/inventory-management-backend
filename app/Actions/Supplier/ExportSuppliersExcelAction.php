<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Exports\Export;
use App\Models\Supplier;
use Maatwebsite\Excel\Facades\Excel;

class ExportSuppliersExcelAction
{
    /**
     * @return array{type: 'not_found'}|array{type: 'download', response: \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response}|array{type: 'exception', message: string}
     */
    public function execute(): array
    {
        try {
            $suppliers = Supplier::with([
                'supplierGroup:id,name',
                'trade:id,name',
                'businessType:id,name',
                'currency:id,code,name',
                'openingBalances.paymentTerm:id,code',
                'openingBalances.paymentMethod:id,code',
            ]);

            if ($suppliers->count() === 0) {
                return ['type' => 'not_found'];
            }

            $columns = [
                'id', 'title', 'first_name', 'middle_name', 'last_name', 'display_name',
                'company_name', 'phone1', 'phone2', 'phone3', 'file_number', 'bar_code',
                'search_terms', 'indicator', 'invoicing_mode', 'opening_amount', 'opening_date', 'credit_limit',
                'max_cheques', 'taxable', 'taxed_from_date', 'taxed_till_date',
                'subjected_to_tax', 'added_tax', 'is_foreign', 'active',
                'message', 'notes', 'created_at', 'updated_at',
            ];

            $headings = [
                'ID', 'Title', 'First Name', 'Middle Name', 'Last Name', 'Display Name',
                'Company Name', 'Phone 1', 'Phone 2', 'Phone 3', 'File Number', 'Bar Code',
                'Search Terms', 'Indicator', 'Invoicing Mode', 'Opening Amount', 'Opening Date', 'Credit Limit',
                'Max Cheques', 'Taxable', 'Taxed From Date', 'Taxed Till Date',
                'Subjected to Tax', 'Added Tax', 'Is Foreign', 'Active', 'Add Message',
                'Message', 'Notes', 'Created At', 'Updated At',
            ];

            return [
                'type' => 'download',
                'response' => Excel::download(new Export($suppliers, $columns, $headings), 'suppliers.xlsx'),
            ];
        } catch (\Exception $e) {
            return ['type' => 'exception', 'message' => $e->getMessage()];
        }
    }
}
