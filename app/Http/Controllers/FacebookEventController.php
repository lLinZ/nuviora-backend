<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class FacebookEventController extends Controller
{
    public function sendEvent(Request $request)
    {
        $pixelId = Config::get('services.facebook.pixel_id');
        $accessToken = Config::get('services.facebook.access_token');

        $payload = [
            'data' => [
                [
                    'event_name'       => $request->input('event_name'),
                    'event_time'       => $request->input('event_time', now()->timestamp),
                    'event_source_url' => $request->input('event_source_url'),
                    'event_id'         => $request->input('event_id'),
                    'action_source'    => 'website',
                    'user_data'        => $request->input('user_data', []),
                    'custom_data'      => $request->input('custom_data', []),
                ],
            ],
            'access_token' => $accessToken,
        ];

        $response = Http::post("https://graph.facebook.com/v17.0/{$pixelId}/events", $payload);

        return response()->json($response->json(), $response->status());
    }
}
