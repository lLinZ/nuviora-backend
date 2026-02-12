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
