@props(['color' => 'slate', 'dot' => false, 'pill' => false])

{{--
    Status badge. Colors follow the admin kit status map; enums pass $enum->color().
    color: brand · sky · violet · amber · red · slate · muted · ink · outline
--}}
<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 px-2 py-0.5 text-xs font-semibold',
    $pill ? 'rounded-full ring-1 ring-inset' : 'rounded-md',
    match ($color) {
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-600/20',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'red' => 'bg-red-50 text-danger ring-red-600/20',
        'muted' => 'bg-slate-100 text-slate-500 ring-slate-500/20',
        'ink' => 'bg-slate-900 text-white ring-slate-900',
        'outline' => 'bg-white text-slate-500 ring-1 ring-inset ring-slate-300',
        default => 'bg-slate-100 text-slate-700 ring-slate-500/20',
    },
]) }}>
    @if ($dot)<span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
