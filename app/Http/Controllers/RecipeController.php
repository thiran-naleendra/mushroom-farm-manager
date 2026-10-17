<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRecipeRequest;
use App\Models\Farm;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Recipe;
use App\Services\RecipeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RecipeController extends Controller
{
    private function context(): array
    {
        return ['farm' => Farm::firstOrFail(), 'section' => 'production'];
    }

    public function index()
    {
        Gate::authorize('viewAny', Recipe::class);

        return view('recipes.index', $this->context() + ['recipes' => Recipe::withCount('ingredients')->orderBy('name')->paginate(15)]);
    }

    private function form(Recipe $recipe)
    {
        $recipe->load('ingredients');

        return view('recipes.form', $this->context() + compact('recipe') + [
            'materials' => Material::with('unit')->where('supply_type', 'growing')->where('is_active', true)->orderBy('name')->get(),
            'units' => MaterialUnit::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', Recipe::class);

        return $this->form(new Recipe);
    }

    public function edit(Recipe $recipe)
    {
        Gate::authorize('update', $recipe);

        return $this->form($recipe);
    }

    public function store(SaveRecipeRequest $request, RecipeService $service)
    {
        $service->save($request->user(), $request->validated());

        return redirect()->route('recipes.index')->with('success', 'Recipe saved. Stock is unchanged until production is confirmed.');
    }

    public function update(SaveRecipeRequest $request, Recipe $recipe, RecipeService $service)
    {
        $service->save($request->user(), $request->validated(), $recipe);

        return redirect()->route('recipes.index')->with('success', 'Recipe updated. Historical production ingredients and costs are preserved.');
    }

    public function destroy(Recipe $recipe)
    {
        Gate::authorize('delete', $recipe);
        DB::transaction(fn () => Recipe::lockForUpdate()->findOrFail($recipe->id)->delete());

        return redirect()->route('recipes.index')->with('success', 'Recipe archived. Production history is preserved.');
    }
}
