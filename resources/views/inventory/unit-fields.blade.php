<x-field name="name" label="Unit name" :value="$unit?->name" required maxlength="100" />
<x-field name="symbol" label="Symbol" :value="$unit?->symbol" required maxlength="20" />
<label>Dimension<select name="dimension">@foreach(['mass', 'volume', 'count', 'custom'] as $dimension)<option value="{{ $dimension }}" @selected(($unit?->dimension ?? 'custom') === $dimension)>{{ ucfirst($dimension) }}</option>@endforeach</select></label>
<x-field name="factor" label="Conversion factor" type="number" :value="$unit?->factor ?? '1'" min="0.000001" max="1000" step="0.000001" required />
<label>Status<select name="is_active"><option value="1" @selected($unit?->is_active ?? true)>Active</option><option value="0" @selected($unit && !$unit->is_active)>Inactive</option></select></label>
