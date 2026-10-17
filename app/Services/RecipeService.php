<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecipeService
{
    public function save(User $actor, array $data, ?Recipe $recipe = null): Recipe
    {
        return DB::transaction(function () use ($actor, $data, $recipe) {
            $recipe = $recipe ? Recipe::lockForUpdate()->findOrFail($recipe->id) : new Recipe;
            Gate::forUser($actor->fresh())->authorize($recipe->exists ? 'update' : 'create', $recipe->exists ? $recipe : Recipe::class);
            $materials = Material::with('unit')->whereIn('id', array_column($data['ingredients'], 'material_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $units = MaterialUnit::whereIn('id', array_column($data['ingredients'], 'material_unit_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($data['ingredients'] as $ingredient) {
                $material = $materials->get($ingredient['material_id']);
                $unit = $units->get($ingredient['material_unit_id']);
                if (! $material?->is_active || $material->supply_type !== 'growing' || ! $unit?->is_active) {
                    throw ValidationException::withMessages(['ingredients' => 'Use active growing materials and active units.']);
                }
                MaterialQuantity::convert((string) $ingredient['quantity'], $unit, $material->unit);
            }
            $ingredients = $data['ingredients'];
            unset($data['ingredients']);
            if ($recipe->exists) {
                $recipe->revision++;
            }
            $recipe->fill($data)->save();
            $recipe->ingredients()->delete();
            $recipe->ingredients()->createMany($ingredients);

            return $recipe;
        });
    }
}
