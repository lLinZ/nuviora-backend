<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WhatsappTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'ubicacion_solicitud',
                'label' => 'Ubicación',
                'body' => 'Hola! Nos podrías enviar tu ubicación por favor para agendar la entrega?',
                'is_official' => false,
            ],
            [
                'name' => 'llamada_no_atendida',
                'label' => 'Llamada no atendida',
                'body' => 'Hola! Intentamos llamarte para confirmar tu entrega pero no pudimos contactarte. Por favor avísanos cuando estés disponible.',
                'is_official' => false,
            ],
            [
                'name' => 'confirmar_manana',
                'label' => 'Confirmar Mañana',
                'body' => 'Hola! Tu orden está agendada para mañana. ¿Estarás disponible para recibirla?',
                'is_official' => false,
            ],
            [
                'name' => 'transferencia_recordatorio',
                'label' => 'Transferencia',
                'body' => 'Hola! Recuerda que si vas a pagar por transferencia, debes enviarnos el comprobante por este medio. Gracias!',
                'is_official' => false,
            ],
        ];

        foreach ($templates as $tpl) {
            \App\Models\WhatsappTemplate::updateOrCreate(['name' => $tpl['name']], $tpl);
        }
    }
}
