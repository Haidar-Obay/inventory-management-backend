<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Http\Resources\Customer\CustomerAttachmentResource;
use App\Models\Customer;

class GetCustomerAttachmentsAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(Customer $customer): array
    {
        $attachments = $customer->attachments()->orderBy('created_at', 'desc')->get();

        return CustomerAttachmentResource::collection($attachments)->resolve();
    }
}
