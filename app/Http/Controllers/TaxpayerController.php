<?php

namespace App\Http\Controllers;

use App\Actions\RegisterParticipant;
use App\Http\Requests\StoreTaxpayerRequest;
use App\Http\Resources\TaxpayerResource;
use App\Models\Taxpayer;
use Illuminate\Http\JsonResponse;

class TaxpayerController extends Controller
{
    public function store(StoreTaxpayerRequest $request, RegisterParticipant $register): JsonResponse
    {
        $taxpayer = Taxpayer::create($request->validated());

        // Announce the participant to the AMS so peers can discover us.
        $register->execute($taxpayer);

        return TaxpayerResource::make($taxpayer)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Taxpayer $taxpayer): TaxpayerResource
    {
        return TaxpayerResource::make($taxpayer);
    }
}
