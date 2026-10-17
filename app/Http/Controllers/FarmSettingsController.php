<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFarmSettingsRequest;
use App\Models\Farm;
use App\Models\Role;
use App\Services\FarmSettingsService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class FarmSettingsController extends Controller
{
    public function edit()
    {
        Gate::authorize('manage-farm');

        return view('settings', ['farm' => Farm::firstOrFail(), 'section' => 'settings', 'roles' => Role::all(), 'timezones' => \DateTimeZone::listIdentifiers()]);
    }

    public function update(UpdateFarmSettingsRequest $request, FarmSettingsService $service)
    {
        $service->update($request->user(), $request->validated());

        return redirect()->route('settings.edit')->with('success', 'Farm settings saved.');
    }

    public function logo()
    {
        $path = Farm::firstOrFail()->logo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
