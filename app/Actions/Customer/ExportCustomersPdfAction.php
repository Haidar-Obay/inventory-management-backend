<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Exports\ExportPDF;
use App\Models\Customer;
use Illuminate\Http\Request;

class ExportCustomersPdfAction
{
    /**
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response|null
     */
    public function execute(Request $request, ExportPDF $pdfService)
    {
        $requestedColumns = $request->input('columns');
        $order = $request->input('order');
        // Size and layout options (orientation, fit, fontSize) are read by ExportPDF from request

        $baseColumns = [
            'id',
            'first_name',
            'last_name',
            'title',
            'middle_name',
            'company_name',
            'phone1',
            'phone2',
            'phone3',
            'email',
            'card_number',
            'file_number',
            'bar_code',
            'search_terms',
            'trade_id',
            'company_code_id',
            'customer_group_id',
            'business_type_id',
            'sales_channel_id',
            'distribution_channel_id',
            'media_channel_id',
            'indicator',
            'risk_category',
            'salesman_id',
            'collector_id',
            'supervisor_id',
            'manager_id',
            'payment_term_id',
            'payment_method_id',
            'allow_credit',
            'price_choice',
            'global_discount',
            'discount_class',
            'markup_percentage',
            'markdown_percentage',
            'taxable',
            'taxed_from_date',
            'taxed_till_date',
            'subjected_to_tax',
            'added_tax',
            'exempted',
            'exempted_from',
            'exemption_reference',
            'exempted_from_date',
            'exempted_till_date',
            'active',
            'black_listed',
            'one_time_account',
            'special_account',
            'pos_customer',
            'free_delivery_charge',
            'print_invoice_language',
            'send_invoice',
            'message',
            'contacts_id',
            'notes',
        ];

        $columns = is_array($requestedColumns) && ! empty($requestedColumns)
            ? array_values(array_intersect($requestedColumns, $baseColumns))
            : $baseColumns;

        $query = Customer::select($columns);

        if (is_array($order) && isset($order['by']) && in_array($order['by'], $columns, true)) {
            $direction = strtolower($order['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($order['by'], $direction);
        }

        $customers = $query->get();

        if ($customers->isEmpty()) {
            return;
        }

        $title = 'Customer Report';
        $headers = [
            'id' => 'Customer ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'title' => 'Title',
            'middle_name' => 'Middle Name',
            'company_name' => 'Company Name',
            'phone1' => 'Phone 1',
            'phone2' => 'Phone 2',
            'phone3' => 'Phone 3',
            'file_number' => 'File Number',
            'bar_code' => 'Bar Code',
            'search_terms' => 'Search Terms',
            'trade_id' => 'Trade ID',
            'company_code_id' => 'Company Code ID',
            'customer_group_id' => 'Customer Group ID',
            'business_type_id' => 'Business Type ID',
            'sales_channel_id' => 'Sales Channel ID',
            'distribution_channel_id' => 'Distribution Channel ID',
            'media_channel_id' => 'Media Channel ID',
            'indicator' => 'Indicator',
            'risk_category' => 'Risk Category',
            'salesman_id' => 'Salesman ID',
            'collector_id' => 'Collector ID',
            'supervisor_id' => 'Supervisor ID',
            'manager_id' => 'Manager ID',
            'payment_term_id' => 'Payment Term ID',
            'payment_method_id' => 'Payment Method ID',
            'allow_credit' => 'Allow Credit',
            'price_choice' => 'Price Choice',
            'global_discount' => 'Global Discount',
            'discount_class' => 'Discount Class',
            'markup_percentage' => 'Markup Percentage',
            'markdown_percentage' => 'Markdown Percentage',
            'taxable' => 'Taxable',
            'taxed_from_date' => 'Taxed From Date',
            'taxed_till_date' => 'Taxed Till Date',
            'subjected_to_tax' => 'Subjected To Tax',
            'added_tax' => 'Added Tax',
            'exempted' => 'Is Exempted',
            'exempted_from' => 'Exempted From',
            'exemption_reference' => 'Exemption Reference',
            'exempted_from_date' => 'Exempted From Date',
            'exempted_till_date' => 'Exempted Till Date',
            'active' => 'Active',
            'black_listed' => 'Black Listed',
            'one_time_account' => 'One Time Account',
            'special_account' => 'Special Account',
            'pos_customer' => 'POS Customer',
            'free_delivery_charge' => 'Free Delivery Charge',
            'print_invoice_language' => 'Print Invoice Language',
            'send_invoice' => 'Send Invoice',
            'message' => 'Invoice Message',
            'contacts_id' => 'Contacts ID',
            'notes' => 'Notes',
        ];

        $data = $customers->toArray();

        // Reorder headers to match selected columns
        if (! empty($requestedColumns)) {
            $headers = array_filter($headers, function ($key) use ($columns) {
                return in_array($key, $columns, true);
            }, ARRAY_FILTER_USE_KEY);
            $headers = array_replace(array_flip($columns), $headers);
            foreach ($headers as $key => $val) {
                if ($val === $key) {
                    unset($headers[$key]);
                    $headers[$key] = ucfirst(str_replace('_', ' ', $key));
                }
            }
        }

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('customers.pdf');
    }
}
