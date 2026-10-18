{{-- Documentação do design system: texto, tema e componentes (com filtro). Dono edita aqui. --}}
@php
    $tokens = \App\Actions\Designs\SetDesignTokens::parse($artifact->design_tokens);
    $components = $artifact->orderedBoards()->get();
    $ref = app(\App\Designs\DesignSystems::class)->ref($artifact);
    $canEdit = auth()->user()?->can('update', $artifact) ?? false;
@endphp
<div x-data="wardSystemDocs(@js(['editUrl' => route('designs.edit', $artifact->uuid), 'docsUrl' => route('design-systems.docs', $artifact->uuid), 'csrf' => csrf_token(), 'title' => $artifact->title, 'description' => $artifact->description, 'docs' => $artifact->docs]))"
     x-on:keydown.escape.window="open = false" class="contents">
    <button type="button" x-on:click="open = true" class="ward-ds-open" aria-haspopup="dialog">
        <flux:icon.book-open variant="micro" /> Documentação
    </button>

    <div x-show="open" x-cloak class="fixed inset-0 z-40 flex justify-end bg-black/40" x-on:click.self="open = false">
        <section role="dialog" aria-modal="true" aria-label="Documentação do design system"
                 class="flex h-full w-full max-w-3xl flex-col overflow-hidden bg-white text-zinc-900 shadow-2xl dark:bg-zinc-900 dark:text-zinc-100">
            <header class="flex items-start gap-3 border-b border-zinc-200 p-5 dark:border-zinc-700">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Design system · <code class="normal-case">{{ $ref }}</code></p>
                    <h2 class="mt-1 text-2xl font-semibold" x-text="title"></h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300" x-show="description" x-text="description"></p>
                </div>
                @if ($canEdit)
                    <flux:button size="sm" x-on:click="editing = !editing" x-text="editing ? 'Ver' : 'Editar'">Editar</flux:button>
                @endif
                <flux:button size="sm" variant="ghost" icon="x-mark" aria-label="Fechar" x-on:click="open = false" />
            </header>

            <div class="min-h-0 flex-1 space-y-8 overflow-y-auto p-5">
                @if ($canEdit)
                    <form x-show="editing" x-on:submit.prevent="save" class="space-y-3">
                        <flux:input label="Título" x-model="form.title" required />
                        <flux:textarea label="Descrição" rows="2" x-model="form.description" />
                        <flux:textarea label="Documentação (markdown)" rows="16" x-model="form.docs" class="font-mono text-xs"
                            placeholder="## Princípios&#10;&#10;## Quando usar cada componente&#10;&#10;## Tom de voz" />
                        <div class="flex items-center gap-2">
                            <flux:button type="submit" variant="primary" x-bind:disabled="saving">Salvar</flux:button>
                            <span class="text-sm text-zinc-500" x-text="status"></span>
                        </div>
                    </form>
                @endif

                <article x-show="!editing" class="ward-ds-docs" x-html="html">{!! \App\Http\Controllers\DesignSystemController::markdown($artifact->docs) !!}</article>
                <p x-show="!editing && !html" class="text-sm text-zinc-500">Sem documentação ainda.{{ $canEdit ? ' Clique em Editar, ou peça à IA (save_design_system).' : '' }}</p>

                <section class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Tema (tokens)</h3>
                    @forelse ($tokens as $name => $value)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="size-6 shrink-0 rounded border border-zinc-300 dark:border-zinc-600" style="background: {{ preg_match('/^(#|rgb|hsl|oklch|oklab|lab|lch|color\()/i', $value) ? $value : 'transparent' }}"></span>
                            <code class="w-40 shrink-0 truncate">{{ $name }}</code>
                            <code class="truncate text-zinc-500">{{ $value }}</code>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">Sem tokens. Defina no painel Tema do canvas ou com set_design_tokens.</p>
                    @endforelse
                </section>

                <section class="space-y-3" x-data="{ q: '' }">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Componentes ({{ $components->count() }})</h3>
                        <flux:input size="sm" icon="magnifying-glass" x-model="q" placeholder="Filtrar componentes" class="max-w-56" aria-label="Filtrar componentes" />
                    </div>
                    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @forelse ($components as $component)
                            <div class="flex items-start gap-3 p-3 text-sm"
                                 x-show="!q || @js(mb_strtolower("{$component->name} {$component->title} {$component->description} {$component->tags}")).includes(q.toLowerCase())">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium">{{ $component->title ?: $component->name }} <code class="ml-1 text-xs text-zinc-500">{{ $component->name }}</code></p>
                                    @if ($component->description)
                                        <p class="mt-0.5 text-zinc-600 dark:text-zinc-300">{{ $component->description }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-zinc-500">{{ $component->w }}×{{ $component->h }}@if ($component->tags) · {{ $component->tags }}@endif</p>
                                    <code class="mt-1 block truncate text-xs text-zinc-500">&lt;ward-include ds="{{ $ref }}" name="{{ $component->name }}"&gt;&lt;/ward-include&gt;</code>
                                </div>
                            </div>
                        @empty
                            <p class="p-3 text-sm text-zinc-500">Nenhum componente ainda. Cada artboard deste canvas é um componente.</p>
                        @endforelse
                    </div>
                </section>
            </div>
        </section>
    </div>
</div>

<script>
    function wardSystemDocs(config) {
        return {
            open: false, editing: false, saving: false, status: '',
            title: config.title, description: config.description,
            html: null,
            form: { title: config.title, description: config.description ?? '', docs: config.docs ?? '' },
            init() { this.html = this.$el.querySelector('.ward-ds-docs')?.innerHTML.trim() || ''; },
            async save() {
                this.saving = true;
                this.status = 'Salvando…';
                const response = await fetch(config.editUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                    body: JSON.stringify({ ops: [{ op: 'design.meta', title: this.form.title, description: this.form.description, docs: this.form.docs }] }),
                });
                const json = await response.json().catch(() => ({}));
                this.saving = false;
                if (!response.ok) { this.status = json.message || 'Não foi possível salvar.'; return; }
                const docs = await (await fetch(config.docsUrl, { headers: { Accept: 'application/json' } })).json();
                this.title = docs.title; this.description = docs.description; this.html = docs.html;
                this.status = 'Salvo.'; this.editing = false;
            },
        };
    }
</script>
