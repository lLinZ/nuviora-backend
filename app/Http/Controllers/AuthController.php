<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Warehouse;
use App\Models\WarehouseType;

class AuthController extends Controller
{
    // POST /users
    public function store(Request $request)
    {
        $request->validate([
            'names'    => 'required|string|max:100',
            'surnames' => 'nullable|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role_id'  => 'required|exists:roles,id',
            'phone'    => 'nullable|string|max:100',
            'address'  => 'nullable|string|max:255',
            'delivery_cost' => 'nullable|numeric|min:0',
        ]);

        $user = User::create([
            'names'     => $request->names,
            'surnames'  => $request->surnames,
            'email'     => $request->email,
            'phone'     => $request->phone,
            'address'   => $request->address,
            'password'  => Hash::make($request->password),
            'role_id'   => $request->role_id,
            'delivery_cost' => $request->delivery_cost ?? 0,
            'color'     => '#0073ff', // Default color
            'theme'     => 'light',    // Default theme
        ]);

        // Ensure active status exists or create it
        $status = Status::firstOrNew(['description' => 'Activo']);
        $status->save();
        $user->status()->associate($status);
        $user->save();

        // Create warehouse for Agency
        $role = Role::find($request->role_id);
        if ($role && $role->description === 'Agencia') {
            $type = WarehouseType::where('code', '=', 'AGENCY')->first();
            if ($type) {
                Warehouse::create([
                    'warehouse_type_id' => $type->id,
                    'user_id' => $user->id,
                    'code' => 'WH-AG-' . $user->id . '-' . time(),
                    'name' => 'Bodega Agencia: ' . $user->names,
                    'is_active' => true,
                    'is_main' => false
                ]);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Usuario creado exitosamente',
            'user'   => $user,
        ], 201);
    }

