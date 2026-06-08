<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StoreCertificate;
use App\Enums\CertificateStatus;
use App\Http\Requests\StoreCertificateRequest;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CertificateController extends Controller
{
    public function index(Party $party): AnonymousResourceCollection
    {
        return CertificateResource::collection($party->certificates()->latest()->get());
    }

    public function store(StoreCertificateRequest $request, Party $party, StoreCertificate $action): JsonResponse
    {
        $material = $request->hasFile('certificate')
            ? (string) file_get_contents((string) $request->file('certificate')?->getRealPath())
            : (string) $request->string('certificate_pem');

        $certificate = $action->execute($party, $material, $request->input('password'));

        return CertificateResource::make($certificate)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Party $party, Certificate $certificate): CertificateResource
    {
        return CertificateResource::make($certificate);
    }

    public function destroy(Party $party, Certificate $certificate): Response
    {
        $certificate->update(['status' => CertificateStatus::Revoked]);

        return response()->noContent();
    }
}
