<?php

namespace App\Http\Controllers;

use App\Models\ReferBy;
use App\Http\Requests\ReferBy\StoreReferByRequest;
use App\Http\Requests\ReferBy\UpdateReferByRequest;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;

class ReferByController extends Controller
{
    public function index(): JsonResponse
    {
        $referBies = ReferBy::paginate(10);

        return response()->json([
            'status' => true,
            'message' => 'Refer Bies fetched successfully.',
            'data' => $referBies,
        ]);
    }

    public function store(StoreReferByRequest $request): JsonResponse
    {
        $referBy = ReferBy::create($request->validated());

        return response()->json([
            'message' => 'Refer By created successfully.',
            'data' => $referBy,
        ], 201);
    }

    public function show(ReferBy $referBy): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Refer By details fetched successfully.',
            'data' => $referBy,
        ]);
    }

    public function update(UpdateReferByRequest $request, ReferBy $referBy): JsonResponse
    {
        $referBy->update($request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Refer By updated successfully.',
            'data' => $referBy,
        ]);
    }

    public function destroy(ReferBy $referBy): JsonResponse
    {
        $referBy->delete();

        return response()->json([
            'status' => true,
            'message' => 'Refer By deleted successfully.',
        ]);
    }
    public function export()
    {
        $ReferBy = ReferBy::query();
        $collection =  $ReferBy->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No ReferBy found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];

        return Excel::download(new Export($ReferBy, $columns, $headings), 'ReferBy.xlsx');
    }
}
