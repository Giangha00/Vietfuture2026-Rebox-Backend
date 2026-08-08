@php
    use Illuminate\Support\Collection;

    $raw = $images
        ?? (isset($getState) ? $getState() : null)
        ?? (isset($record) ? ($record->images ?? null) : null)
        ?? [];

    if ($raw instanceof Collection) {
        $raw = $raw->all();
    }

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [$raw];
    }

    if (! is_array($raw)) {
        $raw = [];
    }

    // Flatten accidental nesting like [[url1, url2]] or [{url: '...'}]
    $images = collect($raw)
        ->flatMap(function ($item) {
            if (is_array($item)) {
                if (isset($item['url'])) {
                    return [$item['url']];
                }

                return array_values($item);
            }

            return [$item];
        })
        ->map(fn ($url) => is_string($url) ? trim($url) : '')
        ->filter(fn ($url) => $url !== '' && (str_starts_with($url, 'http') || str_starts_with($url, '/')))
        ->unique()
        ->values()
        ->all();

    $count = count($images);
@endphp

<div class="space-y-3">
    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
        {{ $count }} {{ $count === 1 ? 'photo' : 'photos' }} submitted
    </p>

    @if ($count === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">No photos uploaded for this listing.</p>
    @else
        <div class="flex flex-row flex-nowrap items-stretch gap-3 overflow-x-auto pb-1">
            @foreach ($images as $index => $image)
                <a
                    href="{{ $image }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="group relative block h-40 w-40 shrink-0 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:h-48 sm:w-48"
                >
                    <img
                        src="{{ $image }}"
                        alt="Product photo {{ $index + 1 }} of {{ $count }}"
                        class="size-full object-cover transition duration-200 group-hover:scale-[1.02]"
                        loading="lazy"
                    />
                    <span class="absolute bottom-2 left-2 rounded-md bg-black/60 px-2 py-0.5 text-xs font-semibold text-white">
                        {{ $index + 1 }}/{{ $count }}
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
