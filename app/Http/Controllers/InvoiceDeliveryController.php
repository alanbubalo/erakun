<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitAs4Delivery;
use App\Enums\As4MessageDirection;
use App\Enums\As4MessageState;
use App\Http\Resources\InvoiceResource;
use App\Models\As4Message;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

class InvoiceDeliveryController extends Controller
{
    public function store(Invoice $invoice, SubmitAs4Delivery $action): JsonResponse
    {
        $latest = $invoice->latestAs4MessageFor(As4MessageDirection::Outbound);

        if ($latest instanceof As4Message && $latest->state === As4MessageState::Acknowledged) {
            return new JsonResponse([
                'message' => 'AS4 delivery is already acknowledged.',
            ], 409);
        }

        $action->execute($invoice);

        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'))
            ->response()
            ->setStatusCode(200);
    }
}
