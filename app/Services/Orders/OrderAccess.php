<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;

/**
 * Quién puede ver o tocar un pedido. Es la misma regla de las listas de pedidos (OrderController@index):
 *  - Admin, Gerente y Master: todos.
 *  - Vendedora: los suyos. La Líder, además, los de su grupo.
 *  - Agencia: los de su agencia, nunca los cancelados.
 *  - Repartidor: los suyos.
 *  - Otros roles internos: permitido (como hasta ahora).
 */
final class OrderAccess
{
    private const SUPER_ROLES = ['admin', 'manager', 'gerente', 'master'];

    public static function can(?User $user, Order $order): bool
    {
        if (!$user) {
            return false;
        }
        $role = $user->role ? strtolower(trim($user->role->description)) : '';

        if (in_array($role, self::SUPER_ROLES, true)) {
            return true;
        }

        if (str_contains($role, 'vende')) {
            if ((int) $order->agent_id === (int) $user->id) {
                return true;
            }
            $group = $order->agent_id ? $user->ledGroup() : null;

            return $group !== null && $group->openMembers()->where('user_id', $order->agent_id)->exists();
        }

        if ($role === 'agencia') {
            return (int) $order->agency_id === (int) $user->id
                && $order->status?->description !== 'Cancelado';
        }

        if ($role === 'repartidor') {
            return (int) $order->deliverer_id === (int) $user->id;
        }

        return true;
    }
}
