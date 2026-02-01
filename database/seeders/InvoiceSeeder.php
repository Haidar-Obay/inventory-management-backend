<?php

namespace Database\Seeders;

use App\Enums\InvoiceType;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemUnitOfMeasurement;
use App\Models\PaymentTerm;
use App\Models\Salesman;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\InvoiceNumberService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class InvoiceSeeder extends Seeder
{
    protected InvoiceNumberService $invoiceNumberService;

    public function __construct()
    {
        $this->invoiceNumberService = new InvoiceNumberService;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure all required data exists
        $this->ensureRequiredData();

        // Get existing data
        $customers = Customer::take(5)->get();
        $suppliers = Supplier::take(3)->get();
        $items = Item::take(10)->get();
        $warehouses = Warehouse::take(2)->get();
        $currencies = Currency::take(2)->get();
        $paymentTerms = PaymentTerm::take(3)->get();
        $salesmen = Salesman::take(2)->get();

        if ($customers->isEmpty() || $items->isEmpty() || $warehouses->isEmpty() || $currencies->isEmpty()) {
            $this->command->warn('Required data (customers, items, warehouses, currencies) not found. Please run other seeders first.');

            return;
        }

        $currentYear = (int) date('Y');

        // Create Sales Invoices
        $this->createSalesInvoices($customers, $items, $warehouses, $currencies, $paymentTerms, $salesmen, $currentYear);

        // Create Purchase Invoices
        $this->createPurchaseInvoices($suppliers, $items, $warehouses, $currencies, $paymentTerms, $currentYear);

        $this->command->info('Invoices seeded successfully!');
    }

    /**
     * Ensure all required data exists
     */
    protected function ensureRequiredData(): void
    {
        // Ensure at least one customer exists
        if (Customer::count() === 0) {
            $this->command->info('Creating sample customer...');
            $this->createSampleCustomer();
        }

        // Ensure at least one supplier exists
        if (Supplier::count() === 0) {
            $this->command->info('Creating sample supplier...');
            $this->createSampleSupplier();
        }

        // Ensure at least one item exists
        if (Item::count() === 0) {
            $this->command->info('Creating sample items...');
            $this->createSampleItems();
        }

        // Ensure at least one warehouse exists
        if (Warehouse::count() === 0) {
            $this->command->info('Creating sample warehouse...');
            $this->createSampleWarehouse();
        }

        // Ensure at least one currency exists
        if (Currency::count() === 0) {
            $this->command->info('Creating sample currency...');
            Currency::firstOrCreate(
                ['code' => 'USD'],
                [
                    'name' => 'US Dollar',
                    'iso_code' => 'USD',
                    'rate' => 1.0000,
                ]
            );
        }

        // Ensure payment terms exist
        if (PaymentTerm::count() === 0) {
            $this->command->info('Creating sample payment terms...');
            PaymentTerm::firstOrCreate(
                ['code' => 'NET30'],
                [
                    'name' => 'Net 30 Days',
                    'nb_days' => 30,
                    'active' => true,
                ]
            );
        }
        
        // Ensure payment term with 0 days exists (for immediate payment)
        PaymentTerm::firstOrCreate(
            ['code' => 'IMMEDIATE'],
            [
                'name' => 'Immediate Payment',
                'nb_days' => 0,
                'active' => true,
            ]
        );

        // Ensure salesmen exist
        if (Salesman::count() === 0) {
            $this->command->info('Creating sample salesman...');
            Salesman::firstOrCreate(
                ['code' => 'SALES001'],
                [
                    'name' => 'John Salesman',
                    'active' => true,
                ]
            );
        }
    }

    /**
     * Create sample customer
     */
    protected function createSampleCustomer(): void
    {
        $customerGroup = \App\Models\CustomerGroup::firstOrCreate(
            ['name' => 'Default Group'],
            ['code' => 'DEFAULT', 'active' => true]
        );

        $paymentTerm = PaymentTerm::firstOrCreate(
            ['code' => 'NET30'],
            [
                'name' => 'Net 30 Days',
                'nb_days' => 30,
                'active' => true,
            ]
        );

        $currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'US Dollar',
                'iso_code' => 'USD',
                'rate' => 1.0000,
            ]
        );

        $customer = Customer::create([
            'code' => 'CUST001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'display_name' => 'John Doe',
            'phone1' => '1234567890',
            'phone2' => '0987654321',
            'customer_group_id' => $customerGroup->id,
            'payment_term_id' => $paymentTerm->id,
            'currency_id' => $currency->id,
            'active' => true,
        ]);

        // Create opening balance for currency (explicit id to avoid sequence clash after other seeders)
        \App\Models\CustomerOpeningBalance::create([
            'id' => \App\Models\CustomerOpeningBalance::getNextAvailableId(),
            'customer_id' => $customer->id,
            'currency_id' => $currency->id,
            'opening_amount' => 0,
            'opening_date' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * Create sample supplier
     */
    protected function createSampleSupplier(): void
    {
        $supplierGroup = \App\Models\SupplierGroup::firstOrCreate(
            ['name' => 'Default Group'],
            ['code' => 'DEFAULT', 'active' => true]
        );

        $paymentTerm = PaymentTerm::firstOrCreate(
            ['code' => 'NET30'],
            [
                'name' => 'Net 30 Days',
                'nb_days' => 30,
                'active' => true,
            ]
        );

        $currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'US Dollar',
                'iso_code' => 'USD',
                'rate' => 1.0000,
            ]
        );

        $supplier = Supplier::create([
            'code' => 'SUPP001',
            'first_name' => 'Global',
            'last_name' => 'Supplies',
            'display_name' => 'Global Supplies',
            'phone1' => '1112223333',
            'supplier_group_id' => $supplierGroup->id,
            'payment_term_id' => $paymentTerm->id,
            'currency_id' => $currency->id,
            'active' => true,
        ]);

        // Create opening balance for currency
        \App\Models\SupplierOpeningBalance::create([
            'supplier_id' => $supplier->id,
            'currency_id' => $currency->id,
            'opening_amount' => 0,
            'opening_date' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * Create sample items
     */
    protected function createSampleItems(): void
    {
        $trade = \App\Models\Trade::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Trade', 'active' => true]
        );

        $companyCode = \App\Models\CompanyCode::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Company Code']
        );

        $taxGroup = \App\Models\TaxGroup::firstOrCreate(
            ['code' => 'TG001'],
            ['name' => 'Standard Tax Group', 'value' => 10.00, 'active' => true]
        );

        $baseUom = \App\Models\UnitOfMeasurement::first();
        if (! $baseUom) {
            $baseUom = \App\Models\UnitOfMeasurement::create([
                'name' => 'Piece',
                'unit_group_id' => \App\Models\UnitGroup::firstOrCreate(['name' => 'Default'])->id,
            ]);
        }

        $items = [
            ['code' => 'ITEM001', 'name' => 'Sample Item 1'],
            ['code' => 'ITEM002', 'name' => 'Sample Item 2'],
            ['code' => 'ITEM003', 'name' => 'Sample Item 3'],
        ];

        foreach ($items as $itemData) {
            $item = Item::firstOrCreate(
                ['code' => $itemData['code']],
                [
                    'name' => $itemData['name'],
                    'type' => \App\Enums\ItemType::INVENTORY->value,
                    'trade_id' => $trade->id,
                    'company_code_id' => $companyCode->id,
                    'tax_group_id' => $taxGroup->id,
                    'base_uom_id' => $baseUom->id,
                    'active' => true,
                ]
            );

            // Attach UOM
            $itemUom = ItemUnitOfMeasurement::firstOrCreate(
                [
                    'item_id' => $item->id,
                    'unit_of_measurement_id' => $baseUom->id,
                ],
                [
                    'operation' => 'multiply',
                    'conversion' => 1,
                ]
            );

            // Insert barcode into dedicated table
            if ($itemUom->wasRecentlyCreated) {
                \App\Models\ItemBarcode::create([
                    'item_id' => $item->id,
                    'item_unit_of_measurement_id' => $itemUom->id,
                    'barcode' => 'BARCODE'.str_pad($item->id, 6, '0', STR_PAD_LEFT),
                    'is_primary' => true,
                ]);
            }
        }
    }

    /**
     * Create sample warehouse
     */
    protected function createSampleWarehouse(): void
    {
        Warehouse::firstOrCreate(
            ['code' => 'WH001'],
            [
                'name' => 'Main Warehouse',
                'active' => true,
            ]
        );
    }

    /**
     * Create sales invoices
     */
    protected function createSalesInvoices($customers, $items, $warehouses, $currencies, $paymentTerms, $salesmen, $year): void
    {
        $invoiceCount = 5;

        for ($i = 0; $i < $invoiceCount; $i++) {
            $customer = $customers->random();
            $warehouse = $warehouses->random();
            $currency = $currencies->random();
            $paymentTerm = $paymentTerms->random();
            $salesman = $salesmen->random();

            // Get customer's available currencies from opening balances
            $customerCurrencies = $customer->openingBalances()->pluck('currency_id')->toArray();
            if (empty($customerCurrencies)) {
                // Create opening balance if it doesn't exist (explicit id to avoid sequence clash)
                $existing = \App\Models\CustomerOpeningBalance::where('customer_id', $customer->id)
                    ->where('currency_id', $currency->id)->first();
                if (! $existing) {
                    \App\Models\CustomerOpeningBalance::create([
                        'id' => \App\Models\CustomerOpeningBalance::getNextAvailableId(),
                        'customer_id' => $customer->id,
                        'currency_id' => $currency->id,
                        'opening_amount' => 0,
                        'opening_date' => now()->toDateString(),
                        'is_active' => true,
                    ]);
                }
                $currencyId = $currency->id;
            } else {
                $currencyId = in_array($currency->id, $customerCurrencies) ? $currency->id : $customerCurrencies[0];
            }

            // Generate invoice number
            $numberData = $this->invoiceNumberService->generateInvoiceNumberWithSequence(InvoiceType::SALE->value, $year);

            // Calculate due date
            $date = now()->subDays(rand(0, 30))->toDateString();
            $dueDate = \Carbon\Carbon::parse($date)->addDays($paymentTerm->nb_days)->toDateString();

            // Get customer billing/shipping info
            $billingPhones = array_filter([$customer->phone1, $customer->phone2, $customer->phone3]);
            $shippingPhones = $billingPhones;

            $billingAddresses = [];
            $shippingAddresses = [];

            // Get customer addresses
            $customerBillingAddresses = $customer->billingAddresses()->get();
            foreach ($customerBillingAddresses as $addr) {
                if ($addr && $addr->address_line1) {
                    $billingAddresses[] = $addr->address_line1;
                }
            }

            $customerShippingAddresses = $customer->shippingAddresses()->get();
            foreach ($customerShippingAddresses as $addr) {
                if ($addr && $addr->address_line1) {
                    $shippingAddresses[] = $addr->address_line1;
                }
            }

            // If no addresses, use default
            if (empty($billingAddresses)) {
                $billingAddresses = ['Default Billing Address'];
            }
            if (empty($shippingAddresses)) {
                $shippingAddresses = ['Default Shipping Address'];
            }

            // Create invoice
            $invoice = Invoice::create([
                'invoice_number' => $numberData['invoice_number'],
                'invoice_type' => InvoiceType::SALE->value,
                'year' => $numberData['year'],
                'sequence_number' => $numberData['sequence_number'],
                'date' => $date,
                'due_date' => $dueDate,
                'customer_id' => $customer->id,
                'supplier_id' => null,
                'currency_id' => $currencyId,
                'salesman_id' => $salesman->id,
                'warehouse_id' => $warehouse->id,
                'payment_term_id' => $paymentTerm->id,
                'ref_2' => 'REF-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'discount_2_type' => Arr::random(['percent', 'amount', null]),
                'discount_2_value' => Arr::random([null, 5, 10, 50, 100]),
                'notes' => "Sales invoice notes for invoice #{$numberData['invoice_number']}",
                'billing_to_phones' => array_values($billingPhones),
                'billing_to_addresses' => $billingAddresses,
                'shipping_to_phones' => array_values($shippingPhones),
                'shipping_to_addresses' => $shippingAddresses,
                'subtotal' => 0,
                'taxes' => 0,
                'net_total' => 0,
                'adjustment' => Arr::random([0, -10, 10, -50, 50]),
                'net_to_pay' => 0,
            ]);

            // Create invoice items
            $selectedItems = $items->random(rand(2, 5));
            foreach ($selectedItems as $item) {
                $this->createInvoiceItem($invoice, $item, $warehouse);
            }

            // Recalculate totals
            $invoice->recalculateTotals();
        }
    }

    /**
     * Create purchase invoices
     */
    protected function createPurchaseInvoices($suppliers, $items, $warehouses, $currencies, $paymentTerms, $year): void
    {
        $invoiceCount = 3;

        for ($i = 0; $i < $invoiceCount; $i++) {
            $supplier = $suppliers->random();
            $warehouse = $warehouses->random();
            $currency = $currencies->random();
            $paymentTerm = $paymentTerms->random();

            // Get supplier's available currencies from opening balances
            $supplierCurrencies = $supplier->openingBalances()->pluck('currency_id')->toArray();
            if (empty($supplierCurrencies)) {
                // Create opening balance if it doesn't exist
                \App\Models\SupplierOpeningBalance::firstOrCreate(
                    [
                        'supplier_id' => $supplier->id,
                        'currency_id' => $currency->id,
                    ],
                    [
                        'opening_amount' => 0,
                        'opening_date' => now()->toDateString(),
                        'is_active' => true,
                    ]
                );
                $currencyId = $currency->id;
            } else {
                $currencyId = in_array($currency->id, $supplierCurrencies) ? $currency->id : $supplierCurrencies[0];
            }

            // Generate invoice number
            $numberData = $this->invoiceNumberService->generateInvoiceNumberWithSequence(InvoiceType::PURCHASE->value, $year);

            // Calculate due date
            $date = now()->subDays(rand(0, 30))->toDateString();
            $dueDate = \Carbon\Carbon::parse($date)->addDays($paymentTerm->nb_days)->toDateString();

            // Get supplier billing/shipping info
            $billingPhones = array_filter([$supplier->phone1, $supplier->phone2, $supplier->phone3]);
            $shippingPhones = $billingPhones;

            $billingAddresses = [];
            $shippingAddresses = [];

            // Get supplier addresses
            $supplierBillingAddresses = $supplier->billingAddresses()->get();
            foreach ($supplierBillingAddresses as $addr) {
                if ($addr && $addr->address_line1) {
                    $billingAddresses[] = $addr->address_line1;
                }
            }

            $supplierShippingAddresses = $supplier->shippingAddresses()->get();
            foreach ($supplierShippingAddresses as $addr) {
                if ($addr && $addr->address_line1) {
                    $shippingAddresses[] = $addr->address_line1;
                }
            }

            // If no addresses, use default
            if (empty($billingAddresses)) {
                $billingAddresses = ['Default Billing Address'];
            }
            if (empty($shippingAddresses)) {
                $shippingAddresses = ['Default Shipping Address'];
            }

            // Create invoice
            $invoice = Invoice::create([
                'invoice_number' => $numberData['invoice_number'],
                'invoice_type' => InvoiceType::PURCHASE->value,
                'year' => $numberData['year'],
                'sequence_number' => $numberData['sequence_number'],
                'date' => $date,
                'due_date' => $dueDate,
                'customer_id' => null,
                'supplier_id' => $supplier->id,
                'currency_id' => $currencyId,
                'salesman_id' => null,
                'warehouse_id' => $warehouse->id,
                'payment_term_id' => $paymentTerm->id,
                'ref_2' => 'PURCHASE-REF-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'discount_2_type' => Arr::random(['percent', 'amount', null]),
                'discount_2_value' => Arr::random([null, 5, 10, 50, 100]),
                'notes' => "Purchase invoice notes for invoice #{$numberData['invoice_number']}",
                'billing_to_phones' => array_values($billingPhones),
                'billing_to_addresses' => $billingAddresses,
                'shipping_to_phones' => array_values($shippingPhones),
                'shipping_to_addresses' => $shippingAddresses,
                'subtotal' => 0,
                'taxes' => 0,
                'net_total' => 0,
                'adjustment' => Arr::random([0, -10, 10, -50, 50]),
                'net_to_pay' => 0,
            ]);

            // Create invoice items
            $selectedItems = $items->random(rand(2, 5));
            foreach ($selectedItems as $item) {
                $this->createInvoiceItem($invoice, $item, $warehouse);
            }

            // Recalculate totals
            $invoice->recalculateTotals();
        }
    }

    /**
     * Create an invoice item
     */
    protected function createInvoiceItem(Invoice $invoice, Item $item, Warehouse $warehouse): void
    {
        // Get item's UOMs
        $itemUoms = $item->unitOfMeasurements()->get();
        if ($itemUoms->isEmpty()) {
            // If no UOMs, use base UOM
            $uom = $item->baseUom;
            $conversion = 1;
            $barcodes = [];
        } else {
            $itemUom = $itemUoms->random();
            $uom = $itemUom;
            // Get pivot data
            $pivot = ItemUnitOfMeasurement::where('item_id', $item->id)
                ->where('unit_of_measurement_id', $uom->id)
                ->first();
            $conversion = $pivot->conversion ?? 1;
            // Get barcodes from dedicated table
            $barcodes = $pivot ? $pivot->barcodes->pluck('barcode')->toArray() : [];
        }

        if (! $uom) {
            return; // Skip if no UOM
        }

        // Get barcode
        $barcode = ! empty($barcodes) ? $barcodes[0] : null;

        // Generate random pricing
        $price = rand(10, 1000);
        $quantity = rand(1, 50);
        $discountPercent = Arr::random([0, 5, 10, 15]);
        $taxPercent = Arr::random([0, 5, 10, 15]);

        // Calculate unit price
        $unitPrice = InvoiceItem::calculateUnitPrice($price, $conversion);

        // Calculate totals
        $subtotal = $quantity * $price;
        $discountAmount = $subtotal * ($discountPercent / 100);
        $afterDiscount = $subtotal - $discountAmount;
        $taxAmount = $afterDiscount * ($taxPercent / 100);
        $total = $afterDiscount + $taxAmount;

        // Get item description
        $description = $item->sales_description ?? $item->name ?? 'Item Description';

        // Create invoice item
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'barcode' => $barcode,
            'description' => $description,
            'uom_id' => $uom->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'price' => $price,
            'unit_price' => $unitPrice,
            'discount_percent' => $discountPercent,
            'tax_percent' => $taxPercent,
            'subtotal' => $subtotal,
            'total' => $total,
        ]);
    }
}
