---
name: admin-livewire-crud
description: Pattern for building the custom admin panel with Livewire 4 + Tailwind 4 (no Filament). Covers layout, index tables with search/filter/sort/pagination, create/edit forms with Livewire Form objects, locale tabs, file uploads, bulk actions, flash/toasts and authorization. Use whenever creating or changing an /admin screen.
---

# Admin CRUD with Livewire 4 + Tailwind 4

## Routing

```php
// routes/admin.php, loaded with prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'admin'])
Route::get('/products', Admin\Products\Index::class)->name('products.index');
Route::get('/products/create', Admin\Products\Form::class)->name('products.create');
Route::get('/products/{product}/edit', Admin\Products\Form::class)->name('products.edit');
```

## Layout

- `resources/views/components/layouts/admin.blade.php` contains a sidebar, a topbar with a user menu and UI-locale switch, and a toast area.
- Sidebar items are filtered with `@can`. Groups: Dashboard · Catalog · Sales (Leads, Quotes, Orders, Customers, Payments) · Content (Pages, News, Banners) · Settings (Users, Roles, Integrations).
- Reusable Blade components live in `components/admin/`: `table`, `th-sortable`, `input`, `textarea`, `select`, `toggle`, `locale-tabs`, `money-input`, `image-upload`, `modal`, `confirm-button`, `badge` (for enum colors) and `page-header`.

## Index component

```php
#[Layout('components.layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url] public string $search = '';
    #[Url] public ?string $status = null;
    #[Url] public string $sortField = 'created_at';
    #[Url] public string $sortDirection = 'desc';

    public function mount(): void { $this->authorize('viewAny', Product::class); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function sortBy(string $field): void { /* whitelist fields! */ }

    public function delete(Product $product): void
    {
        $this->authorize('delete', $product);
        $product->delete();
        $this->dispatch('toast', message: __('admin.deleted'));
    }

    public function render() { /* query with eager loads, ->paginate(25) */ }
}
```

- Use `wire:model.live.debounce.300ms` for search. **Whitelist** sortable columns.
- Use `wire:confirm` or `<x-admin.confirm-button>` for destructive actions.

## Form component

- A single `Form` component handles both create and edit: `mount(?Product $product = null)`.
- State goes in a `Livewire\Form` subclass (`ProductForm`) with `setProduct()` and `rules()`. `save()` calls an Action.
- Translatable fields use `<x-admin.locale-tabs>` (see `multilingual-content`).
- Uploads use `WithFileUploads` with validation `image|max:4096` and are stored on the `public` disk under `products/{id}`.
- Money inputs show sums and store tiyin. Convert in the Form object, never in the view.
- After saving: toast, then `redirectRoute('admin.products.edit', $product, navigate: true)`.

## UX rules

- Use `wire:navigate` for admin links.
- Every list shows an empty state, and loading states use `wire:loading`.
- Enum status badges get their color from `$enum->color()`.
- Mobile: tables scroll horizontally and the sidebar collapses.
