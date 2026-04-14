<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaxpayerRequest;
use App\Http\Resources\TaxpayerResource;
use App\Models\Taxpayer;
use Illuminate\Http\JsonResponse;

class TaxpayerController extends Controller
{
    public function store(StoreTaxpayerRequest $request): JsonResponse
    {
        $taxpayer = Taxpayer::create($request->validated());

        return TaxpayerResource::make($taxpayer)
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $oib): TaxpayerResource
    {
        $taxpayer = Taxpayer::where('oib', $oib)->firstOrFail();

        return TaxpayerResource::make($taxpayer);
    }
}
