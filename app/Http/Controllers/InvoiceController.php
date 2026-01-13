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

    /**
     * Get the next available invoice number for preview (does not reserve it)
     */
    public function getNextInvoiceNumber(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_type' => 'required|in:purchase,sale',
            'year' => 'nullable|integer|min:2000|max:9999',
        ]);

        $invoiceType = $request->invoice_type;
        $year = $request->has('year') ? (int) $request->year : null;

        // Generate next invoice number (read-only, no lock since it's just preview)
        $numberData = $this->invoiceNumberService->generateInvoiceNumberWithSequence($invoiceType, $year);

        return response()->json([
            'status' => true,
            'message' => 'Next invoice number retrieved successfully.',
            'data' => $numberData,
        ]);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();
            $items = $data['items'] ?? [];
            unset($data['items']);

            // Handle invoice number: use provided one if available and valid, otherwise generate new
            $invoiceType = $data['invoice_type'];
            $year = isset($data['date']) ? (int) date('Y', strtotime($data['date'])) : null;

            $finalInvoiceNumber = null;
            $finalYear = null;
            $finalSequence = null;

            // If invoice_number is provided, check if it's still available
            if (isset($data['invoice_number']) && ! empty($data['invoice_number'])) {
                $providedNumber = $data['invoice_number'];

                // Check if the provided number is still available
                $exists = Invoice::where('invoice_number', $providedNumber)->exists();

                if (! $exists) {
                    // Number is available, use it
                    // Extract year and sequence from the number (format: "26-0")
                    $parts = explode('-', $providedNumber);
                    if (count($parts) === 2) {
                        $yearLastTwo = (int) $parts[0];
                        $sequence = (int) $parts[1];
                        // Reconstruct full year (assume 2000s)
                        $fullYear = 2000 + $yearLastTwo;

                        $finalInvoiceNumber = $providedNumber;
                        $finalYear = $fullYear;
                        $finalSequence = $sequence;
                    } else {
                        // Invalid format, generate new
                        $numberData = $this->invoiceNumberService->generateInvoiceNumberWithSequence($invoiceType, $year);
                        $finalInvoiceNumber = $numberData['invoice_number'];
                        $finalYear = $numberData['year'];
                        $finalSequence = $numberData['sequence_number'];
                    }
                } else {
                    // Number is taken, generate a new one
                    $numberData = $this->invoiceNumberService->generateInvoiceNumberWithSequence($invoiceType, $year);
                    $finalInvoiceNumber = $numberData['invoice_number'];
                    $finalYear = $numberData['year'];
                    $finalSequence = $numberData['sequence_number'];
                }
            } else {
                // No number provided, generate new
                $numberData = $this->invoiceNumberService->generateInvoiceNumberWithSequence($invoiceType, $year);
                $finalInvoiceNumber = $numberData['invoice_number'];
                $finalYear = $numberData['year'];
                $finalSequence = $numberData['sequence_number'];
            }

            $data['invoice_number'] = $finalInvoiceNumber;
            $data['year'] = $finalYear;
            $data['sequence_number'] = $finalSequence;

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
                    'customer:id,first_name,last_name,display_name,company_name',
                    'supplier:id,first_name,last_name,display_name,company_name',
                    'currency:id,code,name',
                    'salesman:id,name',
                    'warehouse:id,name',
                    'paymentTerm:id,name,nb_days',
                    'items.item:id,code,name',
                    'items.uom:id,name',
                    'items.warehouse:id,name',
                ]),
                'invoice_number' => $finalInvoiceNumber, // Include final invoice number in response
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
                    'customer:id,first_name,last_name,display_name,company_name',
                    'supplier:id,first_name,last_name,display_name,company_name',
                    'currency:id,code,name',
                    'salesman:id,name',
                    'warehouse:id,name',
                    'paymentTerm:id,name,nb_days',
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

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:invoices,id',
        ], [
            'ids.required' => 'The ids field is required.',
            'ids.array' => 'The ids must be an array.',
            'ids.min' => 'At least one ID must be provided.',
            'ids.*.required' => 'Each ID is required.',
            'ids.*.integer' => 'Each ID must be an integer.',
            'ids.*.exists' => 'One or more selected invoices do not exist.',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $invoice = Invoice::find($id);

                if (! $invoice) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "Invoice ID: {$id}",
                        'reason' => 'Invoice not found.',
                    ];

                    continue;
                }

                // Delete the invoice (items will be deleted via cascade)
                $invoice->delete();
                $deleted++;

                // Clear cache
                app('cache')->store('database')->forget("tenant_{$tenantId}_invoice_{$id}");

            } catch (\Illuminate\Database\QueryException $e) {
                $invoice = Invoice::withTrashed()->find($id);
                $identifier = $invoice ? ($invoice->invoice_number ? "Invoice #{$invoice->invoice_number}" : "Invoice ID: {$id}") : "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => 'Cannot delete invoice. It may be referenced by other records.',
                ];
            } catch (\Exception $e) {
                $invoice = Invoice::withTrashed()->find($id);
                $identifier = $invoice ? ($invoice->invoice_number ? "Invoice #{$invoice->invoice_number}" : "Invoice ID: {$id}") : "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // Clear invoices cache
        app('cache')->store('database')->forget("tenant_{$tenantId}_invoices");

        return response()->json([
            'status' => true,
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
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

        // Get barcode from dedicated item_barcodes table
        $itemBarcode = \App\Models\ItemBarcode::where('item_unit_of_measurement_id', $itemUom->id)
            ->where('is_primary', true)
            ->first();
        $barcode = $itemBarcode ? $itemBarcode->barcode : null;

        // Calculate unit price: price / conversion
        $price = $itemData['price'];
        $conversion = $itemUom->conversion ?? 1;
        $unitPrice = InvoiceItem::calculateUnitPrice($price, $conversion);

        // Calculate item totals
        // Order: Subtotal → Discount → Tax → Total
        // Discount is calculated on subtotal, then tax is calculated on (subtotal - discount)
        $quantity = $itemData['quantity'];
        $subtotal = $quantity * $price;

        // Step 1: Apply discount on subtotal (before tax)
        $discountPercent = $itemData['discount_percent'] ?? 0;
        $discountAmount = $subtotal * ($discountPercent / 100);

        // Step 2: Calculate amount after discount
        $afterDiscount = $subtotal - $discountAmount;

        // Step 3: Calculate tax on the amount after discount
        $taxPercent = $itemData['tax_percent'] ?? 0;
        $taxAmount = $afterDiscount * ($taxPercent / 100);

        // Step 4: Final total = afterDiscount + tax
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
