<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Models\PurchaseLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaterialService
{
    public function save(User $actor, array $data, ?Material $material = null): Material
    {
        return DB::transaction(function () use ($actor, $data, $material) {
            $material = $material ? Material::lockForUpdate()->findOrFail($material->id) : new Material;
            Gate::forUser($actor->fresh())->authorize($material->exists ? 'update' : 'create', $material->exists ? $material : Material::class);
            $category = MaterialCategory::lockForUpdate()->findOrFail($data['material_category_id']);
            $unit = MaterialUnit::lockForUpdate()->findOrFail($data['material_unit_id']);
            if ((! $category->is_active && $material->material_category_id != $category->id) || (! $unit->is_active && $material->material_unit_id != $unit->id)) {
                throw ValidationException::withMessages(['material_unit_id' => 'Choose an active category and unit.']);
            }
            if ($material->exists && $material->material_unit_id != $unit->id && ($material->movements()->exists() || PurchaseLine::where('material_id', $material->id)->exists())) {
                throw ValidationException::withMessages(['material_unit_id' => 'The stock unit cannot change after movements exist. Create a new material instead.']);
            }
            $opening = $data['quantity'] ?? '0';
            unset($data['quantity']);
            $new = ! $material->exists;
            $material->fill($data)->save();
            if ($new && MaterialQuantity::micros((string) $opening) > 0) {
                app(MaterialStockService::class)->adjust($actor, $material, [
                    'type' => 'opening', 'quantity' => $opening, 'material_unit_id' => $unit->id,
                    'reason' => 'Opening stock', 'request_id' => (string) Str::uuid(),
                ]);
            }

            return $material->refresh();
        });
    }

    public function archive(User $actor, Material $material): void
    {
        DB::transaction(function () use ($actor, $material) {
            $material = Material::lockForUpdate()->findOrFail($material->id);
            Gate::forUser($actor->fresh())->authorize('delete', $material);
            if ((int) $material->movements()->sum('quantity_micros') !== 0) {
                throw ValidationException::withMessages(['quantity' => 'Materials with stock cannot be archived. Deactivate the material or record an adjustment first.']);
            }
            $material->delete();
        });
    }
}
