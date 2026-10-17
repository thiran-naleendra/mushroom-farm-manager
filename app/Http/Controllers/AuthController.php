<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\SetupRequest;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function form()
    {
        return view('auth', ['setup' => ! Farm::exists()]);
    }

    public function setup(SetupRequest $request)
    {
        abort_if(Farm::exists(), 403);
        $data = $request->validated();
        $user = DB::transaction(function () use ($data) {
            abort_if(Farm::exists(), 403);
            $user = User::create($data);
            $user->forceFill(['role' => 'owner', 'is_active' => true])->save();
            $farm = new Farm(['owner_id' => $user->id, 'name' => $data['farm_name'], 'mode' => $data['mode'], 'currency' => $data['currency']]);
            $farm->id = 1;
            $farm->save();

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();
        if (! Auth::attemptWhen($credentials, fn (User $user) => $user->canAccessApp())) {
            throw ValidationException::withMessages(['email' => 'The login details are incorrect.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
