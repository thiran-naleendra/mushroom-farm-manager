<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveUserRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Farm;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', User::class);

        return view('users.index', ['farm' => Farm::firstOrFail(), 'section' => 'users', 'users' => User::orderBy('name')->paginate(12), 'roles' => Role::all()]);
    }

    public function edit(User $user)
    {
        Gate::authorize('update', $user);

        return view('users.edit', ['farm' => Farm::firstOrFail(), 'section' => 'users', 'account' => $user]);
    }

    public function store(SaveUserRequest $request, UserManagementService $service)
    {
        $service->save($request->user(), $request->validated());

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function update(SaveUserRequest $request, User $user, UserManagementService $service)
    {
        $service->save($request->user(), $request->validated(), $user);

        return redirect()->route('users.index')->with('success', 'User updated. History preserved.');
    }

    public function role(UpdateRoleRequest $request, string $role, UserManagementService $service)
    {
        $service->setRoleActive($request->user(), $role, $request->boolean('is_active'));

        return redirect()->route('users.index')->with('success', 'Role access updated.');
    }
}
