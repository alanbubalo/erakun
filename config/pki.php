<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | PKI disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk holding the test PKI material: the two root CAs, the
    | access point key pair, and per-party signing keys. Private keys live here
    | and never in the database or API responses. Rooted under storage/app/private,
    | which is gitignored, so `php artisan pki:generate` produces local-only keys.
    |
    */

    'disk' => env('PKI_DISK', 'pki'),

    /*
    |--------------------------------------------------------------------------
    | OpenSSL configuration
    |--------------------------------------------------------------------------
    |
    | The cnf pins the X.509 extensions (CA:TRUE vs CA:FALSE, keyUsage) so cert
    | minting is deterministic across machines rather than depending on the
    | system openssl.cnf.
    |
    */

    'openssl_config' => resource_path('pki/openssl.cnf'),

    /*
    |--------------------------------------------------------------------------
    | Certificate authorities (fictional trust anchors)
    |--------------------------------------------------------------------------
    |
    | Two layers, mirroring the real PEPPOL PKI hierarchy:
    |   - fina   → issues party document-signing certs (stands in for FINA)
    |   - peppol → issues the access point transport cert (stands in for OpenPEPPOL)
    |
    */

    'ca' => [
        'fina' => ['cn' => env('PKI_FINA_CN', 'eRakun Test FINA CA')],
        'peppol' => ['cn' => env('PKI_PEPPOL_CN', 'eRakun Test PEPPOL CA')],
    ],

    'access_point' => [
        'cn' => env('PKI_AP_CN', 'eRakun Test Access Point'),
    ],

    // Validity windows (days).
    'ca_days' => (int) env('PKI_CA_DAYS', 3650),
    'leaf_days' => (int) env('PKI_LEAF_DAYS', 730),

];