    public function get_all_users(Request $request)
    {

        $users = User::with('role')->whereHas('status', function ($query) {
            $query->where('description', '=', 'Activo');
        })->paginate(20);

        return response()->json(['status' => true, 'data' => $users]);
    }
    public function deliverers(Request $request)
    {
        $roleId = Role::where('description', '=', 'Repartidor')->value('id');
        $q = trim((string) $request->get('search', ''));

        $users = User::query()
            ->where('role_id', '=', $roleId)
            ->when(Auth::user()->role?->description === 'Agencia', function ($query) {
                $query->where('agency_id', '=', Auth::id());
            })
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('names', 'like', "%$q%")
                        ->orWhere('surnames', 'like', "%$q%")
                        ->orWhere('email', 'like', "%$q%");
                });
            })
            ->orderBy('names')
            ->select('id', 'names', 'surnames', 'email', 'created_at')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data'   => $users->items(),
            'meta'   => [
                'current_page' => $users->currentPage(),
                'total'        => $users->total(),
                'last_page'    => $users->lastPage(),
            ],
        ]);
    }

    // POST /users/deliverers
    public function storeDeliverer(Request $request)
    {
        $request->validate([
            'names'    => 'required|string|max:100',
            'surnames' => 'nullable|string|max:100',
            'phone' => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $roleId = Role::where('description', '=', 'Repartidor')->value('id');

        $user = User::create([
            'names'     => $request->names,
            'surnames'  => $request->surnames,
            'email'     => $request->email,
            'phone'     => $request->phone,
            'password'  => Hash::make($request->password),
            'role_id'   => $roleId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Repartidor creado',
            'user'   => $user,
        ], 201);
    }

    // PUT /users/deliverers/{user}
    public function updateDeliverer(Request $request, User $user)
    {
        // asegurar que realmente es repartidor
        if ($user->role?->description !== 'Repartidor') {
            return response()->json(['status' => false, 'message' => 'No es un repartidor'], 422);
        }

        $request->validate([
            'names'    => 'required|string|max:100',
            'surnames' => 'nullable|string|max:100',
            'email'    => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
        ]);

        $payload = [
            'names'    => $request->names,
            'surnames' => $request->surnames,
            'email'    => $request->email,
        ];
        if ($request->filled('password')) {
            $payload['password'] = Hash::make($request->password);
        }

        $user->update($payload);

        return response()->json([
            'status' => true,
            'message' => 'Repartidor actualizado',
            'user'   => $user,
        ]);
    }

    // DELETE /users/deliverers/{user}
    public function destroyDeliverer(User $user)
    {
        if ($user->role?->description !== 'Repartidor') {
            return response()->json(['status' => false, 'message' => 'No es un repartidor'], 422);
        }

        $user->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Repartidor eliminado',
        ]);
    }
    /**
     * Login de usuario
     */
    public function login(Request $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }
        $user = User::with('role')->where('email', '=', $request['email'])->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;
        $user->token = $token;
        $logs = Log::create([
            'description' => "El usuario $user->names $user->surnames ($user->id) inició sesión. ($user->email)",
            'impact' => 'Normal',
            'author' => 'Sistema',
        ]);
        $logs->user()->associate($user);
        $logs->save();
        return response()->json([
            'status' => true,
            'message' => 'Bienvenido ' . $user->names . ' ' . $user->surnames,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }
    /**
     * Cerrar sesion
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $logs = Log::create([
            'description' => "El usuario $user->first_name $user->lastname ($user->document) cerró sesión. ($user->email)",
            'impact' => 'Leve',
            'author' => 'Sistema',
        ]);
        $logs->user()->associate($user);
        $logs->save();
        $request->user()->currentAccessToken()->delete();
        return [
            'status' => true,
            'message' => 'Has cerrado sesion exitosamente'
        ];
    }
    public function agents()
    {
        $agents = User::whereHas('role', function ($q) {
            $q->where('description', '=', 'Vendedor');
        })->get();

        return response()->json([
            'status' => true,
            'data' => $agents
        ]);
    }

    public function usersByRole($roleDescription)
    {
        $users = User::whereHas('role', function ($q) use ($roleDescription) {
            $q->where('description', '=', $roleDescription);
        })->get();

        return response()->json([
            'status' => true,
            'data' => $users
        ]);
    }
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'names' => 'required|string|max:255',
            'surnames' => 'string|max:255',
            'phone' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'address' => 'required|string',
            'password' => 'required|string|min:8',
            'level' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }

        $user = User::create([
            'names' => $request->names,
            'surnames' => $request->surnames,
            'email' => $request->email,
            'address' => $request->address,
            'password' => Hash::make($request->password),
            'color' => '#C0EA0F',
        ]);
        // Obtener status activo o crear status si no existe
        $status = Status::firstOrNew(['description' => 'Activo']);
        $status->save();

        // Se asocia el status al usuario
        $user->status()->associate($status);

        // Obtener rol cliente o crear rol si no existe
        $role = Role::firstOrNew(['description' => 'Usuario']);
        $role->save();

        // Se asocia el rol al usuario
        $user->role()->associate($role);
        // Se guarda el usuario
        $user->save();

        // Token de auth
        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json(['data' => $user, 'token' => $token, 'token_type' => 'Bearer', 'status' => true]);
    }
    /**
     * Registrar administrador de condominios
     */
    public function register_master(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'names' => 'required|string|max:255',
            'surnames' => 'string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }

        $user = User::create([
            'names' => $request->names,
            'surnames' => $request->surnames,
            'email' => $request->email,
            'address' => $request->address,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'theme' => 'light',
            'color' => '#0073ff',
        ]);
        // Obtener status activo o crear status si no existe
        $status = Status::firstOrNew(['description' => 'Activo']);
        $status->save();

        // Se asocia el status al usuario
        $user->status()->associate($status);

        // Obtener rol cliente o crear rol si no existe
        $role = Role::firstOrNew(['description' => 'Admin']);
        $role->save();

        // Se asocia el rol al usuario
        $user->role()->associate($role);
        // Se guarda el usuario
        $user->save();

        // Token de auth
        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json(['data' => $user, 'token' => $token, 'token_type' => 'Bearer', 'status' => true]);
    }
    /**
     * Registrar administrador de condominios
     */
    public function register_agent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'names' => 'required|string|max:255',
            'surnames' => 'string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }

        $user = User::create([
            'names' => $request->names,
            'surnames' => $request->surnames,
            'email' => $request->email,
            'address' => $request->address,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'theme' => 'light',
            'color' => '#0073ff',
        ]);
        // Obtener status activo o crear status si no existe
        $status = Status::firstOrNew(['description' => 'Activo']);
        $status->save();

        // Se asocia el status al usuario
        $user->status()->associate($status);

        // Obtener rol cliente o crear rol si no existe
        $role = Role::firstOrNew(['description' => 'Vendedor']);
        $role->save();

        // Se asocia el rol al usuario
        $user->role()->associate($role);
        // Se guarda el usuario
        $user->save();

        // Token de auth
        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json(['data' => $user, 'token' => $token, 'token_type' => 'Bearer', 'status' => true]);
    }

    public function edit_color(Request $request, User $user)
    {
        if (!$request->color) {
            return response()->json(['status' => false, 'message' => 'El color es obligatorio'], 400);
        }
        $user->color = $request->color;
        $user->save();

        return response()->json(['status' => true, 'message' => 'Se ha cambiado el color'], 200);
    }
    public function edit_theme(Request $request, User $user)
    {
        if (!$request->theme) {
            return response()->json(['status' => false, 'message' => 'El tema es obligatorio'], 400);
        }
        $user->theme = $request->theme;
        $user->save();

        return response()->json(['status' => true, 'message' => 'Se ha cambiado el tema'], 200);
    }
    public function get_logged_user_data(Request $request)
    {
        $data = $request->user();
        $user = User::with('role')->where('id', '=', $data->id)->first();
        return response()->json(['user' => $user]);
    }
    public function edit_user_data(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'string|max:255',
            'names' => 'string|max:255',
            'surnames' => 'string|max:255',
            'email' => 'string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'string|min:8',
            'address' => 'string|min:8',
            'delivery_cost' => 'numeric|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }
        try {
            //code...
            if ($request->phone != '') {
                $user->phone = $request->phone;
            }
            if ($request->names != '') {
                $user->names = $request->names;
            }
            if ($request->surnames != '') {
                $user->surnames = $request->surnames;
            }
            if ($request->email != '') {
                $user->email = $request->email;
            }
            if ($request->address != '') {
                $user->address = $request->address;
            }
            if ($request->has('delivery_cost')) {
                $user->delivery_cost = $request->delivery_cost;
            }
            $user->save();
            return response()->json(['status' => true, 'message' => 'Se ha editado el usuario', 'data' => $user], 200);
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['status' => false, 'message' => 'Error al editar el usuario', 'error' => $th->getMessage()], 500);
        }
    }

    public function edit_user(Request $request, User $user)
    {

        if ($request->password != $request->confirmarPassword) {
            return response()->json(['status' => false, 'errors' => ['password' => 'Las contraseñas no coinciden']], 400);
        }

        $validator = Validator::make($request->all(), [
            'phone' => 'string|max:255',
            // 'email' => 'string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 400);
        }
        $prev_phone = $user->phone;
        $new_password = $user->password;
        if ($request->phone != '') {
            $user->phone = $request->phone;
        }
        if ($request->password != '' && $request->confirmarPassword != '') {
            $new_password = $request->password;
            $user->password = Hash::make($request->password);
        }
        $user->save();
        $logs = Log::create([
            'description' => "El usuario $user->names $user->surnames ($user->document) edito sus datos, Tlf: $prev_phone, Pass: $new_password. ($user->email)",
            'impact' => 'Alta',
            'author' => 'Sistema',
        ]);
        $logs->user()->associate($user);
        $logs->save();
        return response()->json(['status' => true, 'message' => 'Se ha editado el usuario', 'user' => $user], 200);
    }
    public function testRegister(Request $request)
    {
        // Validation similar to store/register
        $request->validate([
            'names'    => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role_id'  => 'required|exists:roles,id',
            'phone'    => 'nullable|string',
            'address'  => 'nullable|string',
        ]);

        $user = User::create([
            'names'     => $request->names,
            'surnames'  => $request->surnames ?? '',
            'email'     => $request->email,
            'phone'     => $request->phone ?? '0000000000',
            'address'   => $request->address ?? 'Address',
            'password'  => Hash::make($request->password),
            'role_id'   => $request->role_id,
            'color'     => '#0073ff',
            'theme'     => 'light',
        ]);

        // Ensure active status
        $status = Status::firstOrNew(['description' => 'Activo']);
        $status->save();
        $user->status()->associate($status);
        $user->save();

        // Create warehouse for Agency
        $role = Role::find($request->role_id);
        if ($role && $role->description === 'Agencia') {
            $type = WarehouseType::where('code', '=', 'AGENCY')->first();
            if ($type) {
                Warehouse::create([
                    'warehouse_type_id' => $type->id,
                    'user_id' => $user->id,
                    'code' => 'WH-AG-' . $user->id . '-' . time(),
                    'name' => 'Bodega Agencia: ' . $user->names,
                    'is_active' => true,
                    'is_main' => false
                ]);
            }
        }
        
        // Token de auth
        $token = $user->createToken("auth_token")->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Usuario de prueba creado',
            'user'   => $user,
            'token'  => $token
        ], 201);
    }

    // PUT /user/{user}/change/password
    public function edit_password(Request $request, User $user)
    {
        // Solo Admin puede cambiar contraseña de otros
        $authUser = Auth::user();
        if ($authUser->role?->description !== 'Admin') {
            return response()->json([
                'status' => false,
                'message' => 'No tienes permisos para realizar esta acción'
            ], 403);
        }

        $request->validate([
            'new_password' => 'required|string|min:6',
        ]);

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Contraseña actualizada exitosamente',
        ]);
    }
}
