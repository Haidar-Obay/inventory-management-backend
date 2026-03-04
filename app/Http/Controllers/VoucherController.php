<?php

namespace App\Http\Controllers;

use App\Http\Requests\Voucher\StoreVoucherRequest;
use App\Http\Requests\Voucher\UpdateVoucherRequest;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Services\VoucherNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoucherController extends Controller
{
    public function __construct(
        protected VoucherNumberService $voucherNumberService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Voucher::with([
            'customer:id,first_name,last_name,display_name,company_name',
            'supplier:id,first_name,last_name,display_name,company_name',
            'currency:id,code,name',
            'openingBalanceCurrency:id,code,name',
            'salesman:id,name',
            'collector:id,name',
        ]);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        $vouchers = $query->orderBy('date', 'desc')
            ->orderBy('sequence_number', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Vouchers fetched successfully.',
            'data' => $vouchers,
        ]);
    }

    public function show(Voucher $voucher): JsonResponse
    {
        $voucher->load([
            'customer:id,first_name,last_name,display_name,company_name',
            'supplier:id,first_name,last_name,display_name,company_name',
            'currency:id,code,name',
            'openingBalanceCurrency:id,code,name',
            'salesman:id,name',
            'collector:id,name',
            'lines.cashAccount:id,name,type',
            'lines.currency:id,code,name',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Voucher details fetched successfully.',
            'data' => $voucher,
        ]);
    }

    public function getNextVoucherNumber(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:receipt,payment',
            'year' => 'nullable|integer|min:2000|max:9999',
        ]);

        $type = $request->type;
        $year = $request->has('year') ? (int) $request->year : null;

        $numberData = $this->voucherNumberService->generateVoucherNumberWithSequence($type, $year);

        return response()->json([
            'status' => true,
            'message' => 'Next voucher number retrieved successfully.',
            'data' => $numberData,
        ]);
    }

    public function store(StoreVoucherRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $type = $data['type'];
            $year = isset($data['date']) ? (int) date('Y', strtotime($data['date'])) : null;

            $numberData = $this->voucherNumberService->generateVoucherNumberWithSequence(
                is_object($type) ? $type->value : $type,
                $year
            );

            $data['voucher_number'] = $numberData['voucher_number'];
            $data['year'] = $numberData['year'];
            $data['sequence_number'] = $numberData['sequence_number'];

            $nextId = $this->computeNextAvailableId(Voucher::class, 'id');
            $voucher = new Voucher($data);
            $voucher->id = $nextId;
            $voucher->save();

            foreach ($lines as $lineData) {
                $this->createVoucherLine($voucher, $lineData);
            }

            $voucher->recalculateTotals();
            $voucher->refresh();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Voucher created successfully.',
                'data' => $voucher->load([
                    'customer:id,first_name,last_name,display_name,company_name',
                    'supplier:id,first_name,last_name,display_name,company_name',
                    'currency:id,code,name',
                    'openingBalanceCurrency:id,code,name',
                    'salesman:id,name',
                    'collector:id,name',
                    'lines.cashAccount:id,name,type',
                    'lines.currency:id,code,name',
                ]),
                'voucher_number' => $numberData['voucher_number'],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to create voucher: '.$e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateVoucherRequest $request, Voucher $voucher): JsonResponse
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();
            $lines = $data['lines'] ?? null;
            unset($data['lines']);

            $voucher->update($data);

            if ($lines !== null) {
                $voucher->lines()->delete();
                foreach ($lines as $lineData) {
                    $this->createVoucherLine($voucher, $lineData);
                }
            }

            $voucher->recalculateTotals();
            $voucher->refresh();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Voucher updated successfully.',
                'data' => $voucher->load([
                    'customer:id,first_name,last_name,display_name,company_name',
                    'supplier:id,first_name,last_name,display_name,company_name',
                    'currency:id,code,name',
                    'openingBalanceCurrency:id,code,name',
                    'salesman:id,name',
                    'collector:id,name',
                    'lines.cashAccount:id,name,type',
                    'lines.currency:id,code,name',
                ]),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to update voucher: '.$e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Voucher $voucher): JsonResponse
    {
        DB::beginTransaction();

        try {
            $voucher->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Voucher deleted successfully.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete voucher: '.$e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:vouchers,id',
        ], [
            'ids.required' => 'The ids field is required.',
            'ids.array' => 'The ids must be an array.',
            'ids.min' => 'At least one ID must be provided.',
            'ids.*.required' => 'Each ID is required.',
            'ids.*.integer' => 'Each ID must be an integer.',
            'ids.*.exists' => 'One or more selected vouchers do not exist.',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $voucher = Voucher::find($id);

                if (! $voucher) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "Voucher ID: {$id}",
                        'reason' => 'Voucher not found.',
                    ];

                    continue;
                }

                $voucher->delete();
                $deleted++;
            } catch (\Illuminate\Database\QueryException $e) {
                $voucher = Voucher::find($id);
                $identifier = $voucher ? "Voucher #{$voucher->voucher_number}" : "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => 'Cannot delete voucher. It may be referenced by other records.',
                ];
            } catch (\Exception $e) {
                $voucher = Voucher::find($id);
                $identifier = $voucher ? "Voucher #{$voucher->voucher_number}" : "ID: {$id}";
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

    protected function createVoucherLine(Voucher $voucher, array $lineData): VoucherLine
    {
        $nextId = $this->computeNextAvailableId(VoucherLine::class, 'id');
        $line = new VoucherLine([
            'voucher_id' => $voucher->id,
            'cash_account_id' => $lineData['cash_account_id'],
            'currency_id' => $lineData['currency_id'],
            'exchange_rate' => $lineData['exchange_rate'] ?? 1.0000,
            'amount' => $lineData['amount'],
            'remark' => $lineData['remark'] ?? null,
        ]);
        $line->id = $nextId;
        $line->save();

        return $line;
    }
}
