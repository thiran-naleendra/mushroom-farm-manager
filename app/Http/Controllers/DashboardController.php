<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $service)
    {
        Gate::authorize('access-section', 'dashboard');
        $farm = Farm::firstOrFail();

        return view('dashboard', ['farm' => $farm, 'section' => 'dashboard'] + $service->data($request->user(), $farm));
    }
}
