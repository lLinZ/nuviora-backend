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
     * Sanitize the path to prevent Path Traversal.
     */
    protected function sanitizePath($path)
    {
        // 1. Remove any ".." or ".\" or "./" to prevent going up
        $path = str_replace(['..', './', '.\\'], '', $path);
        
        // 2. Remove leading/trailing slashes
        $path = trim($path, '/\\');

        return $path;
    }

    /**
     * List files and directories.
     */
    public function index(Request $request)
    {
        $this->authorizeAdmin();
        $path = $this->sanitizePath($request->query('path', ''));
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
                'path' => $this->sanitizePath(str_replace($this->basePath, '', $dir)),
                'last_modified' => Storage::disk($this->disk)->lastModified($dir),
            ];
        }

        foreach ($files as $file) {
            $result[] = [
                'name' => basename($file),
                'type' => 'file',
                'path' => $this->sanitizePath(str_replace($this->basePath, '', $file)),
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
            'name' => 'required|string|max:50|regex:/^[a-zA-Z0-9_\-\s]+$/'
        ]);

        $path = $this->sanitizePath($request->path);
        $name = Str::slug($request->name); // Secure naming
        
        $fullPath = $this->basePath . ($path ? '/' . $path : '') . '/' . $name;

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
            'files.*' => 'required|file|mimes:jpg,jpeg,png,webp,gif,pdf,svg,xlsx,docx|max:10240' // Secure extensions
        ]);

        $uploaded = [];
        $path = $this->sanitizePath($request->path);
        $targetDir = $this->basePath . ($path ? '/' . $path : '');

        foreach ($request->file('files') as $file) {
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $extension = $file->getClientOriginalExtension();
            
            // Sanitize filename for safe URLs
            $safeName = Str::slug($originalName) . '.' . $extension;
            
            $finalPath = Storage::disk($this->disk)->putFileAs($targetDir, $file, $safeName);
            $uploaded[] = $finalPath;
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
            'new_name' => 'required|string|max:100|regex:/^[a-zA-Z0-9_\-\s\.]+$/'
        ]);

        $path = $this->sanitizePath($request->path);
        $base = $this->basePath . ($path ? '/' . $path : '');
        
        $oldPath = $base . '/' . $this->sanitizePath($request->old_name);
        
        // Check if new name is a file or directory
        $extension = pathinfo($request->old_name, PATHINFO_EXTENSION);
        $newNameClean = Str::slug(pathinfo($request->new_name, PATHINFO_FILENAME));
        $newPath = $base . '/' . $newNameClean . ($extension ? '.' . $extension : '');

        if (!Storage::disk($this->disk)->exists($oldPath)) {
            return response()->json(['message' => 'El archivo original no existe.'], 404);
        }

        if (Storage::disk($this->disk)->exists($newPath)) {
            return response()->json(['message' => 'Ya existe un archivo con ese nombre.'], 422);
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

        $path = $this->sanitizePath($request->path);
        $name = $this->sanitizePath($request->name);
        $fullPath = $this->basePath . ($path ? '/' . $path : '') . '/' . $name;

        if (!Storage::disk($this->disk)->exists($fullPath)) {
            return response()->json(['message' => 'El archivo no existe.'], 404);
        }

        // Prevent deleting the base library folder itself
        if ($fullPath === $this->basePath) {
            return response()->json(['message' => 'Operación no permitida.'], 403);
        }

        // Check if it's a directory (directories method returns paths)
        $isDir = collect(Storage::disk($this->disk)->directories(dirname($fullPath)))->contains($fullPath);

        if ($isDir) {
            Storage::disk($this->disk)->deleteDirectory($fullPath);
        } else {
            Storage::disk($this->disk)->delete($fullPath);
        }

        return response()->json(['status' => true, 'message' => 'Eliminado correctamente.']);
    }
}
