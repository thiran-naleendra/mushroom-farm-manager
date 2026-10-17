<x-table :label="$report['title']"><x-slot:head><tr>@foreach($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></x-slot:head>
@forelse($rows as $row)<tr>@foreach($report['columns'] as $key => $label)<td>{{ $format($row, $key) }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($report['columns']) }}" class="py-8 text-center">No records for these filters.</td></tr>@endforelse
</x-table>
