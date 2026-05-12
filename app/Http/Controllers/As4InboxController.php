<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReceiveAs4Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class As4InboxController extends Controller
{
    private const string SOAP_CONTENT_TYPE = 'application/soap+xml; charset=utf-8';

    public function store(Request $request, ReceiveAs4Message $action): Response
    {
        $result = $action->execute($request->getContent());

        return response($result->responseXml, $result->httpStatus, [
            'Content-Type' => self::SOAP_CONTENT_TYPE,
        ]);
    }
}
