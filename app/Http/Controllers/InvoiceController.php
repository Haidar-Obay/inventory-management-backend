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
        $query = Invoice::with([
            'customer:id,first_name,last_name,display_name,company_name',
            'supplier:id,first_name,last_name,display_name,company_name',
            'currency:id,code,name',
            'salesman:id,name',
            'warehouse:id,name,code',
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
        $invoice->load([
            'customer:id,first_name,last_name,display_name,company_name',
            'supplier:id,first_name,last_name,display_name,company_name',
            'currency:id,code,name',
            'salesman:id,name',
            'warehouse:id,name,code',
            'paymentTerm:id,name,nb_days',
            'items.item:id,code,name',
            'items.uom:id,name',
            'items.warehouse:id,name,code',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Invoice details fetched successfully.',
            'data' => $invoice,
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

    /**
     * Get the last purchase invoice for a supplier
     * Returns full invoice data including header and items
     */
    public function getLastInvoice(Request $request): JsonResponse
    {
        $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
        ]);

        $supplierId = $request->supplier_id;

        // Get last purchase invoice for this supplier with all relationships
        $lastInvoice = Invoice::where('supplier_id', $supplierId)
            ->where('invoice_type', 'purchase')
            ->orderBy('date', 'desc')
            ->orderBy('sequence_number', 'desc')
            ->with([
                'items' => function ($query) {
                    $query->select([
                        'id',
                        'invoice_id',
                        'item_id',
                        'barcode',
                        'description',
                        'uom_id',
                        'warehouse_id',
                        'quantity',
                        'price',
                        'unit_price',
                        'discount_percent',
                        'tax_percent',
                    ]);
                },
                'currency:id,code,name',
                'paymentTerm:id,code,name,nb_days',
            ])
            ->first();

        if (! $lastInvoice) {
            return response()->json([
                'status' => false,
                'message' => 'No previous purchase invoice found for this supplier.',
                'data' => null,
            ], 404);
        }

        // Transform invoice to match frontend format
        $invoiceData = [
            'date' => $lastInvoice->date,
            'due_date' => $lastInvoice->due_date,
            'supplier_invoice_number' => $lastInvoice->supplier_invoice_number,
            'supplier_invoice_date' => $lastInvoice->supplier_invoice_date,
            'supplier_invoice_total' => $lastInvoice->supplier_invoice_total !== null ? (float) $lastInvoice->supplier_invoice_total : null,
            'currency_id' => $lastInvoice->currency_id,
            'payment_term_id' => $lastInvoice->payment_term_id,
            'exchange_rate' => $lastInvoice->exchange_rate,
            'notes' => $lastInvoice->notes,
            'discount_2_type' => $lastInvoice->discount_2_type,
            'discount_2_value' => (float) $lastInvoice->discount_2_value,
            'adjustment' => (float) $lastInvoice->adjustment,
            'items' => $lastInvoice->items->map(function ($item) {
                return [
                    'item_id' => $item->item_id,
                    'barcode' => $item->barcode,
                    'description' => $item->description,
                    'uom_id' => $item->uom_id,
                    'warehouse_id' => $item->warehouse_id,
                    'quantity' => (float) $item->quantity,
                    'price' => (float) $item->price,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => (float) $item->discount_percent,
                    'tax' => (float) $item->tax_percent,
                ];
            }),
        ];

        return response()->json([
            'status' => true,
            'message' => 'Last invoice retrieved successfully.',
            'data' => $invoiceData,
            'invoice_number' => $lastInvoice->invoice_number,
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
            if (isset($data['payment_term_id'])) {
                $paymentTerm = \App\Models\PaymentTerm::find($data['payment_term_id']);
                if ($paymentTerm && $paymentTerm->nb_days) {
                    // Purchase: supplier_invoice_date + nb_days, fallback to date + nb_days
                    $baseDate = null;
                    if (isset($data['invoice_type']) && $data['invoice_type'] === 'purchase' && ! empty($data['supplier_invoice_date'] ?? null)) {
                        $baseDate = $data['supplier_invoice_date'];
                    }
                    $baseDate = $baseDate ?? ($data['date'] ?? null);
                    if ($baseDate) {
                        $data['due_date'] = \Carbon\Carbon::parse($baseDate)->addDays($paymentTerm->nb_days)->toDateString();
                    }
                }
            }

            // Auto-fill exchange_rate if currency_id is set and exchange_rate is not provided
            if (isset($data['currency_id']) && (! isset($data['exchange_rate']) || $data['exchange_rate'] === null || $data['exchange_rate'] == 0)) {
                $invoice = new Invoice($data);
                $invoice->autoFillExchangeRate();
                $data['exchange_rate'] = $invoice->exchange_rate;
            }

            // Note: Frontend is responsible for sending customer_name, salesman_name, and customer_phone_number
            // These fields are denormalized snapshots at invoice creation time
            // Backend will use the values sent from frontend (no auto-population from relationships)

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

            // Calculate due date if payment term, date, or (purchase) supplier_invoice_date changed
            $paymentTermId = $data['payment_term_id'] ?? $invoice->payment_term_id;
            if ($paymentTermId) {
                $paymentTerm = \App\Models\PaymentTerm::find($paymentTermId);
                if ($paymentTerm && $paymentTerm->nb_days) {
                    $baseDate = null;
                    if ($invoice->invoice_type->value === 'purchase') {
                        $supplierInvoiceDate = $data['supplier_invoice_date'] ?? $invoice->supplier_invoice_date;
                        if ($supplierInvoiceDate) {
                            $baseDate = $supplierInvoiceDate;
                        }
                    }
                    $baseDate = $baseDate ?? ($data['date'] ?? $invoice->date);
                    if ($baseDate) {
                        $data['due_date'] = \Carbon\Carbon::parse($baseDate)->addDays($paymentTerm->nb_days)->toDateString();
                    }
                }
            }

            // Auto-fill exchange_rate if currency_id is set/changed and exchange_rate is not provided
            if (isset($data['currency_id']) && (! isset($data['exchange_rate']) || $data['exchange_rate'] === null || $data['exchange_rate'] == 0)) {
                $tempInvoice = new Invoice($data);
                $tempInvoice->currency_id = $data['currency_id'];
                $tempInvoice->autoFillExchangeRate();
                $data['exchange_rate'] = $tempInvoice->exchange_rate;
            }

            // Note: Frontend is responsible for sending customer_name, salesman_name, and customer_phone_number
            // These fields are denormalized snapshots at invoice creation time
            // Backend will use the values sent from frontend (no auto-population from relationships)

            // Update invoice
            $invoice->update($data);

            // Update items if provided
            if ($items !== null) {
                $existingIds = $invoice->items()->pluck('id')->toArray();
                $payloadIds = array_filter(array_map(fn ($i) => $i['id'] ?? null, $items));

                foreach ($existingIds as $id) {
                    if (! in_array($id, $payloadIds)) {
                        InvoiceItem::where('id', $id)->where('invoice_id', $invoice->id)->delete();
                    }
                }

                foreach ($items as $itemData) {
                    $id = $itemData['id'] ?? null;
                    if ($id && $invoice->items()->where('id', $id)->exists()) {
                        $this->updateInvoiceItem($invoice, $itemData);
                    } else {
                        $this->createInvoiceItem($invoice, $itemData);
                    }
                }
            }

            // Recalculate totals
            $invoice->recalculateTotals();
            $invoice->refresh();

            DB::commit();

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

        // Calculate unit display: conversion + base UOM name (e.g., "12pieces")
        $unit = null;
        if ($item->base_uom_id) {
            $baseUom = \App\Models\UnitOfMeasurement::find($item->base_uom_id);
            if ($baseUom) {
                $unit = $conversion.$baseUom->name;
            }
        }

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

        return InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'barcode' => $barcode ?? $itemData['barcode'] ?? null,
            'description' => $itemData['description'],
            'uom_id' => $uomId,
            'warehouse_id' => $itemData['warehouse_id'],
            'quantity' => $quantity,
            'price' => $price,
            'unit_price' => $unitPrice,
            'unit' => $unit,
            'discount_percent' => $discountPercent,
            'tax_percent' => $taxPercent,
            'subtotal' => $subtotal,
            'total' => $total,
        ]);
    }

    /**
     * Update an existing invoice item with proper calculations.
     */
    protected function updateInvoiceItem(Invoice $invoice, array $itemData): InvoiceItem
    {
        $invoiceItem = InvoiceItem::where('id', $itemData['id'])->where('invoice_id', $invoice->id)->firstOrFail();
        $item = Item::findOrFail($itemData['item_id']);
        $uomId = $itemData['uom_id'];

        $itemUom = ItemUnitOfMeasurement::where('item_id', $item->id)
            ->where('unit_of_measurement_id', $uomId)
            ->first();

        if (! $itemUom) {
            throw new \Exception("UOM {$uomId} is not associated with item {$item->id}");
        }

        $itemBarcode = \App\Models\ItemBarcode::where('item_unit_of_measurement_id', $itemUom->id)
            ->where('is_primary', true)
            ->first();
        $barcode = $itemBarcode ? $itemBarcode->barcode : null;

        $price = $itemData['price'];
        $conversion = $itemUom->conversion ?? 1;
        $unitPrice = InvoiceItem::calculateUnitPrice($price, $conversion);

        $unit = null;
        if ($item->base_uom_id) {
            $baseUom = \App\Models\UnitOfMeasurement::find($item->base_uom_id);
            if ($baseUom) {
                $unit = $conversion.$baseUom->name;
            }
        }

        $quantity = $itemData['quantity'];
        $subtotal = $quantity * $price;
        $discountPercent = $itemData['discount_percent'] ?? 0;
        $discountAmount = $subtotal * ($discountPercent / 100);
        $afterDiscount = $subtotal - $discountAmount;
        $taxPercent = $itemData['tax_percent'] ?? 0;
        $taxAmount = $afterDiscount * ($taxPercent / 100);
        $total = $afterDiscount + $taxAmount;

        $invoiceItem->update([
            'item_id' => $item->id,
            'barcode' => $barcode ?? $itemData['barcode'] ?? null,
            'description' => $itemData['description'],
            'uom_id' => $uomId,
            'warehouse_id' => $itemData['warehouse_id'],
            'quantity' => $quantity,
            'price' => $price,
            'unit_price' => $unitPrice,
            'unit' => $unit,
            'discount_percent' => $discountPercent,
            'tax_percent' => $taxPercent,
            'subtotal' => $subtotal,
            'total' => $total,
        ]);

        return $invoiceItem->fresh();
    }
}
