{{-- Result of a connection test (safe message only). --}}
<p @class([
    'mt-3 flex items-start gap-1.5 rounded-lg px-3 py-2 text-sm',
    'bg-brand-50 text-brand-800' => $result['ok'],
    'bg-red-50 text-red-800' => ! $result['ok'],
]) role="status" data-test-result>
    <x-admin.icon :name="$result['ok'] ? 'check' : 'alert'" size="size-4" class="mt-0.5 shrink-0" />{{ $result['message'] }}
</p>
