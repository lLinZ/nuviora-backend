<?php

// app/Http/Controllers/SettingsController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Setting;

class SettingsController extends Controller
{
    protected function ensureManager(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }

    public function getBusinessHours()
    {
        $this->ensureManager();
        return response()->json([
            'status' => true,
            'data'   => [
                'open'  => Setting::get('business_open_at', '09:00'),
                'close' => Setting::get('business_close_at', '18:00'),
            ],
        ]);
    }

    public function updateBusinessHours(Request $request)
    {
        $this->ensureManager();

        $request->validate([
            'open'  => ['required', 'regex:/^\d{2}:\d{2}$/'],  // HH:mm
            'close' => ['required', 'regex:/^\d{2}:\d{2}$/'],  // HH:mm
        ]);

        Setting::set('business_open_at',  $request->open);
        Setting::set('business_close_at', $request->close);

        return response()->json([
            'status'  => true,
            'message' => 'Horario actualizado',
            'data'    => ['open' => $request->open, 'close' => $request->close],
        ]);
    }
}
