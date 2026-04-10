<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappTemplate extends Model
{
    protected $fillable = [
        'name',
        'label',
        'body',
        'is_official',
        'meta_components',
    ];

    protected $casts = [
        'is_official'     => 'boolean',
        'meta_components' => 'array',
    ];

    /**
     * Render the template body replacing placeholders {{1}}, {{2}}, etc. with provided vars.
     */
    public function render(array $vars): string
    {
        $renderedBody = $this->body;
        // Also check if there's a header to prepend
        $headerText = '';
        if (!empty($this->meta_components)) {
            foreach ($this->meta_components as $component) {
                if (($component['type'] ?? '') === 'HEADER') {
                    $headerText = ($component['text'] ?? '') . "\n\n";
                }
                // Fallback: If body string is empty or doesn't look like it has placeholders, try getting it from META
                if (($component['type'] ?? '') === 'BODY' && (empty($renderedBody) || !str_contains($renderedBody, '{{'))) {
                    $renderedBody = $component['text'] ?? '';
                }
            }
        }

        $fullText = $headerText . $renderedBody;

        foreach ($vars as $index => $value) {
            $placeholder = '{{' . ($index + 1) . '}}';
            $fullText = str_replace($placeholder, (string)$value, $fullText);
        }

        // If rendering still fails to find variables and it's mostly empty, just show the template name
        if (trim($fullText) === '') {
            return "Plantilla: " . $this->name;
        }

        return $fullText;
    }
}
