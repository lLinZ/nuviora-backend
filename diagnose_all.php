<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use App\Models\Status;
use App\Models\Role;

echo "--- ROLES ---\n";
foreach(Role::all() as $r) {
    echo "ID: {$r->id} - Desc: '{$r->description}'\n";
}

echo "\n--- STATUSES ---\n";
foreach(Status::all() as $s) {
    echo "ID: {$s->id} - Desc: '{$s->description}'\n";
}
