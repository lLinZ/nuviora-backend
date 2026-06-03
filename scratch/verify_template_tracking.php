<?php
/**
 * Verifica la lógica de `template_tracking.should_send_next_template` de checkWindow,
 * en especial el fix anti-"enmascarado": un mensaje automático posterior a la respuesta
 * del cliente NO debe volver a habilitar el recordatorio.
 *
 * Uso:  php scratch/verify_template_tracking.php
 * Todo corre dentro de una transacción que se revierte (no ensucia la base).
 */
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\External\ExternalWhatsAppController;
use App\Models\Order;
use App\Models\Client;
use App\Models\Status;
use App\Models\Shop;
use App\Models\WhatsappMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "--- VERIFICACION should_send_next_template (anti-enmascarado) ---\n\n";

$pass = 0; $fail = 0;
function check($label, $expected, $actual) {
    global $pass, $fail;
    $ok = ($expected === $actual);
    $ok ? $pass++ : $fail++;
    $e = $expected ? 'true' : 'false';
    $a = $actual ? 'true' : 'false';
    echo ($ok ? "  ✅ PASS" : "  ❌ FAIL") . "  {$label}  (esperado={$e}, obtenido={$a})\n";
}

/** Llama a checkWindow y devuelve should_send_next_template */
function shouldSend($internalId, $shopId) {
    $controller = new ExternalWhatsAppController();
    $resp = $controller->checkWindow(new Request(['internal_id' => $internalId, 'shop_id' => $shopId]));
    $body = json_decode($resp->getContent(), true);
    return $body['template_tracking']['should_send_next_template'] ?? null;
}

/** Crea un mensaje con sent_at controlado */
function msg($clientId, $orderId, $body, $isFromClient, $type, Carbon $when) {
    return WhatsappMessage::create([
        'order_id'       => $orderId,
        'client_id'      => $clientId,
        'body'           => $body,
        'is_from_client' => $isFromClient,
        'message_type'   => $type,
        'status'         => $isFromClient ? 'delivered' : 'sent',
        'sent_at'        => $when,
    ]);
}

DB::beginTransaction();
try {
    $shop   = Shop::first() ?: Shop::create(['name' => 'Test', 'shopify_domain' => 't.myshopify.com']);
    $status = Status::where('description', 'Nuevo')->first()
           ?: Status::where('description', 'Pendiente')->first()
           ?: Status::create(['description' => 'Nuevo']);

    $T = Carbon::now()->subHours(5); // t0 base

    // ---- Escenario A: confirmación enviada, cliente NO responde -> debe reenviar (true)
    $cA = Client::create(['phone' => '+584120000001', 'first_name' => 'A', 'last_name' => 'Test']);
    $oA = Order::create(['shop_id' => $shop->id, 'client_id' => $cA->id, 'status_id' => $status->id,
                         'order_id' => 900000001, 'order_number' => 'A1', 'current_total_price' => 50, 'ves_price' => 2000]);
    msg($cA->id, $oA->id, 'Plantilla: confirmacion_pedido_nuevo_competencia', false, WhatsappMessage::TYPE_AUTOMATED, (clone $T));
    check('A) Confirmación sin respuesta', true, shouldSend($oA->id, $shop->id));

    // ---- Escenario B (EL BUG): confirmación -> cliente responde -> acuse automático posterior
    //      Antes del fix devolvía true (spam). Ahora debe ser false.
    $cB = Client::create(['phone' => '+584120000002', 'first_name' => 'B', 'last_name' => 'Test']);
    $oB = Order::create(['shop_id' => $shop->id, 'client_id' => $cB->id, 'status_id' => $status->id,
                         'order_id' => 900000002, 'order_number' => 'B1', 'current_total_price' => 50, 'ves_price' => 2000]);
    msg($cB->id, $oB->id, 'Plantilla: confirmacion_pedido_nuevo_competencia', false, WhatsappMessage::TYPE_AUTOMATED, (clone $T));
    msg($cB->id, $oB->id, 'Confirmar pedido', true,  WhatsappMessage::TYPE_INCOMING,  (clone $T)->addMinutes(2)); // cliente respondió
    msg($cB->id, $oB->id, 'Plantilla: gracias_pedido_confirmado', false, WhatsappMessage::TYPE_AUTOMATED, (clone $T)->addMinutes(3)); // acuse que "tapaba"
    check('B) Respuesta + acuse automático posterior (anti-enmascarado)', false, shouldSend($oB->id, $shop->id));

    // ---- Escenario C: confirmación -> recordatorio 1h enviado, sigue sin responder -> debe reenviar (true)
    $cC = Client::create(['phone' => '+584120000003', 'first_name' => 'C', 'last_name' => 'Test']);
    $oC = Order::create(['shop_id' => $shop->id, 'client_id' => $cC->id, 'status_id' => $status->id,
                         'order_id' => 900000003, 'order_number' => 'C1', 'current_total_price' => 50, 'ves_price' => 2000]);
    msg($cC->id, $oC->id, 'Plantilla: confirmacion_pedido_nuevo_competencia', false, WhatsappMessage::TYPE_AUTOMATED, (clone $T));
    msg($cC->id, $oC->id, 'Plantilla: no_respondio_confirmacion_en_1_hora_2', false, WhatsappMessage::TYPE_AUTOMATED, (clone $T)->addHour());
    check('C) Escalado 1h->2h sigue funcionando', true, shouldSend($oC->id, $shop->id));

    // ---- Escenario D: un agente intervino manualmente -> NO reenviar (false)
    $cD = Client::create(['phone' => '+584120000004', 'first_name' => 'D', 'last_name' => 'Test']);
    $oD = Order::create(['shop_id' => $shop->id, 'client_id' => $cD->id, 'status_id' => $status->id,
                         'order_id' => 900000004, 'order_number' => 'D1', 'current_total_price' => 50, 'ves_price' => 2000]);
    msg($cD->id, $oD->id, 'Plantilla: confirmacion_pedido_nuevo_competencia', false, WhatsappMessage::TYPE_AUTOMATED, (clone $T));
    msg($cD->id, $oD->id, 'Hola, te ayudo con tu pedido', false, WhatsappMessage::TYPE_AGENT, (clone $T)->addMinutes(10));
    check('D) Agente respondió manualmente', false, shouldSend($oD->id, $shop->id));

    echo "\n--- RESULTADO: {$pass} PASS / {$fail} FAIL ---\n";
} catch (\Throwable $e) {
    echo "Excepción: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    DB::rollBack();
    echo "Datos de prueba revertidos. Base limpia.\n";
}
