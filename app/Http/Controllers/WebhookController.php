<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Webhook;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Webhook::with('status')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'url' => 'required|url',
            'status_id' => 'nullable|integer',
        ]);

        try {
            $webhook = new Webhook();
            $webhook->name = $request->name;
            $webhook->url = $request->url;
            $webhook->status_id = $request->status_id === 'all' ? null : $request->status_id;
            $webhook->event_type = $request->event_type ?? 'order.status_changed';
            $webhook->is_active = true;
            $webhook->save();

            return response()->json([
                'status' => true,
                'message' => 'Webhook creado exitosamente',
                'data' => $webhook->load('status')
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating webhook: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Error al crear: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
