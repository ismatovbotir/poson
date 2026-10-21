{{-- One level of the category tree. Siblings sort among themselves (wire:sort); children nest below. --}}
<ul @if ($canUpdate) wire:sort="reorder" @endif @class(['divide-y divide-slate-100', 'border-t border-slate-100' => $depth > 1])>
    @foreach ($nodes as $category)
        @php($children = $tree->get((string) $category->id, collect()))
        <li wire:key="cat-{{ $category->id }}" @if ($canUpdate) wire:sort:item="{{ $category->id }}" @endif x-data="{ open: true }" data-category-id="{{ $category->id }}">
            @include('livewire.admin.categories.partials.row', [
                'category' => $category,
                'hasChildren' => $children->isNotEmpty(),
                'sortable' => $canUpdate,
                'first' => $loop->first,
                'last' => $loop->last,
                'depth' => $depth,
                'path' => null,
            ])

            @if ($children->isNotEmpty())
                <div x-show="open" x-collapse class="pl-6 sm:pl-10">
                    @include('livewire.admin.categories.partials.tree', ['nodes' => $children, 'depth' => $depth + 1])
                </div>
            @endif
        </li>
    @endforeach
</ul>
