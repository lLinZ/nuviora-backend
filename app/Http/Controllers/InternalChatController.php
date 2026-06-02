<?php

namespace App\Http\Controllers;

use App\Events\InternalMessageSent;
use App\Models\InternalConversation;
use App\Models\InternalMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Bandeja: hilos del usuario autenticado (o todos, si es Admin/Gerente),
     * ordenados por última actividad, con contraparte, último mensaje y no-leídos.
     */
    public function conversations(Request $request)
    {
        $user = $request->user();
        $isAdmin = $this->isAdmin($user);

        $query = InternalConversation::with([
            'vendedor.role',
            'agency.role',
            'agency.cities:id,name,agency_id',
            'lastMessage',
        ]);

        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('vendedor_id', $user->id)
                  ->orWhere('agency_id', $user->id);
            });
        }

        $conversations = $query->orderByDesc('last_message_at')->get();

        $data = $conversations->map(function (InternalConversation $c) use ($user, $isAdmin) {
            // Para admins (que sólo observan) el "counterpart" no aplica: mostramos ambos.
            $counterpart = null;
            if (!$isAdmin) {
                $counterpart = (int) $c->vendedor_id === (int) $user->id ? $c->agency : $c->vendedor;
            }

            $unread = $isAdmin ? 0 : $c->messages()
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')
                ->count();

            return [
                'id'              => $c->id,
                'vendedor'        => $c->vendedor ? ['id' => $c->vendedor->id, 'name' => $c->vendedor->chatDisplayName()] : null,
                'agency'          => $c->agency ? ['id' => $c->agency->id, 'name' => $c->agency->chatDisplayName()] : null,
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
     * Contrapartes disponibles para iniciar un hilo nuevo.
     * Vendedor -> Agencias; Agencia -> Vendedores.
     */
    public function contacts(Request $request)
    {
        $user = $request->user();
        $role = $this->roleOf($user);

        $targetRole = match ($role) {
            'Vendedor' => 'Agencia',
            'Agencia'  => 'Vendedor',
            default    => null, // Admins gestionan hilos existentes desde la bandeja
        };

        if (!$targetRole) {
            return response()->json([]);
        }

        $contacts = User::whereHas('role', fn ($q) => $q->where('description', $targetRole))
            ->with(['role', 'cities:id,name,agency_id'])
            ->orderBy('names')
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->chatDisplayName()]);

        return response()->json($contacts);
    }

    /**
     * Abre (o reutiliza) el hilo entre el usuario actual y una contraparte.
     */
    public function openConversation(Request $request)
    {
        $request->validate(['counterpart_id' => 'required|integer|exists:users,id']);

        $user = $request->user();
        $role = $this->roleOf($user);

        if (!in_array($role, ['Vendedor', 'Agencia'])) {
            return response()->json(['message' => 'Solo vendedoras y agencias pueden iniciar un chat.'], 403);
        }

        $counterpart = User::with('role')->findOrFail($request->counterpart_id);
        $counterRole = $counterpart->role->description ?? null;

        // Debe ser exactamente el par Vendedor<->Agencia.
        $pairOk = ($role === 'Vendedor' && $counterRole === 'Agencia')
            || ($role === 'Agencia' && $counterRole === 'Vendedor');

        if (!$pairOk) {
            return response()->json(['message' => 'Un chat interno solo es entre una vendedora y una agencia.'], 422);
        }

        $vendedorId = $role === 'Vendedor' ? $user->id : $counterpart->id;
        $agencyId   = $role === 'Agencia' ? $user->id : $counterpart->id;

        $conversation = InternalConversation::firstOrCreate(
            ['vendedor_id' => $vendedorId, 'agency_id' => $agencyId],
        );

        return response()->json([
            'id'          => $conversation->id,
            'vendedor_id' => $conversation->vendedor_id,
            'agency_id'   => $conversation->agency_id,
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Mensajes de un hilo. Marca como leídos los recibidos (si es participante).
     */
    public function messages(Request $request, InternalConversation $conversation)
    {
        $user = $request->user();
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
                'order_id'   => $m->order_id,
                'read_at'    => $m->read_at,
                'created_at' => $m->created_at,
                'mine'       => (int) $m->sender_id === (int) $user->id,
            ]);

        // Auto-marcar como leído lo recibido (solo participantes).
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
        $request->validate([
            'body'     => 'required|string|max:5000',
            'order_id' => 'nullable|integer|exists:orders,id',
        ]);

        $user = $request->user();
        if (!$this->canAccess($user, $conversation)) {
            return response()->json(['message' => 'No tienes acceso a esta conversación.'], 403);
        }

        $message = InternalMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $user->id,
            'body'            => $request->body,
            'order_id'        => $request->order_id,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        event(new InternalMessageSent($message));

        $user->loadMissing(['role', 'cities']);

        return response()->json([
            'id'         => $message->id,
            'sender_id'  => $message->sender_id,
            'sender'     => ['id' => $user->id, 'name' => $user->chatDisplayName()],
            'body'       => $message->body,
            'order_id'   => $message->order_id,
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

        if (!$conversation->hasParticipant($user->id)) {
            // Admins observan: su lectura no afecta los contadores de los participantes.
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
            ->whereHas('conversation', function ($q) use ($user) {
                $q->where('vendedor_id', $user->id)->orWhere('agency_id', $user->id);
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
