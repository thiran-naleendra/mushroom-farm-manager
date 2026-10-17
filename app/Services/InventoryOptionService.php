<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialMovement;
use App\Models\MaterialUnit;
use App\Models\ProductionIngredient;
use App\Models\PurchaseLine;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InventoryOptionService
{
    public function save(User $actor, string $kind, array $data, ?int $id = null): void
    {
        DB::transaction(function () use ($actor, $kind, $data, $id) {
            Gate::forUser($actor->fresh())->authorize('create', Material::class);
            $model = $kind === 'categories' ? MaterialCategory::class : MaterialUnit::class;
            $option = $id ? $model::lockForUpdate()->findOrFail($id) : new $model;
            if ($option instanceof MaterialUnit && $option->exists) {
                $used = Material::withTrashed()->where('material_unit_id', $id)->exists() || MaterialMovement::where('material_unit_id', $id)->exists() || PurchaseLine::where('material_unit_id', $id)->exists()
                    || RecipeIngredient::where('material_unit_id', $id)->exists() || ProductionIngredient::where('material_unit_id', $id)->exists();
                if ($used && ($option->symbol !== $data['symbol'] || $option->dimension !== $data['dimension'] || MaterialQuantity::micros($option->factor) !== MaterialQuantity::micros((string) $data['factor']))) {
                    throw ValidationException::withMessages(['factor' => 'A unit used by materials or movements cannot change symbol, dimension or conversion factor.']);
                }
            }
            if ($kind === 'units' && $data['dimension'] === 'custom' && MaterialQuantity::micros((string) $data['factor']) !== 1000000) {
                throw ValidationException::withMessages(['factor' => 'Custom units must use a factor of 1 and only convert to themselves.']);
            }
            $option->fill($data)->save();
        });
    }
}
