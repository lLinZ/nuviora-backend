<?php

namespace App\Http\Controllers;

use App\Events\InternalMessageSent;
use App\Models\InternalConversation;
use App\Models\InternalMessage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class InternalChatController extends Controller
{
    private const ADMIN_ROLES = ['Admin', 'Gerente', 'Master'];

    private function roleOf(?User $user): ?string
    {
        if (!$user) return null;
        if (!$user->relationLoaded('role')) $user->load('role');
        return $user->role->description ?? null;
    }

    private function isAdmin(?User $user): bool
    {
        return in_array($this->roleOf($user), self::ADMIN_ROLES);
    }

    /** Datos del cliente de una orden (para el encabezado del hilo). */
    private function clientName(?Order $order): ?string
    {
        if (!$order || !$order->client) return null;
        return trim(($order->client->first_name ?? '') . ' ' . ($order->client->last_name ?? '')) ?: null;
    }

    /** La contraparte enmascarada de una orden, según quién pregunta. */
    private function counterpartOf(Order $order, User $user): ?User
    {
        if ((int) $order->agent_id === (int) $user->id) return $order->agency;
        if ((int) $order->agency_id === (int) $user->id) return $order->agent;
        return null;
    }

    private const INBOX_WITH = [
        'order:id,name,client_id,agent_id,agency_id',
        'order.client:id,first_name,last_name',
        'order.agent.role',
        'order.agency.role',
        'order.agency.cities:id,name,agency_id',
        'lastMessage',
    ];

    /**
     * Bandeja: hilos (por orden) del usuario, ordenados por última actividad.
     */
    public function conversations(Request $request)
    {
        $user = $request->user();
        $isAdmin = $this->isAdmin($user);

        $query = InternalConversation::with(self::INBOX_WITH);

        if (!$isAdmin) {
            $query->whereHas('order', function ($q) use ($user) {
                $q->where('agent_id', $user->id)->orWhere('agency_id', $user->id);
            });
        }

        $conversations = $query->orderByDesc('last_message_at')->get();

        $data = $conversations->map(function (InternalConversation $c) use ($user, $isAdmin) {
            $order = $c->order;
            $counterpart = (!$isAdmin && $order) ? $this->counterpartOf($order, $user) : null;

            $unread = $isAdmin ? 0 : $c->messages()
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')
                ->count();

            return [
                'id'              => $c->id,
                'order'           => $order ? ['id' => $order->id, 'name' => $order->name] : null,
                'client'          => $this->clientName($order),
                'vendedor'        => $order && $order->agent ? ['id' => $order->agent->id, 'name' => $order->agent->chatDisplayName()] : null,
                'agency'          => $order && $order->agency ? ['id' => $order->agency->id, 'name' => $order->agency->chatDisplayName()] : null,
                'counterpart'     => $counterpart ? ['id' => $counterpart->id, 'name' => $counterpart->chatDisplayName()] : null,
                'last_message'    => $c->lastMessage ? [
                    'body'       => $c->lastMessage->body,
                    'sender_id'  => $c->lastMessage->sender_id,
                    'created_at' => $c->lastMessage->created_at,
                ] : null,
                'last_message_at' => $c->last_message_at,
                'unread'          => $unread,
            ];
        });

        return response()->json($data);
    }

    /**
     * Buscador de órdenes asignadas al usuario, para iniciar/abrir un chat.
     * Vendedora -> sus órdenes con agencia asignada. Agencia -> sus órdenes.
     */
    public function searchOrders(Request $request)
    {
        $user = $request->user();
        $role = $this->roleOf($user);
        $term = trim((string) $request->query('q', ''));

        $query = Order::with([
            'client:id,first_name,last_name',
            'agent.role',
            'agency.role',
            'agency.cities:id,name,agency_id',
        ]);

        if ($role === 'Vendedor') {
            $query->where('agent_id', $user->id)->whereNotNull('agency_id');
        } elseif ($role === 'Agencia') {
            $query->where('agency_id', $user->id)->whereNotNull('agent_id');
        } elseif ($this->isAdmin($user)) {
            // Admin puede buscar cualquier orden con vendedora y agencia.
            $query->whereNotNull('agent_id')->whereNotNull('agency_id');
        } else {
            return response()->json([]);
        }

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('id', $term);
            });
        }

        $orders = $query->orderByDesc('id')->limit(25)->get();

        // Mapear conversaciones existentes en una sola consulta.
        $convByOrder = InternalConversation::whereIn('order_id', $orders->pluck('id'))
            ->pluck('id', 'order_id');

        $data = $orders->map(function (Order $order) use ($user, $convByOrder) {
            $counterpart = $this->counterpartOf($order, $user)
                ?? $order->agency // fallback admin: muestra la agencia
                ?? $order->agent;

            return [
                'order_id'        => $order->id,
                'order_name'      => $order->name,
                'client'          => $this->clientName($order),
                'counterpart'     => $counterpart ? ['id' => $counterpart->id, 'name' => $counterpart->chatDisplayName()] : null,
                'conversation_id' => $convByOrder[$order->id] ?? null,
            ];
        });

        return response()->json($data);
    }

    /**
     * Abre (o crea) el hilo de una orden. Lo usa el buscador y el botón en la orden.
     */
    public function openByOrder(Request $request, Order $order)
    {
        $user = $request->user();

        $isParticipant = (int) $order->agent_id === (int) $user->id
            || (int) $order->agency_id === (int) $user->id;

        if (!$isParticipant && !$this->isAdmin($user)) {
            return response()->json(['message' => 'No tienes acceso al chat de esta orden.'], 403);
        }

        $conversation = InternalConversation::firstOrCreate(['order_id' => $order->id]);

        $order->loadMissing(['client:id,first_name,last_name', 'agent.role', 'agency.role', 'agency.cities:id,name,agency_id']);
        $counterpart = $this->counterpartOf($order, $user) ?? $order->agency ?? $order->agent;

        return response()->json([
            'id'          => $conversation->id,
            'order'       => ['id' => $order->id, 'name' => $order->name],
            'client'      => $this->clientName($order),
            'counterpart' => $counterpart ? ['id' => $counterpart->id, 'name' => $counterpart->chatDisplayName()] : null,
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Mensajes de un hilo. Marca como leídos los recibidos (si es participante).
     */
    public function messages(Request $request, InternalConversation $conversation)
    {
        $user = $request->user();
        $conversation->loadMissing('order');

        if (!$this->canAccess($user, $conversation)) {
            return response()->json(['message' => 'No tienes acceso a esta conversación.'], 403);
        }

        $messages = $conversation->messages()
            ->with(['sender.role', 'sender.cities:id,name,agency_id'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (InternalMessage $m) => [
                'id'         => $m->id,
                'sender_id'  => $m->sender_id,
                'sender'     => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->chatDisplayName()] : null,
                'body'       => $m->body,
                'read_at'    => $m->read_at,
                'created_at' => $m->created_at,
                'mine'       => (int) $m->sender_id === (int) $user->id,
            ]);

        if ($conversation->hasParticipant($user->id)) {
            $conversation->messages()
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return response()->json($messages);
    }

    /**
     * Envía un mensaje al hilo. Admin/Gerente pueden intervenir.
     */
    public function store(Request $request, InternalConversation $conversation)
    {
        $request->validate(['body' => 'required|string|max:5000']);

        $user = $request->user();
        $conversation->loadMissing('order');

        if (!$this->canAccess($user, $conversation)) {
            return response()->json(['message' => 'No tienes acceso a esta conversación.'], 403);
        }

        $message = InternalMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $user->id,
            'body'            => $request->body,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        event(new InternalMessageSent($message));

        $user->loadMissing(['role', 'cities']);

        return response()->json([
            'id'         => $message->id,
            'sender_id'  => $message->sender_id,
            'sender'     => ['id' => $user->id, 'name' => $user->chatDisplayName()],
            'body'       => $message->body,
            'read_at'    => $message->read_at,
            'created_at' => $message->created_at,
            'mine'       => true,
        ], 201);
    }

    /**
     * Marca como leídos los mensajes recibidos del hilo.
     */
    public function markRead(Request $request, InternalConversation $conversation)
    {
        $user = $request->user();
        $conversation->loadMissing('order');

        if (!$conversation->hasParticipant($user->id)) {
            return response()->json(['status' => 'ignored']);
        }

        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Total de mensajes no leídos del usuario (para la campanita).
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();

        if ($this->isAdmin($user)) {
            return response()->json(['unread' => 0]);
        }

        $count = InternalMessage::whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->whereHas('conversation.order', function ($q) use ($user) {
                $q->where('agent_id', $user->id)->orWhere('agency_id', $user->id);
            })
            ->count();

        return response()->json(['unread' => $count]);
    }

    private function canAccess(?User $user, InternalConversation $conversation): bool
    {
        if ($this->isAdmin($user)) return true;
        return $conversation->hasParticipant($user->id);
    }
}
