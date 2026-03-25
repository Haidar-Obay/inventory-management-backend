<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Exports\ExportPDF;
use App\Models\Supplier;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportSuppliersPdfAction
{
    /**
     * @return BinaryFileResponse|Response|null
     */
    public function execute(ExportPDF $pdfService): BinaryFileResponse|Response|null
    {
        $suppliers = Supplier::with([
            'supplierGroup:id,name',
            'trade:id,name',
            'businessType:id,name',
            'currency:id,code,name',
            'openingBalances.paymentTerm:id,code',
            'openingBalances.paymentMethod:id,code',
        ])->get();

        if ($suppliers->isEmpty()) {
            return null;
        }

        $data = $suppliers->map(function ($supplier) {
            return [
                'ID' => $supplier->id,
                'Title' => $supplier->title,
                'First Name' => $supplier->first_name,
                'Middle Name' => $supplier->middle_name,
                'Last Name' => $supplier->last_name,
                'Display Name' => $supplier->display_name,
                'Company Name' => $supplier->company_name,
                'Phone 1' => $supplier->phone1,
                'Phone 2' => $supplier->phone2,
                'Phone 3' => $supplier->phone3,
                'File Number' => $supplier->file_number,
                'Bar Code' => $supplier->bar_code,
                'Search Terms' => is_array($supplier->search_terms) ? implode(', ', $supplier->search_terms) : '',
                'Trade' => $supplier->trade ? $supplier->trade->name : '',
                'Supplier Group' => $supplier->supplierGroup ? $supplier->supplierGroup->name : '',
                'Business Type' => $supplier->businessType ? $supplier->businessType->name : '',
                'Indicator' => $supplier->indicator,
                'Invoicing Mode' => $supplier->invoicing_mode,
                'Currency' => $supplier->currency ? $supplier->currency->code : '',
                'Opening Amount' => $supplier->opening_amount,
                'Opening Date' => $supplier->opening_date,
                'Payment Term' => $supplier->openingBalances->first()?->paymentTerm?->code ?? '',
                'Payment Method' => $supplier->openingBalances->first()?->paymentMethod?->code ?? '',
                'Credit Limit' => $supplier->credit_limit,
                'Accept Cheques' => $supplier->openingBalances()->active()->where('accept_cheques', true)->exists() ? 'Yes' : 'No',
                'Max Cheques' => $supplier->max_cheques,
                'Taxable' => $supplier->taxable ? 'Yes' : 'No',
                'Taxed From Date' => $supplier->taxed_from_date,
                'Taxed Till Date' => $supplier->taxed_till_date,
                'Subjected to Tax' => $supplier->subjected_to_tax ? 'Yes' : 'No',
                'Added Tax' => $supplier->added_tax,
                'Is Foreign' => $supplier->is_foreign ? 'Yes' : 'No',
                'Active' => $supplier->active ? 'Yes' : 'No',
                'Message' => $supplier->message,
                'Notes' => $supplier->notes,
                'Created At' => $supplier->created_at,
                'Updated At' => $supplier->updated_at,
            ];
        });

        $title = 'Suppliers List';
        $headers = [
            'ID' => 'ID',
            'Title' => 'Title',
            'First Name' => 'First Name',
            'Middle Name' => 'Middle Name',
            'Last Name' => 'Last Name',
            'Display Name' => 'Display Name',
            'Company Name' => 'Company Name',
            'Phone 1' => 'Phone 1',
            'Phone 2' => 'Phone 2',
            'Phone 3' => 'Phone 3',
            'File Number' => 'File Number',
            'Barcode' => 'Barcode',
            'Search Terms' => 'Search Terms',
            'Trade' => 'Trade',
            'Supplier Group' => 'Supplier Group',
            'Business Type' => 'Business Type',
            'Indicator' => 'Indicator',
            'Invoicing Mode' => 'Invoicing Mode',
            'Currency' => 'Currency',
            'Opening Amount' => 'Opening Amount',
            'Opening Date' => 'Opening Date',
            'Payment Term' => 'Payment Term',
            'Payment Method' => 'Payment Method',
            'Credit Limit' => 'Credit Limit',
            'Payment Day' => 'Payment Day',
            'Track Payment' => 'Track Payment',
            'Settlement Method' => 'Settlement Method',
            'Accept Cheques' => 'Accept Cheques',
            'Max Cheques' => 'Max Cheques',
            'Taxable' => 'Taxable',
            'Taxed From Date' => 'Taxed From Date',
            'Taxed Till Date' => 'Taxed Till Date',
            'Subjected to Tax' => 'Subjected to Tax',
            'Added Tax' => 'Added Tax',
            'Is Foreign' => 'Is Foreign',
            'Active' => 'Active',
            'Add Message' => 'Add Message',
            'Message' => 'Message',
            'Notes' => 'Notes',
            'Created At' => 'Created At',
            'Updated At' => 'Updated At',
        ];

        $pdf = $pdfService->generatePdf($title, $headers, $data->toArray());

        return $pdf->download('suppliers.pdf');
    }
}
