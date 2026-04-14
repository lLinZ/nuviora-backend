<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('orders', function ($user) {
    return in_array($user->role?->description, ['Admin', 'Gerente', 'Master']);
});

Broadcast::channel('orders.agency.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id && $user->role?->description === 'Agencia';
});

Broadcast::channel('orders.agent.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id && $user->role?->description === 'Vendedor';
});

Broadcast::channel('orders.deliverer.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id && $user->role?->description === 'Repartidor';
});

Broadcast::channel('orders.{id}', function ($user, $id) {
    if (!$user) return false;
    
    // Admins always get access
    if (in_array($user->role?->description, ['Admin', 'Gerente', 'Master'])) {
        return true;
    }
    
    // Verify Order ownership
    $order = \App\Models\Order::find($id);
    if (!$order) return false;

    return (int) $order->agent_id === (int) $user->id || 
           (int) $order->agency_id === (int) $user->id || 
           (int) $order->deliverer_id === (int) $user->id;
});
Broadcast::channel('whatsapp', function ($user) {
    return in_array($user->role?->description, ['Admin', 'Gerente', 'Master', 'SuperAdmin', 'Vendedor']);
});
