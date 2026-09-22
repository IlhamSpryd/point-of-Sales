<?php

// [OMEGA-NODE4] Signing server untuk QZ Tray. Tanpa ini, setiap qz.websocket.connect()
// memunculkan popup "Untrusted certificate" di kios -- silent print jadi tidak silent. | 2026-09-22

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class QzTraySigningController extends Controller
{
    public function certificate(): Response
    {
        $path = config('pos.qz_cert_path');

        abort_unless($path && File::exists($path), 404, 'Sertifikat QZ Tray belum digenerate.');

        return response(File::get($path), 200, ['Content-Type' => 'text/plain']);
    }

    public function sign(Request $request): Response
    {
        $request->validate(['request' => ['required', 'string']]);

        $keyPath = config('pos.qz_private_key_path');
        abort_unless($keyPath && File::exists($keyPath), 500, 'Private key QZ Tray tidak ditemukan di server.');

        $privateKey = openssl_pkey_get_private('file://'.$keyPath);
        abort_if($privateKey === false, 500, 'Private key QZ Tray tidak valid.');

        openssl_sign($request->input('request'), $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature), 200, ['Content-Type' => 'text/plain']);
    }
}
