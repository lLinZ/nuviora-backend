<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MediaExplorerController extends Controller
{
    private $disk = 'public';
    private $basePath = 'media_library';

    /**
     * Check if user is Admin or Gerente.
     */
    protected function authorizeAdmin()
    {
        $user = Auth::user();
        if (!$user->relationLoaded('role')) {
            $user->load('role');
        }
        $role = strtolower($user->role->description ?? '');
        if (!in_array($role, ['admin', 'gerente'])) {
            abort(Response::HTTP_FORBIDDEN, 'No tienes permisos para acceder a esta área.');
        }
    }

    /**
     * List files and directories.
     */
    public function index(Request $request)
    {
        $this->authorizeAdmin();
        $path = $request->query('path', '');
        $fullPath = $this->basePath . ($path ? '/' . $path : '');

        // Ensure base directory exists
        if (!Storage::disk($this->disk)->exists($this->basePath)) {
            Storage::disk($this->disk)->makeDirectory($this->basePath);
        }

        $directories = Storage::disk($this->disk)->directories($fullPath);
        $files = Storage::disk($this->disk)->files($fullPath);

        $result = [];

        foreach ($directories as $dir) {
            $result[] = [
                'name' => basename($dir),
                'type' => 'directory',
                'path' => str_replace($this->basePath . '/', '', $dir),
                'last_modified' => Storage::disk($this->disk)->lastModified($dir),
            ];
        }

        foreach ($files as $file) {
            $result[] = [
                'name' => basename($file),
                'type' => 'file',
                'path' => str_replace($this->basePath . '/', '', $file),
                'size' => Storage::disk($this->disk)->size($file),
                'url'  => Storage::disk($this->disk)->url($file),
                'extension' => pathinfo($file, PATHINFO_EXTENSION),
                'last_modified' => Storage::disk($this->disk)->lastModified($file),
            ];
        }

        return response()->json([
            'status' => true,
            'current_path' => $path,
            'items' => $result
        ]);
    }

    /**
     * Create directory.
     */
    public function mkdir(Request $request)
    {
        $this->authorizeAdmin();
        $request->validate([
            'path' => 'nullable|string',
            'name' => 'required|string|max:100'
        ]);

        $fullPath = $this->basePath . ($request->path ? '/' . $request->path : '') . '/' . $request->name;

        if (Storage::disk($this->disk)->exists($fullPath)) {
            return response()->json(['message' => 'La carpeta ya existe.'], 422);
        }

        Storage::disk($this->disk)->makeDirectory($fullPath);

        return response()->json(['status' => true, 'message' => 'Carpeta creada exitosamente.']);
    }

    /**
     * Upload files.
     */
    public function upload(Request $request)
    {
        $this->authorizeAdmin();
        $request->validate([
            'path' => 'nullable|string',
            'files' => 'required|array',
            'files.*' => 'required|file|max:10240' // 10MB limit
        ]);

        $uploaded = [];
        $targetDir = $this->basePath . ($request->path ? '/' . $request->path : '');

        foreach ($request->file('files') as $file) {
            $name = $file->getClientOriginalName();
            $path = Storage::disk($this->disk)->putFileAs($targetDir, $file, $name);
            $uploaded[] = $path;
        }

        return response()->json([
            'status' => true,
            'message' => count($uploaded) . ' archivo(s) subido(s) correctamente.',
            'files' => $uploaded
        ]);
    }

    /**
     * Rename file or directory.
     */
    public function rename(Request $request)
    {
        $this->authorizeAdmin();
        $request->validate([
            'path' => 'nullable|string',
            'old_name' => 'required|string',
            'new_name' => 'required|string'
        ]);

        $base = $this->basePath . ($request->path ? '/' . $request->path : '');
        $oldPath = $base . '/' . $request->old_name;
        $newPath = $base . '/' . $request->new_name;

        if (!Storage::disk($this->disk)->exists($oldPath)) {
            return response()->json(['message' => 'El archivo original no existe.'], 404);
        }

        Storage::disk($this->disk)->move($oldPath, $newPath);

        return response()->json(['status' => true, 'message' => 'Renombrado correctamente.']);
    }

    /**
     * Delete file or directory.
     */
    public function delete(Request $request)
    {
        $this->authorizeAdmin();
        $request->validate([
            'path' => 'nullable|string',
            'name' => 'required|string'
        ]);

        $fullPath = $this->basePath . ($request->path ? '/' . $request->path : '') . '/' . $request->name;

        if (!Storage::disk($this->disk)->exists($fullPath)) {
            return response()->json(['message' => 'El archivo no existe.'], 404);
        }

        if (is_dir(Storage::disk($this->disk)->path($fullPath))) {
            Storage::disk($this->disk)->deleteDirectory($fullPath);
        } else {
            Storage::disk($this->disk)->delete($fullPath);
        }

        return response()->json(['status' => true, 'message' => 'Eliminado correctamente.']);
    }
}
