<?php

namespace App\Services\Meta;

use App\Models\City;
use App\Models\MetaCampaign;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Sugiere el producto y la ciudad de una campaña nueva (respuesta de Fran del 2026-10-09, punto 6: "que el sistema
 * sugiera automáticamente el producto y la ciudad al momento de clasificar"). Solo sugiere: la clasificación la guarda
 * el Admin (§11).
 *
 * - Producto: la marca (lo que va antes de "®" en el nombre del producto, o el nombre entero) dentro del nombre de la
 *   campaña. Si no aparece, lo aprendido de las campañas ya clasificadas; y si no, la marca en el nombre de la cuenta.
 * - Ciudad: el nombre de la ciudad o la zona ("CCS" para Distrito Capital) dentro del nombre de la campaña. Si no
 *   aparece, lo aprendido de las campañas ya clasificadas.
 *
 * Lo aprendido: las palabras del nombre que, en las campañas ya clasificadas, siempre fueron del mismo producto (o de
 * la misma ciudad). Así se reconocen las abreviaturas que use el Admin. Si las pistas llevan a dos productos distintos,
 * no se sugiere ninguno.
 */
class CampaignSuggester
{
    /** Cómo se escriben las ciudades en los nombres de campaña (las de BM1 usan CCS, CBOBO y MCAY). */
    private const CITY_ALIASES = [
        'distrito capital' => ['caracas', 'ccs'],
        'carabobo' => ['cbobo', 'valencia', 'vln', 'vlc'],
        'aragua' => ['mcay', 'maracay', 'mcy'],
    ];

    /** @var array<string, array<int, true>> palabra clave => productos */
    private array $productKeys = [];
    /** @var array<string, array<int, true>> palabra clave => ciudades */
    private array $cityKeys = [];
    /** @var array<string, array<int, int>> palabra => producto => campañas clasificadas que la tienen */
    private array $learnedProducts = [];
    /** @var array<string, array<int, int>> palabra => ciudad => campañas clasificadas que la tienen */
    private array $learnedCities = [];
    /** @var array<string, string> palabra => una campaña clasificada que la tiene (para decir de dónde salió) */
    private array $example = [];

    public function __construct()
    {
        foreach (Product::get(['id', 'title', 'showable_name']) as $p) {
            foreach (array_filter([$p->showable_name, $p->title]) as $name) {
                $brand = preg_split('/[®™©]/u', $name)[0];
                foreach ([self::norm($brand), self::norm($name)] as $key) {
                    $this->addKey($this->productKeys, $key, $p->id);
                }
            }
        }
        foreach (City::get(['id', 'name']) as $c) {
            $name = self::norm($c->name);
            foreach (array_merge([$name], self::CITY_ALIASES[$name] ?? []) as $key) {
                $this->addKey($this->cityKeys, $key, $c->id);
            }
        }
        foreach (MetaCampaign::whereNotNull('product_id')->whereNotNull('city_id')->orderBy('id')->get(['name', 'product_id', 'city_id']) as $c) {
            foreach (self::tokens($c->name) as $t) {
                $this->learnedProducts[$t][$c->product_id] = ($this->learnedProducts[$t][$c->product_id] ?? 0) + 1;
                $this->learnedCities[$t][$c->city_id] = ($this->learnedCities[$t][$c->city_id] ?? 0) + 1;
                $this->example[$t] ??= $c->name;
            }
        }
    }

    /**
     * @return array{product_id: ?int, city_id: ?int, product_from: ?string, city_from: ?string, similar_to: ?string}
     *         product_from y city_from: name (el nombre de la campaña), similar (aprendido de una campaña ya
     *         clasificada, la de similar_to) o account (el nombre de la cuenta)
     */
    public function suggest(MetaCampaign $campaign, ?string $accountName = null): array
    {
        $name = self::norm($campaign->name);
        $out = ['product_id' => $this->find($name, $this->productKeys), 'city_id' => $this->find($name, $this->cityKeys),
            'product_from' => null, 'city_from' => null, 'similar_to' => null];
        $out['product_from'] = $out['product_id'] ? 'name' : null;
        $out['city_from'] = $out['city_id'] ? 'name' : null;

        $tokens = self::tokens($campaign->name);
        foreach (['product' => $this->learnedProducts, 'city' => $this->learnedCities] as $k => $index) {
            if (!$out["{$k}_id"] && ($learned = $this->learned($tokens, $index))) {
                [$out["{$k}_id"], $word] = $learned;
                $out["{$k}_from"] = 'similar';
                $out['similar_to'] ??= $this->example[$word];
            }
        }
        if (!$out['product_id'] && $accountName && ($id = $this->find(self::norm($accountName), $this->productKeys))) {
            $out['product_id'] = $id;
            $out['product_from'] = 'account';
        }

        return $out;
    }

    /** La clave más larga que aparece como palabra entera en el texto, si es de un solo producto o ciudad. */
    private function find(string $text, array $keys): ?int
    {
        $padded = " {$text} ";
        $best = null;
        foreach ($keys as $key => $ids) {
            if (str_contains($padded, " {$key} ")) {
                if ($best === null || mb_strlen($key) > mb_strlen($best)) {
                    $best = $key;
                }
            }
        }
        return $best !== null && count($keys[$best]) === 1 ? array_key_first($keys[$best]) : null;
    }

    /**
     * Lo que dicen las palabras del nombre que en las campañas clasificadas siempre fueron de lo mismo. Solo si todas
     * esas pistas llevan a lo mismo.
     *
     * @return array{0: int, 1: string}|null el producto o la ciudad, y la primera palabra que lo indicó
     */
    private function learned(array $tokens, array $index): ?array
    {
        $found = [];
        foreach ($tokens as $t) {
            if (count($index[$t] ?? []) === 1) {
                $found[array_key_first($index[$t])] ??= $t;
            }
        }
        return count($found) === 1 ? [array_key_first($found), reset($found)] : null;
    }

    /** Una clave y, si tiene espacios, la misma junta ("solo brillo" y "solobrillo"). */
    private function addKey(array &$keys, string $key, int $id): void
    {
        foreach (array_unique([$key, str_replace(' ', '', $key)]) as $k) {
            if (mb_strlen($k) >= 3) {
                $keys[$k][$id] = true;
            }
        }
    }

    /** Minúsculas, sin tildes y con un espacio entre palabras. */
    public static function norm(?string $text): string
    {
        $text = Str::lower(Str::ascii((string) $text));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Las palabras de un nombre, sin números sueltos (fechas, "ABO 3"). */
    private static function tokens(?string $name): array
    {
        return array_values(array_unique(array_filter(explode(' ', self::norm($name)), fn ($w) => strlen($w) >= 2 && !ctype_digit($w))));
    }
}
