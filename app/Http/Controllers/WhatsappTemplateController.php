<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\WhatsappTemplate;

class WhatsappTemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(WhatsappTemplate::orderBy('label')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|unique:whatsapp_templates,name',
            'label' => 'required|string',
            'body' => 'required|string',
            'is_official' => 'boolean',
        ]);

        $template = WhatsappTemplate::create($data);

        return response()->json($template, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(WhatsappTemplate $whatsappTemplate)
    {
        return response()->json($whatsappTemplate);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WhatsappTemplate $whatsappTemplate)
    {
        $data = $request->validate([
            'name' => 'string|unique:whatsapp_templates,name,' . $whatsappTemplate->id,
            'label' => 'string',
            'body' => 'string',
            'is_official' => 'boolean',
        ]);

        $whatsappTemplate->update($data);

        return response()->json($whatsappTemplate);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WhatsappTemplate $whatsappTemplate)
    {
        $whatsappTemplate->delete();
        return response()->json(['message' => 'Plantilla eliminada']);
    }
}
