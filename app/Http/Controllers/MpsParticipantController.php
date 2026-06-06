<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Taxpayer;
use Illuminate\Http\JsonResponse;

/**
 * MPS (Metadata service) — publishes how to reach the participants we serve.
 * Derived from the `taxpayers` table plus config; no backing table of its own.
 */
class MpsParticipantController extends Controller
{
    public function show(string $oib): JsonResponse
    {
        abort_unless(Taxpayer::query()->where('oib', $oib)->exists(), 404);

        return new JsonResponse([
            'oib' => $oib,
            'as4_endpoint' => (string) config('services.mps.as4_endpoint'),
        ]);
    }
}
