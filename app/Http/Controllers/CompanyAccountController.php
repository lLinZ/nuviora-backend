<?php

namespace App\Http\Controllers;

use App\Models\CompanyAccount;
use App\Models\StatementSource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyAccountController extends Controller
{
    public function index()
    {
        return response()->json(CompanyAccount::with('statementSource')->get());
    }

    /**
     * Lo que se puede elegir al configurar una cuenta (documento de Fran del 2026-10-06, §2): el método, la moneda y el
     * extracto donde se verifica, uno de los que ya existen o uno nuevo.
     */
    public function options()
    {
        return response()->json([
            'methods' => CompanyAccount::methods(),
            'currencies' => CompanyAccount::CURRENCIES,
            'sources' => StatementSource::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(true));

        $account = CompanyAccount::create($this->withSource($validated));

        return response()->json($account->load('statementSource'), 201);
    }

    public function show(CompanyAccount $companyAccount)
    {
        return response()->json($companyAccount->load('statementSource'));
    }

    public function update(Request $request, CompanyAccount $companyAccount)
    {
        $validated = $request->validate($this->rules(false));

        $companyAccount->update($this->withSource($validated));

        return response()->json($companyAccount->load('statementSource'));
    }

    public function destroy(CompanyAccount $companyAccount)
    {
        $companyAccount->delete();

        return response()->json(null, 204);
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => ($creating ? '' : 'sometimes|') . 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'method' => ['nullable', Rule::in(array_keys(CompanyAccount::methods()))],
            'currency' => ['nullable', Rule::in(CompanyAccount::CURRENCIES)],
            'statement_source_name' => 'nullable|string|max:60',
            'details' => 'nullable|array',
            'is_active' => 'boolean',
        ];
    }

    /** El extracto se elige por su nombre: si no existe, se crea con la moneda de la cuenta. */
    private function withSource(array $validated): array
    {
        if (array_key_exists('statement_source_name', $validated)) {
            $name = trim((string) $validated['statement_source_name']);
            $validated['statement_source_id'] = $name === '' ? null : (StatementSource::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id')
                ?? StatementSource::create(['name' => $name, 'currency' => $validated['currency'] ?? 'VES'])->id);
            unset($validated['statement_source_name']);
        }

        return $validated;
    }
}
