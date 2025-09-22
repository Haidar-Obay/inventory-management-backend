<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceCategoryRequest;
use App\Http\Requests\UpdateServiceCategoryRequest;
use App\Models\ServiceCategory;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

class ServiceCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ServiceCategory::with('service:id,name')->paginate());
    }

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        // Ensure one record per service
        $existing = ServiceCategory::where('service_id', (int) $data['service_id'])->first();
        if ($existing) {
            $existing->update(['categories' => $data['categories']]);
            return response()->json($existing->refresh(), 200);
        }
        $row = ServiceCategory::create($data);
        return response()->json($row, 201);
    }

    public function show(ServiceCategory $serviceCategory): JsonResponse
    {
        return response()->json($serviceCategory->load('service:id,name'));
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $data = $request->validated();
        $serviceCategory->update(['categories' => $data['categories']]);
        return response()->json($serviceCategory);
    }

    public function destroy(ServiceCategory $serviceCategory): JsonResponse
    {
        $serviceCategory->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function getByService(Service $service): JsonResponse
    {
        $serviceCategory = ServiceCategory::where('service_id', $service->id)->first();

        if (!$serviceCategory) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No categories found for this service',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $serviceCategory,
        ]);
    }
}


