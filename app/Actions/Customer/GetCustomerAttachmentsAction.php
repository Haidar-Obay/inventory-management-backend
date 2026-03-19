<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Http\Resources\Customer\CustomerAttachmentResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class GetCustomerAttachmentsAction
{
    public function execute(Customer $customer): JsonResponse
    {
        $attachments = $customer->attachments()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Attachments fetched successfully.',
            'data' => CustomerAttachmentResource::collection($attachments)->resolve(),
        ]);
    }
}
