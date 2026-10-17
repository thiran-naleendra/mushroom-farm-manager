<x-card title="Cultivation stage & condition" description="Bag conditions are separate counts. Move bags between conditions in the same update; do not count discarded bags as contaminated as well.">
    <x-form :action="route('cultivation.update', $batch)" method="PATCH" class="space-y-5">
        <label>Stage<select name="stage">@foreach(\App\Models\Batch::STAGES as $stage)<option value="{{ $stage }}" @selected(old('stage', $batch->stage) === $stage)>{{ ucfirst($stage) }}</option>@endforeach</select></label>
        <div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">Healthy bags: <strong>{{ $batch->availableBags() }}</strong> · Sold {{ $batch->sold_bags ?? 0 }} · Damaged {{ $batch->damaged_bags ?? 0 }}</div>
        <div class="grid gap-4 sm:grid-cols-2"><x-field name="contaminated_bags" label="Contaminated bags" type="number" :value="$batch->contaminated_bags ?? 0" min="0" :max="$batch->bags" required /><x-field name="discarded_bags" label="Discarded bags" type="number" :value="$batch->discarded_bags ?? 0" min="0" :max="$batch->bags" required /></div>
        <details><summary class="cursor-pointer text-sm font-medium text-emerald-800">Growth dates</summary><div class="mt-4 grid gap-4 sm:grid-cols-2">@foreach(\App\Models\Batch::STAGES as $stage)<x-field :name="$stage.'_on'" :label="ucfirst($stage).' date'" type="date" :value="$batch->{$stage.'_on'}" :min="$batch->started_on" :max="now()->toDateString()" />@endforeach</div><p class="muted mt-3 text-xs">Recorded dates must follow stage order. A new stage date defaults to today when left blank.</p></details>
        <x-field name="expected_harvest_on" label="Expected harvest date" type="date" :value="$batch->expected_harvest_on" :min="$batch->started_on" />
        <label>Growth notes<textarea name="growth_notes" rows="3" maxlength="3000">{{ old('growth_notes', $batch->growth_notes) }}</textarea></label>
        <x-field name="reason" label="Reason for this update" required maxlength="500" /><x-button>Save cultivation update</x-button>
    </x-form>
</x-card>
