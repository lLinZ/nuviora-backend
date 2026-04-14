<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WhatsappTemplate;
use App\Services\WhatsAppService;

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
            'name'            => 'required|string|unique:whatsapp_templates,name',
            'label'           => 'required|string',
            'body'            => 'required|string',
            'language'        => 'nullable|string',
            'is_official'     => 'boolean',
            'meta_components' => 'nullable|array',
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
    public function update(Request $request, $id)
    {
        $template = WhatsappTemplate::findOrFail($id);

        $data = $request->validate([
            'name'            => 'string|unique:whatsapp_templates,name,' . $template->id,
            'label'           => 'string',
            'body'            => 'string',
            'language'        => 'nullable|string',
            'is_official'     => 'boolean',
            'meta_components' => 'nullable|array',
        ]);

        $template->update($data);

        return response()->json($template);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WhatsappTemplate $whatsappTemplate)
    {
        $whatsappTemplate->delete();
        return response()->json(['message' => 'Plantilla eliminada']);
    }

    /**
     * Fetch all templates directly from Meta (WhatsApp Business API).
     * Requires WHATSAPP_WABA_ID in .env
     */
    public function metaTemplates(WhatsAppService $whatsapp)
    {
        $result = $whatsapp->getMetaTemplates();

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        // Attach "already_imported" flag — check if name exists in local DB
        $localNames = WhatsappTemplate::pluck('name')->toArray();

        $templates = collect($result['data'] ?? [])->map(function ($tpl) use ($localNames) {
            $tpl['already_imported'] = in_array($tpl['name'], $localNames);

            // Extract body text from components for preview
            $body = '';
            foreach ($tpl['components'] ?? [] as $component) {
                if ($component['type'] === 'BODY') {
                    $body = $component['text'] ?? '';
                    break;
                }
            }
            $tpl['body_preview'] = $body;

            return $tpl;
        })->values();

        return response()->json([
            'data'  => $templates,
            'total' => $templates->count(),
        ]);
    }

    /**
     * Import a Meta template into the local DB.
     */
    public function importFromMeta(Request $request)
    {
        $data = $request->validate([
            'name'            => 'required|string',
            'label'           => 'required|string',
            'body'            => 'required|string',
            'language'        => 'nullable|string',
            'meta_components' => 'nullable|array',
        ]);

        $template = WhatsappTemplate::updateOrCreate(
            ['name' => $data['name']],
            [
                'label'           => $data['label'],
                'body'            => $data['body'],
                'language'        => $data['language'] ?? 'es',
                'is_official'     => true,
                'meta_components' => $data['meta_components'] ?? null,
            ]
        );

        return response()->json($template, 201);
    }
}
