<?php

namespace App\Jobs;

use App\Models\TableTemplate;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

class CreateDefaultTableTemplates
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function handle()
    {
        if (! Schema::hasTable('table_templates')) {
            // Table does not exist yet, skip job
            return;
        }

        $tables = [
            'customers' => [
                'id', 'title', 'first_name', 'middle_name', 'last_name', 'display_name', 'company_name', 'phone1', 'phone2', 'phone3', 'file_number', 'bar_code', 'search_terms', 'trade_id', 'company_code_id', 'customer_group_id', 'business_type_id', 'sales_channel_id', 'distribution_channel_id', 'media_channel_id', 'indicator', 'risk_category', 'salesman_id', 'collector_id', 'supervisor_id', 'manager_id', 'payment_term_id', 'payment_method_id', 'allow_credit', 'accept_cheque', 'payment_day', 'track_payment', 'settlement_method', 'pricing_choice', 'discount_by_item', 'global_discount', 'discount_class', 'markup_percentage', 'markdown_percentage', 'taxable', 'tax_rate', 'tax_number', 'is_exempted', 'exemption_from', 'exemption_reference', 'exempted_from_date', 'exempted_till_date', 'active', 'black_listed', 'one_time_account', 'special_account', 'pos_customer', 'free_delivery_charge', 'print_invoice_language', 'send_invoice', 'add_message', 'invoice_message', 'contacts_id', 'notes', 'created_at', 'updated_at',
            ],
            'customerGroups' => [
                'id', 'code', 'name', 'active', 'created_at', 'updated_at',
            ],
            'salesmen' => [
                'id', 'code', 'name', 'address', 'phone1', 'phone2', 'email', 'is_manager', 'is_supervisor', 'is_collector', 'fix_commission', 'commission_by_item', 'active', 'created_at', 'updated_at',
            ],
            'items' => [
                'id', 'code', 'name', 'price', 'created_at', 'updated_at',
            ],
            'categories' => [
                'id', 'code', 'name', 'subcategory_of', 'active', 'created_at', 'updated_at',
            ],
            'brands' => [
                'id', 'code', 'name', 'sub_brand_of', 'active', 'created_at', 'updated_at',
            ],
            'productLines' => [
                'id', 'code', 'name', 'active', 'created_at', 'updated_at',
            ],
            'projects' => [
                'id', 'name', 'start_date', 'end_date', 'expected_date', 'customer_id', 'created_at', 'updated_at',
            ],
            'costCenters' => [
                'id', 'code', 'name', 'active', 'sub_cost_center_of', 'created_at', 'updated_at',
            ],
            'departments' => [
                'id', 'code', 'name', 'sub_department_of', 'active', 'created_at', 'updated_at',
            ],
            'trades' => [
                'id', 'code', 'name', 'active', 'created_at', 'updated_at',
            ],
            'companyCodes' => [
                'id', 'code', 'name', 'created_at', 'updated_at',
            ],
            'jobs' => [
                'id', 'code', 'description', 'project_id', 'start_date', 'expected_date', 'end_date', 'created_at', 'updated_at',
            ],
            'countries' => [
                'id', 'name', 'created_at', 'updated_at',
            ],
            'cities' => [
                'id', 'name', 'created_at', 'updated_at',
            ],
            'districts' => [
                'id', 'name', 'created_at', 'updated_at',
            ],
            'zones' => [
                'id', 'name', 'created_at', 'updated_at',
            ],
            'paymentMethods' => [
                'id', 'code', 'name', 'is_credit_card', 'is_online_payment', 'active', 'created_at', 'updated_at',
            ],
            'paymentTerms' => [
                'id', 'code', 'name', 'nb_days', 'active', 'created_at', 'updated_at',
            ],
            'salesChannels' => [
                'id', 'code', 'name', 'sub_sales_of', 'created_at', 'updated_at',
            ],
            'distributionChannels' => [
                'id', 'code', 'name', 'sub_distribution_of', 'created_at', 'updated_at',
            ],
            'mediaChannels' => [
                'id', 'code', 'name', 'sub_media_of', 'created_at', 'updated_at',
            ],
            'mediaTypes' => [
                'id', 'name', 'sub_media_type_of', 'created_at', 'updated_at',
            ],
            'businessTypes' => [
                'id', 'code', 'name', 'created_at', 'updated_at',
            ],
            'serviceCategories' => [
                'id', 'name', 'department_id', 'description', 'created_at', 'updated_at',
            ],
            'services' => [
                'id', 'name', 'service_category_id', 'normal_price', 'cost_price', 'active', 'created_at', 'updated_at',
            ],
            'customerMasterLists' => [
                'id', 'date', 'name', 'valid_from', 'valid_till', 'created_at', 'updated_at',
            ],
            'suppliers' => [
                'id', 'title', 'first_name', 'middle_name', 'last_name', 'display_name', 'company_name', 'phone1', 'phone2', 'phone3', 'file_number', 'bar_code', 'search_terms', 'trade_id', 'supplier_group_id', 'business_type_id', 'indicator', 'active', 'notes', 'created_at', 'updated_at',
            ],
            'supplierGroups' => [
                'id', 'code', 'name', 'active', 'created_at', 'updated_at',
            ],
            'associations' => [
                'id', 'name', 'phone1', 'phone2', 'email', 'website', 'markup_value', 'markup_type', 'markdown_value', 'markdown_type', 'allowed_to_pay_for_guests', 'active', 'created_at', 'updated_at',
            ],
            'referrers' => [
                'id', 'name', 'address', 'phone1', 'phone2', 'email', 'active', 'commission_percent', 'created_at', 'updated_at',
            ],

        ];

        $timestampFields = ['created_at', 'updated_at', 'deleted_at'];

        foreach ($tables as $tableName => $columns) {
            // Create visible_columns array with all columns set to true
            $visibleColumns = array_fill_keys($columns, true);

            // Set timestamp fields to false
            $visibleColumns['created_at'] = false;
            $visibleColumns['updated_at'] = false;

            $template = [
                'name' => 'Default',
                'table_name' => $tableName,
                'visible_columns' => $visibleColumns,
                'column_widths' => array_fill_keys($columns, 110),
                'column_order' => array_values($columns),
                'headerColor' => null,
                'showHeaderSeparator' => true,
                'showHeaderColSeparator' => true,
                'showBodyColSeparator' => true,
                'is_default' => true,
                'headerFontSize' => '14px',
                'headerFontStyle' => 'normal',
                'headerFontColor' => null,
            ];
            TableTemplate::updateOrCreate(
                [
                    'table_name' => $tableName,
                    'name' => 'Default',
                ],
                $template
            );
        }
    }
}
