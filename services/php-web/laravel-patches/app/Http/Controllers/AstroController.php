<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AstroController extends Controller
{
    public function events(Request $r)
    {
        $lat  = (float) $r->query('lat', 55.7558);
        $lon  = (float) $r->query('lon', 37.6176);
        $days = max(1, min(366, (int) $r->query('days', 7)));

        $from = now('UTC')->toDateString();
        $to   = now('UTC')->addDays($days - 1)->toDateString();

        $appId  = env('ASTRO_APP_ID', '');
        $secret = env('ASTRO_APP_SECRET', '');
        if ($appId === '' || $secret === '') {
            return response()->json(['error' => 'Missing ASTRO_APP_ID/ASTRO_APP_SECRET'], 500);
        }

        $auth = base64_encode($appId . ':' . $secret);
        $url = 'https://api.astronomyapi.com/api/v2/bodies/events/moon?' . http_build_query([
            'latitude'  => $lat,
            'longitude' => $lon,
            'elevation' => 0,
            'from_date'      => $from,
            'to_date'        => $to,
            'time' => '12:00:00'
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/json',
                'User-Agent: monolith-iss/1.0'
            ],
            CURLOPT_TIMEOUT        => 25,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            return response()->json(['error' => $err ?: ("HTTP " . $code), 'code' => $code, 'raw' => $raw, 'app_id' => $appId, 'secret'=> $secret, 'base' => base64_encode($appId . ':' . $secret)], 403);
        }
        $json = json_decode($raw, true);
        return response()->json($json ?? ['raw' => $raw]);
    }
}