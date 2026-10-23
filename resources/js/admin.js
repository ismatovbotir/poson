/*
 * Admin panel scripts (separate Vite entry). Alpine and its plugins (focus,
 * persist, sort, ...) ship with Livewire; this entry only adds the Tiptap
 * rich-text editor used by <x-admin.rich-text> (plan 03 D12).
 */
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';

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
    const MENU_STORAGE_KEY = 'poson.admin.menu.open';

    /**
     * Collapsible admin menu groups (layouts/admin.blade.php).
     * x-data="adminMenu('catalog')": the open state per group is kept in
     * localStorage (private mode / blocked storage just falls back to "open"),
     * and the group of the current page always opens.
     */
    window.Alpine.data('adminMenu', (activeGroup = null) => ({
        open: {},

        init() {
            try {
                const stored = JSON.parse(window.localStorage.getItem(MENU_STORAGE_KEY) ?? '{}');
                this.open = stored && typeof stored === 'object' ? stored : {};
            } catch (error) {
                this.open = {};
            }

            if (activeGroup) {
                this.open[activeGroup] = true;
            }
        },

        isOpen(group) {
            return this.open[group] ?? true;
        },

        toggle(group) {
            this.open[group] = !this.isOpen(group);

            try {
                window.localStorage.setItem(MENU_STORAGE_KEY, JSON.stringify(this.open));
            } catch (error) {
                // Storage unavailable: the state just isn't remembered.
            }
        },
    }));
});
