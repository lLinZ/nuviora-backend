<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Log;
use App\Models\Logs;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function get_all_users(Request $request)
    {

        $users = User::with('role')->whereHas('status', function ($query) {
            $query->where('description', 'Activo');
        })->whereHas('role', function ($query) {
            $query->where('description', 'Usuario');
        })->get();

        return response()->json(['status' => true, 'data' => $users]);
    }
    /**
     * Login de usuario
     */
    public function login(Request $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }
        $user = User::with('role')->where('email', $request['email'])->firstOrFail();
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
        $role = Role::firstOrNew(['description' => 'Master']);
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
        $user = User::with('role')->where('id', $data->id)->first();
        return response()->json(['user' => $user]);
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
}
