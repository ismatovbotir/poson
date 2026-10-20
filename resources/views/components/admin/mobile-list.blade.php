{{--
    Phone/tablet variant of a table (below lg): one card per record.
    Put <article class="rounded-2xl border border-slate-200 bg-white p-3.5"> items in the slot.
--}}
<div {{ $attributes->class(['space-y-2.5 lg:hidden']) }}>
    {{ $slot }}
</div>
