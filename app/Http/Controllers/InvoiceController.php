<?php

namespace App\Http\Controllers;

use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemUnitOfMeasurement;
use App\Services\InvoiceNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    protected InvoiceNumberService $invoiceNumberService;

    public function __construct(InvoiceNumberService $invoiceNumberService)
    {
        $this->invoiceNumberService = $invoiceNumberService;
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_invoices";

        $query = Invoice::with([
            'customer:id,first_name,last_name,display_name,company_name',
            'supplier:id,first_name,last_name,display_name,company_name',
            'currency:id,code,name',
            'salesman:id,name',
            'warehouse:id,name',
            'paymentTerm:id,name,nb_days',
        ]);

        // Apply filters
        if ($request->has('invoice_type')) {
            $query->where('invoice_type', $request->invoice_type);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        if ($request->has('year')) {
            $query->where('year', $request->year);
        }

        // Order by date descending (newest first)
        $invoices = $query->orderBy('date', 'desc')
            ->orderBy('sequence_number', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Invoices fetched successfully.',
            'data' => $invoices,
        ]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_invoice_{$invoice->id}";

        $cachedInvoice = app('cache')->store('database')->get($key);

        if (! $cachedInvoice) {
            $cachedInvoice = $invoice->load([
                'customer:id,first_name,last_name,display_name,company_name',
                'supplier:id,first_name,last_name,display_name,company_name',
                'currency:id,code,name',
                'salesman:id,name',
                'warehouse:id,name',
                'paymentTerm:id,name,nb_days',
                'items.item:id,code,name',
                'items.uom:id,name',
                'items.warehouse:id,name',
            ]);
            app('cache')->store('database')->forever($key, $cachedInvoice);
        }

        return response()->json([
            'status' => true,
            'message' => 'Invoice details fetched successfully.',
            'data' => $cachedInvoice,
        ]);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();
            $items = $data['items'] ?? [];
            unset($data['items']);

            // Generate invoice number
            $invoiceType = $data['invoice_type'];
            $year = isset($data['date']) ? (int) date('Y', strtotime($data['date'])) : null;
            $numberData = $this->invoiceNumberService->generateInvoiceNumberWithSequence($invoiceType, $year);

            $data['invoice_number'] = $numberData['invoice_number'];
            $data['year'] = $numberData['year'];
            $data['sequence_number'] = $numberData['sequence_number'];

            // Calculate due date if payment term is provided
            if (isset($data['payment_term_id']) && isset($data['date'])) {
                $paymentTerm = \App\Models\PaymentTerm::find($data['payment_term_id']);
                if ($paymentTerm && $paymentTerm->nb_days) {
                    $data['due_date'] = \Carbon\Carbon::parse($data['date'])->addDays($paymentTerm->nb_days)->toDateString();
                }
            }

            // Create invoice
            $nextId = $this->computeNextAvailableId(Invoice::class, 'id');
            $invoice = new Invoice($data);
            $invoice->id = $nextId;
            $invoice->save();

            // Create invoice items
            foreach ($items as $itemData) {
                $this->createInvoiceItem($invoice, $itemData);
            }

            // Recalculate totals
            $invoice->recalculateTotals();
            $invoice->refresh();

            DB::commit();

            // Clear cache
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_invoices");

            return response()->json([
                'status' => true,
                'message' => 'Invoice created successfully.',
                'data' => $invoice->load([
                    'customer:id,code,name,display_name',
                    'supplier:id,code,name,display_name',
                    'currency:id,code,name',
                    'salesman:id,code,name',
                    'warehouse:id,code,name',
                    'paymentTerm:id,code,name,nb_days',
                    'items.item:id,code,name',
                    'items.uom:id,name',
                    'items.warehouse:id,name',
                ]),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to create invoice: '.$e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();
            $items = $data['items'] ?? null;
            unset($data['items']);

            // Calculate due date if payment term or date changed
            if (isset($data['payment_term_id']) || isset($data['date'])) {
                $paymentTermId = $data['payment_term_id'] ?? $invoice->payment_term_id;
                $date = $data['date'] ?? $invoice->date;

                if ($paymentTermId && $date) {
                    $paymentTerm = \App\Models\PaymentTerm::find($paymentTermId);
                    if ($paymentTerm && $paymentTerm->nb_days) {
                        $data['due_date'] = \Carbon\Carbon::parse($date)->addDays($paymentTerm->nb_days)->toDateString();
                    }
                }
            }

            // Update invoice
            $invoice->update($data);

            // Update items if provided
            if ($items !== null) {
                // Delete existing items
                $invoice->items()->delete();

                // Create new items
                foreach ($items as $itemData) {
                    $this->createInvoiceItem($invoice, $itemData);
                }
            }

            // Recalculate totals
            $invoice->recalculateTotals();
            $invoice->refresh();

            DB::commit();

            // Clear cache
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_invoices");
            app('cache')->store('database')->forget("tenant_{$tenantId}_invoice_{$invoice->id}");

            return response()->json([
                'status' => true,
                'message' => 'Invoice updated successfully.',
                'data' => $invoice->load([
                    'customer:id,code,name,display_name',
                    'supplier:id,code,name,display_name',
                    'currency:id,code,name',
                    'salesman:id,code,name',
                    'warehouse:id,code,name',
                    'paymentTerm:id,code,name,nb_days',
                    'items.item:id,code,name',
                    'items.uom:id,name',
                    'items.warehouse:id,name',
                ]),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to update invoice: '.$e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        DB::beginTransaction();

        try {
            $invoice->delete();

            DB::commit();

            // Clear cache
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_invoices");
            app('cache')->store('database')->forget("tenant_{$tenantId}_invoice_{$invoice->id}");

            return response()->json([
                'status' => true,
                'message' => 'Invoice deleted successfully.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete invoice: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create an invoice item with proper calculations.
     */
    protected function createInvoiceItem(Invoice $invoice, array $itemData): InvoiceItem
    {
        $item = Item::findOrFail($itemData['item_id']);
        $uomId = $itemData['uom_id'];

        // Get item-UOM relationship to get barcode and conversion
        $itemUom = ItemUnitOfMeasurement::where('item_id', $item->id)
            ->where('unit_of_measurement_id', $uomId)
            ->first();

        if (! $itemUom) {
            throw new \Exception("UOM {$uomId} is not associated with item {$item->id}");
        }

        // Get barcode from item_unit_of_measurement.barcodes array
        $barcodes = $itemUom->barcodes ?? [];
        $barcode = ! empty($barcodes) ? $barcodes[0] : null; // Use first barcode if available

        // Calculate unit price: price / conversion
        $price = $itemData['price'];
        $conversion = $itemUom->conversion ?? 1;
        $unitPrice = InvoiceItem::calculateUnitPrice($price, $conversion);

        // Calculate item totals
        $quantity = $itemData['quantity'];
        $subtotal = $quantity * $price;
        $discountPercent = $itemData['discount_percent'] ?? 0;
        $discountAmount = $subtotal * ($discountPercent / 100);
        $afterDiscount = $subtotal - $discountAmount;
        $taxPercent = $itemData['tax_percent'] ?? 0;
        $taxAmount = $afterDiscount * ($taxPercent / 100);
        $total = $afterDiscount + $taxAmount;

        // Create invoice item
        $nextId = $this->computeNextAvailableId(InvoiceItem::class, 'id');
        $invoiceItem = new InvoiceItem([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'barcode' => $barcode ?? $itemData['barcode'] ?? null,
            'description' => $itemData['description'],
            'uom_id' => $uomId,
            'warehouse_id' => $itemData['warehouse_id'],
            'quantity' => $quantity,
            'price' => $price,
            'unit_price' => $unitPrice,
            'discount_percent' => $discountPercent,
            'tax_percent' => $taxPercent,
            'subtotal' => $subtotal,
            'total' => $total,
        ]);
        $invoiceItem->id = $nextId;
        $invoiceItem->save();

        return $invoiceItem;
    }
}
