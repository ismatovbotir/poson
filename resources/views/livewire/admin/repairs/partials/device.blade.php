{{-- Device line of a repair: brand and model, or the type; serial number below. --}}
<span class="block truncate">{{ trim(($repair->device_brand ?? '').' '.($repair->device_model ?? '')) ?: $repair->device_type->label() }}</span>
@if ($repair->serial_number)<span class="block truncate font-mono text-xs text-slate-500">{{ $repair->serial_number }}</span>@endif
