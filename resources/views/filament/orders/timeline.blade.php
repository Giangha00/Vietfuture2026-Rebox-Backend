@php
    use App\Filament\Resources\Orders\Support\OrderAdminActions;

    $timeline = $getState() ?? ($record->timeline ?? []);
    if (! is_array($timeline)) {
        $timeline = [];
    }
    $timeline = array_reverse($timeline);
@endphp

@if ($timeline === [])
    <p class="text-sm text-gray-500">No timeline events yet.</p>
@else
    <ol class="space-y-3">
        @foreach ($timeline as $entry)
            @php
                $status = $entry['status'] ?? '';
                $label = OrderAdminActions::STATUS_OPTIONS[$status] ?? $status;
                $at = $entry['at'] ?? null;
                try {
                    $atLabel = $at ? \Illuminate\Support\Carbon::parse($at)->timezone(config('app.timezone'))->format('M j, Y g:i A') : '—';
                } catch (\Throwable $e) {
                    $atLabel = (string) $at;
                }
            @endphp
            <li class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $label }}</p>
                    <p class="text-xs text-gray-500">{{ $atLabel }}</p>
                </div>
                @if (! empty($entry['note']))
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $entry['note'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
