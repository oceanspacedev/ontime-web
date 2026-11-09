<div class="space-y-4 p-2 text-center">
    @php
        $getPhotoUrl = fn (?string $path): ?string => \App\Support\StorageUrl::for($path);
        $checkInUrl = $getPhotoUrl($record->check_in_photo);
        $checkOutUrl = $getPhotoUrl($record->check_out_photo);
    @endphp

    @if($record->check_in_lat && $record->check_in_lng)
        <div class="p-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900 rounded-xl text-left text-xs space-y-2">
            <span class="font-bold text-amber-900 dark:text-amber-200 block mb-1">📍 Detail Koordinat GPS & Lokasi Map:</span>
            <div class="flex items-center justify-between">
                <span>Check In: <strong>{{ $record->check_in_lat }}, {{ $record->check_in_lng }}</strong></span>
                <a href="https://www.openstreetmap.org/?mlat={{ $record->check_in_lat }}&mlon={{ $record->check_in_lng }}#map=17/{{ $record->check_in_lat }}/{{ $record->check_in_lng }}" 
                   target="_blank" 
                   class="px-2 py-1 bg-amber-600 text-white rounded font-semibold hover:bg-amber-700 transition inline-flex items-center gap-1">
                    Buka OpenStreetMap ↗
                </a>
            </div>
            @if($record->check_out_lat && $record->check_out_lng)
                <div class="flex items-center justify-between pt-2 border-t border-amber-200 dark:border-amber-800">
                    <span>Check Out: <strong>{{ $record->check_out_lat }}, {{ $record->check_out_lng }}</strong></span>
                    <a href="https://www.openstreetmap.org/?mlat={{ $record->check_out_lat }}&mlon={{ $record->check_out_lng }}#map=17/{{ $record->check_out_lat }}/{{ $record->check_out_lng }}" 
                       target="_blank" 
                       class="px-2 py-1 bg-amber-600 text-white rounded font-semibold hover:bg-amber-700 transition inline-flex items-center gap-1">
                        Buka OpenStreetMap ↗
                    </a>
                </div>
            @endif
        </div>
    @endif

    @if($checkInUrl)
        <div class="space-y-1">
            <span class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Foto Check-In ({{ $record->check_in ? $record->check_in->format('H:i:s') . ' WIB' : '-' }})</span>
            <img src="{{ $checkInUrl }}" class="max-h-72 w-auto mx-auto rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm" />
        </div>
    @endif

    @if($checkOutUrl)
        <div class="space-y-1">
            <span class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Foto Check-Out ({{ $record->check_out ? $record->check_out->format('H:i:s') . ' WIB' : '-' }})</span>
            <img src="{{ $checkOutUrl }}" class="max-h-72 w-auto mx-auto rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm" />
        </div>
    @endif
</div>
