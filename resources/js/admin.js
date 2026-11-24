/*
 * Admin panel scripts (separate Vite entry). Alpine and its plugins (focus,
 * persist, sort, ...) ship with Livewire; this entry only adds the Tiptap
 * rich-text editor used by <x-admin.rich-text> (plan 03 D12).
 */
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import './livewire-throttle';

/**
 * Only https, mailto and site-relative ("/path") links, like the server-side
 * HtmlSanitizer (which adds rel="nofollow" to external links only).
 */
const allowedHref = (url) => /^(https:\/\/|mailto:|\/(?!\/))/i.test(url ?? '');

document.addEventListener('alpine:init', () => {
    /**
     * x-data="richText($wire.entangle('form.description.uz'))" on a wire:ignore element.
     * The editor instance stays outside Alpine's reactive state (proxies break Tiptap).
     */
    window.Alpine.data('richText', (model) => {
        let editor = null;

        return {
            value: model,
            tick: 0,

            init() {
                editor = new Editor({
                    element: this.$refs.editor,
                    content: this.value ?? '',
                    extensions: [
                        StarterKit.configure({
                            heading: { levels: [2, 3] },
                            code: false,
                            codeBlock: false,
                            blockquote: false,
                            horizontalRule: false,
                            strike: false,
                            underline: false,
                            link: {
                                openOnClick: false,
                                autolink: true,
                                defaultProtocol: 'https',
                                isAllowedUri: (url) => allowedHref(url),
                                HTMLAttributes: { rel: null, target: null },
                            },
                        }),
                    ],
                    editorProps: {
                        attributes: {
                            class: 'rich-text-content min-h-36 px-3 py-2.5 text-base outline-none sm:text-sm',
                            role: 'textbox',
                            'aria-multiline': 'true',
                            ...(this.$el.dataset.labelledby ? { 'aria-labelledby': this.$el.dataset.labelledby } : {}),
                        },
                    },
                    onUpdate: ({ editor: instance }) => {
                        this.value = instance.isEmpty ? '' : instance.getHTML();
                    },
                    onTransaction: () => {
                        this.tick++;
                    },
                });

                this.$watch('value', (value) => {
                    const html = value ?? '';

                    if (html !== (editor.isEmpty ? '' : editor.getHTML())) {
                        editor.commands.setContent(html, { emitUpdate: false });
                    }
                });
            },

            destroy() {
                editor?.destroy();
                editor = null;
            },

            isActive(name, attributes = {}) {
                this.tick;

                return editor?.isActive(name, attributes) ?? false;
            },

            run(command, ...args) {
                editor?.chain().focus()[command](...args).run();
            },

            link() {
                if (!editor) {
                    return;
                }

                const current = editor.getAttributes('link').href ?? 'https://';
                const url = window.prompt(this.$el.dataset.linkPrompt ?? 'URL', current);

                if (url === null) {
                    return;
                }

                const chain = editor.chain().focus().extendMarkRange('link');

                if (url.trim() === '' || !allowedHref(url.trim())) {
                    chain.unsetLink().run();

                    return;
                }

                chain.setLink({ href: url.trim() }).run();
            },
        };
    });
});

document.addEventListener('alpine:init', () => {
    /**
     * Admin menu accordion (layouts/admin.blade.php).
     * x-data="adminMenu('catalog')": only the current page's group starts open
     * (the server renders the same state, so there's no flash); opening a group
     * closes the others, clicking the open one closes it. Not persisted: every
     * page load (including wire:navigate, which re-renders the sidebar) starts
     * from the new page's group.
     */
    window.Alpine.data('adminMenu', (activeGroup = null) => ({
        open: activeGroup,

        init() {
            try {
                // Drop the per-group state the previous version kept.
                window.localStorage.removeItem('poson.admin.menu.open');
            } catch (error) {
                // Storage unavailable: nothing to clean up.
            }
        },

        isOpen(group) {
            return this.open === group;
        },

        toggle(group) {
            this.open = this.open === group ? null : group;
        },
    }));
});

document.addEventListener('alpine:init', () => {
    /**
     * Product form tabs (livewire/admin/products/form.blade.php). Alpine only:
     * the active tab is never sent to the server, and every panel stays mounted
     * (x-show) so the child Livewire components keep their state. The optional
     * URL hash (#seo) is checked against the constant key list.
     */
    window.Alpine.data('productTabs', (keys) => ({
        tab: 'main',
        keys,

        init() {
            this.fromHash();
            this.onHashChange = () => this.fromHash();
            window.addEventListener('hashchange', this.onHashChange);
        },

        destroy() {
            window.removeEventListener('hashchange', this.onHashChange);
        },

        fromHash() {
            this.show(window.location.hash.slice(1), false);
        },

        show(key, remember = true) {
            if (!this.keys.includes(key)) {
                return;
            }

            this.tab = key;

            if (remember) {
                window.history.replaceState(window.history.state, '', `#${key}`);
            }
        },

        /** ARIA tabs keyboard pattern: arrows wrap, Home/End, focus moves and activates. */
        key(event) {
            const last = this.keys.length - 1;
            const current = this.keys.indexOf(this.tab);
            const target = {
                ArrowRight: current === last ? 0 : current + 1,
                ArrowLeft: current === 0 ? last : current - 1,
                Home: 0,
                End: last,
            }[event.key];

            if (target === undefined) {
                return;
            }

            event.preventDefault();
            this.show(this.keys[target]);
            this.$nextTick(() => document.getElementById(`product-tab-${this.keys[target]}`)?.focus());
        },

        /** After a failed save: open the tab, focus its first invalid field, else the tab itself. */
        showErrors(key) {
            if (!this.keys.includes(key)) {
                return;
            }

            this.show(key, false);
            this.$nextTick(() => {
                const panel = document.getElementById(`product-panel-${key}`);
                const field = panel?.querySelector('[aria-invalid="true"]');

                (field ?? document.getElementById(`product-tab-${key}`))?.focus();
            });
        },
    }));

    /**
     * Admin sidebar rail (layouts/admin.blade.php). collapsed = icons only (>= lg).
     * Stored in localStorage 'poson.admin.sidebar' ('1' collapsed, '0' expanded);
     * read strictly and re-read on every wire:navigate, since the body is replaced.
     */
    window.Alpine.data('adminShell', () => ({
        nav: false,
        collapsed: false,

        init() {
            // The inline <head> script already set data-rail before first paint; re-apply
            // after wire:navigate (the body is re-initialised, the stored value is the truth).
            this.collapsed = this.readStored();
            this.applyRail();
        },

        readStored() {
            try {
                return window.localStorage.getItem('poson.admin.sidebar') === '1';
            } catch (error) {
                return false;
            }
        },

        applyRail() {
            document.documentElement.setAttribute('data-rail', this.collapsed ? 'true' : 'false');
        },

        toggleRail() {
            this.collapsed = !this.collapsed;
            document.documentElement.setAttribute('data-rail-anim', '');
            this.applyRail();

            try {
                window.localStorage.setItem('poson.admin.sidebar', this.collapsed ? '1' : '0');
            } catch (error) {
                // Storage unavailable: the rail still works for this page.
            }
        },
    }));
});
