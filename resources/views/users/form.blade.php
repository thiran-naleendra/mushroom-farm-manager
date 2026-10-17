<x-field name="name" label="Name" :value="$account?->name ?? ''" required maxlength="120" autocomplete="name" />
<x-field name="email" label="Email" type="email" :value="$account?->email ?? ''" required maxlength="255" autocomplete="email" />
<label>{{ $account ? 'New password (leave blank to keep current)' : 'Password (at least 10 characters)' }}<input name="password" type="password" minlength="10" autocomplete="new-password" @required(!$account)></label>
<label>Confirm password<input name="password_confirmation" type="password" minlength="10" autocomplete="new-password" @required(!$account)></label>
<label>Role<select name="role" x-model="role">@foreach(['owner', 'manager', 'worker'] as $role)<option value="{{ $role }}" @selected(old('role', $account?->role ?? 'worker') === $role)>{{ ucfirst($role) }}</option>@endforeach</select></label>
<label>Account status<select name="is_active"><option value="1" @selected((string) old('is_active', $account?->is_active ?? true) === '1')>Active</option><option value="0" @selected((string) old('is_active', $account?->is_active ?? true) === '0' || old('is_active', $account?->is_active ?? true) === false)>Inactive</option></select></label>
<fieldset class="space-y-3 rounded-xl border border-stone-200 p-4" x-show="role === 'worker'">
    <legend class="px-2 text-sm font-medium">Authorized worker tasks</legend>
    @foreach(['can_work_production' => 'Production tasks', 'can_work_harvest' => 'Record harvests'] as $permission => $label)
        <input type="hidden" name="{{ $permission }}" value="0">
        <label class="flex items-center gap-3"><input class="w-auto" type="checkbox" name="{{ $permission }}" value="1" @checked(old($permission, $account?->{$permission} ?? false))><span>{{ $label }}</span></label>
    @endforeach
</fieldset>
<p class="muted">Owners have full access. Managers can manage production, harvests, and sales. Workers only access the tasks selected above. Optional roles must also be active.</p>
<button class="btn w-full">{{ $account ? 'Save changes' : 'Create user' }}</button>
