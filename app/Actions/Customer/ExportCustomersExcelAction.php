<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Exports\Export;
use App\Models\Customer;
use Maatwebsite\Excel\Facades\Excel;

class ExportCustomersExcelAction
{
    /**
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response|null
     */
    public function execute()
    {
        $customers = Customer::query();
        if ((clone $customers)->count() === 0) {
            return null;
        }
        $columns = [
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
        $headings = [
            'ID',
            'First Name',
            'Last Name',
            'Title',
            'Middle Name',
            'Company Name',
            'Phone 1',
            'Phone 2',
            'Phone 3',
            'File Number',
            'Bar Code',
            'Search Terms',
            'Trade ID',
            'Company Code ID',
            'Customer Group ID',
            'Business Type ID',
            'Sales Channel ID',
            'Distribution Channel ID',
            'Media Channel ID',
            'Indicator',
            'Risk Category',
            'Salesman ID',
            'Collector ID',
            'Supervisor ID',
            'Manager ID',
            'Payment Term ID',
            'Payment Method ID',
            'Allow Credit',
            'Accept Cheques',
            'Payment Day',
            'Track Payment',
            'Settlement Method',
            'Price Choice',
            'Global Discount',
            'Discount Class',
            'Markup Percentage',
            'Markdown Percentage',
            'Taxable',
            'Taxed From Date',
            'Taxed Till Date',
            'Subjected To Tax',
            'Added Tax',
            'Is Exempted',
            'Exempted From',
            'Exemption Reference',
            'Exempted From Date',
            'Exempted Till Date',
            'Active',
            'Black Listed',
            'One Time Account',
            'Special Account',
            'POS Customer',
            'Free Delivery Charge',
            'Print Invoice Language',
            'Send Invoice',
            'Add Message',
            'Invoice Message',
            'Contacts ID',
            'Notes',
        ];

        return Excel::download(new Export($customers, $columns, $headings), 'customers.xlsx');
    }
}
