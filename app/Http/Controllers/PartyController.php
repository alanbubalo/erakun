<?php

namespace App\Http\Controllers;

use App\Actions\RegisterParticipant;
use App\Http\Requests\StorePartyRequest;
use App\Http\Resources\PartyResource;
use App\Models\Party;
use Illuminate\Http\JsonResponse;

class PartyController extends Controller
{
    public function store(StorePartyRequest $request, RegisterParticipant $register): JsonResponse
    {
        $party = Party::create($request->validated());

        // Announce the participant to the AMS so peers can discover us.
        $register->execute($party);

        return PartyResource::make($party)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Party $party): PartyResource
    {
        return PartyResource::make($party);
    }
}
