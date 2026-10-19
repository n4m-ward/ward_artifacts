// Ward Design: canvas ao vivo (pan/zoom, Play do protótipo, setas das ligações, atualização por consulta curta).
// Na página do design, lê o estado inicial de <script id="ward-design-config" type="application/json">.
// O MCP App (resources/views/mcp/design-app.blade.php) embute este mesmo arquivo e cria o
// canvas com window.WardDesignCanvas, trocando a fonte do ao vivo e reagindo à seleção.
// Sem imports: o arquivo roda como está, também embutido no app.

const MIN_SCALE = 0.05;
const MAX_SCALE = 4;
const VISIBLE_MARGIN = 600; // px de tela além da borda em que o iframe já carrega
const POLL_VISIBLE = 1500;
const POLL_HIDDEN = 10000;
const MOBILE_BREAKPOINT = 640;

const NOTE_COLORS = {
    gray: '#E4E4E7', yellow: '#FEF3C7', orange: '#FFEDD5', red: '#FEE2E2', green: '#DCFCE7',
    teal: '#CCFBF1', blue: '#DBEAFE', purple: '#EDE9FE', pink: '#FCE7F3',
};

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else if (key === 'style') Object.assign(node.style, value);
        else node.setAttribute(key, value === true ? '' : value);
    }
    for (const child of [].concat(children)) if (child) node.append(child);
    return node;
}

function storage(key, value) {
    try {
        if (value === undefined) return JSON.parse(localStorage.getItem(key));
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        return null;
    }
    return null;
}

class DesignCanvas {
    /**
     * options.poll(revision): estado novo, ou null se nada mudou (padrão: fetch em config.liveUrl).
     * options.onSelect(board | null): artboard selecionado ou aberto em foco.
     * options.isActive(): false pausa o ao vivo (ex.: app fora da tela).
     * options.persistView: guarda zoom/posição no localStorage (padrão: true).
     * options.mobileBreakpoint: abaixo desta largura vira lista (padrão: 640).
     * options.listMaxHeight: altura máxima de cada artboard na lista (padrão: sem limite).
     *
     * Um editor (resources/js/design-editor.js) pode se ligar em canvas.editor:
     * pointerDown(event) devolve true quando trata o clique; canvas.hold pausa o
     * ao vivo durante um arraste ou um envio.
     */
    constructor(root, config, options = {}) {
        this.root = root;
        this.config = config;
        this.options = {
            persistView: true, pollVisible: POLL_VISIBLE, mobileBreakpoint: MOBILE_BREAKPOINT, listMaxHeight: Infinity, ...options,
        };
        this.state = config.state;
        this.view = { x: 0, y: 0, scale: 1 };
        this.boards = new Map(); // id -> { data, node, frame, loaded }
        this.selected = null;
        this.focusIndex = null;
        this.viewKey = `ward-design-view:${config.design}`;
        this.editor = null;
        this.hold = false;

        this.viewport = root.querySelector('[data-canvas-viewport]');
        this.world = root.querySelector('[data-canvas-world]');
        this.notesLayer = root.querySelector('[data-canvas-notes]');
        this.boardsLayer = root.querySelector('[data-canvas-boards]');
        this.zoomLabel = root.querySelector('[data-zoom-label]');
        this.countLabel = document.querySelector('[data-board-count]');
        this.focus = document.querySelector('[data-focus]');
        this.empty = root.querySelector('[data-empty]');
        this.banner = root.querySelector('[data-banner]');
        this.list = root.querySelector('[data-canvas-list]');
        this.pageKey = `ward-design-page:${config.design}`;
        this.page = storage(this.pageKey) ?? null;
        this.filter = '';
        this.showLinks = Boolean(storage(`ward-design-links:${config.design}`));
        this.buildTopBar();
        this.linksToggle.setAttribute('aria-pressed', String(this.showLinks));

        this.renderAll();
        this.bindCanvas();
        this.bindFocus();
        this.bindKeyboard();
        this.bindMessages();

        const saved = this.isMobile() || !this.options.persistView ? null : storage(this.viewKey);
        // Enquanto a pessoa não mexe no zoom/posição, o canvas se reenquadra quando chega artboard novo.
        this.userMoved = Boolean(saved);
        if (saved && typeof saved.scale === 'number' && saved.scale > 0) {
            this.view = saved;
            this.applyView();
        } else {
            this.fit();
        }

        this.schedulePoll();
        window.addEventListener('resize', () => this.applyView());
    }

    // ---------- páginas e filtro ----------

    pages() {
        return this.state.pages ?? [];
    }

    /** Página de um artboard/nota (sem página = a primeira). */
    pageOf(item) {
        const pages = this.pages();
        if (!pages.length) return null;
        return pages.some((page) => page.key === item.page) ? item.page : pages[0].key;
    }

    currentPage() {
        const pages = this.pages();
        if (!pages.length) return null;
        return pages.some((page) => page.key === this.page) ? this.page : pages[0].key;
    }

    inPage(item) {
        return this.pageOf(item) === this.currentPage();
    }

    pageBoards() {
        return this.state.boards.filter((board) => this.inPage(board));
    }

    setPage(key) {
        this.page = key;
        storage(this.pageKey, key);
        this.select(null);
        this.renderAll();
        this.fit();
    }

    matches(board) {
        if (!this.filter) return true;
        const text = `${board.name} ${board.title ?? ''}`.toLowerCase();
        return this.filter.split(/\s+/).every((word) => text.includes(word));
    }

    buildTopBar() {
        this.pagesBar = el('nav', { class: 'ward-pages', 'aria-label': 'Páginas do design', hidden: true });
        const search = el('input', { type: 'search', class: 'ward-filter', placeholder: 'Filtrar artboards', 'aria-label': 'Filtrar artboards por nome' });
        search.addEventListener('input', () => {
            this.filter = search.value.trim().toLowerCase();
            this.renderAll();
        });
        search.addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Enter') this.fitMatches();
            if (event.key === 'Escape') {
                search.value = '';
                this.filter = '';
                this.renderAll();
                search.blur();
            }
        });
        this.filterInput = search;
        this.filterCount = el('span', { class: 'ward-filter-count', hidden: true });
        const play = el('button', { type: 'button', class: 'ward-play-start', title: 'Play: navegar pelas telas como no aparelho (começa pela selecionada)', text: '▶ Play' });
        play.addEventListener('click', () => {
            const id = this.selected ?? this.ordered()[0]?.id;
            if (id) this.openFocus(id);
        });
        this.linksToggle = el('button', { type: 'button', class: 'ward-links-toggle', 'aria-pressed': 'false', title: 'Mostrar as ligações do protótipo entre as telas', text: 'Ligações' });
        this.linksToggle.addEventListener('click', () => this.toggleLinks());
        this.root.append(this.pagesBar, el('div', { class: 'ward-filter-box' }, [play, this.linksToggle, search, this.filterCount]));
    }

    renderPages() {
        const pages = this.pages();
        const current = this.currentPage();
        const tabs = pages.map((page) => {
            const count = this.state.boards.filter((board) => this.pageOf(board) === page.key).length;
            const tab = el('button', {
                type: 'button', class: 'ward-page-tab', 'data-page': page.key, 'aria-current': page.key === current ? 'page' : null,
                text: page.name, title: `${page.name} · ${count} artboard(s)`,
            });
            tab.addEventListener('click', () => this.setPage(page.key));
            return tab;
        });
        this.pagesBar.replaceChildren(...tabs);
        this.editor?.decoratePages?.(this.pagesBar);
        this.pagesBar.hidden = this.pagesBar.children.length === 0;
    }

    fitMatches() {
        const hits = this.pageBoards().filter((board) => this.matches(board));
        if (!hits.length) return;
        this.fit(hits);
    }

    // ---------- render ----------

    renderAll() {
        this.renderPages();
        this.renderNotes();
        const ids = new Set(this.state.boards.map((board) => board.id));

        for (const [id, entry] of this.boards) {
            if (!ids.has(id)) {
                entry.node.remove();
                entry.listNode?.remove();
                this.boards.delete(id);
            }
        }

        let added = false;
        for (const data of this.state.boards) {
            const entry = this.boards.get(data.id);
            if (!entry) {
                this.createBoard(data);
                added = true;
            } else this.updateBoard(entry, data);
            const node = this.boards.get(data.id).node;
            node.hidden = !this.inPage(data);
            node.classList.toggle('is-filtered-out', !this.matches(data));
        }
        if (this.filterCount) {
            const hits = this.pageBoards().filter((board) => this.matches(board)).length;
            this.filterCount.hidden = !this.filter;
            this.filterCount.textContent = `${hits} de ${this.pageBoards().length}`;
        }
        if (added && this.userMoved === false) this.fit();

        if (this.countLabel) {
            const n = this.state.boards.length;
            this.countLabel.textContent = `${n} artboard${n === 1 ? '' : 's'}`;
        }

        if (this.empty) this.empty.hidden = this.state.boards.length > 0;
        this.loadVisible();
        this.renderLinks();
        this.editor?.afterRender();
    }

    renderNotes() {
        // A nota em edição no editor não é recriada (perderia o cursor).
        const editing = this.editor?.editingNote ?? null;
        this.notesLayer.replaceChildren(...this.state.notes.filter((note) => this.inPage(note)).map((note) => {
            if (editing && editing.dataset.note === note.key) return editing;
            const classes = [
                note.kind === 'title' ? 'ward-note-title' : 'ward-note-sticky',
                note.size ? `is-size-${note.size}` : '',
                note.bold ? 'is-bold' : '',
            ].join(' ').trim();
            if (note.kind === 'title') {
                return el('div', {
                    class: classes,
                    text: note.text,
                    'data-note': note.key,
                    style: { left: `${note.x}px`, top: `${note.y}px`, maxWidth: note.w ? `${note.w}px` : '2400px' },
                });
            }
            return el('div', {
                class: classes,
                text: note.text,
                'data-note': note.key,
                style: {
                    left: `${note.x}px`, top: `${note.y}px`, width: `${note.w || 260}px`,
                    background: NOTE_COLORS[note.color] || NOTE_COLORS.yellow,
                },
            });
        }));
    }

    /** Ponto da tela (clientX/Y) em coordenadas do canvas. */
    toWorld(clientX, clientY) {
        const rect = this.viewport.getBoundingClientRect();
        return {
            x: (clientX - rect.left - this.view.x) / this.view.scale,
            y: (clientY - rect.top - this.view.y) / this.view.scale,
        };
    }

    createBoard(data) {
        const frame = el('iframe', {
            title: data.title || data.name,
            sandbox: this.config.sandbox,
            allow: this.config.allow,
            referrerpolicy: 'no-referrer',
            tabindex: '-1',
        });
        const label = el('button', { type: 'button', class: 'ward-board-name', text: data.title || data.name });
        const meta = el('span', { class: 'ward-board-meta' });
        const caption = el('div', { class: 'ward-board-caption' }, [label, meta]);
        const flash = el('span', { class: 'ward-board-flash', text: 'atualizado agora', hidden: true });
        const error = el('button', { type: 'button', class: 'ward-board-error', text: 'Erro no script', hidden: true });
        const play = el('button', { type: 'button', class: 'ward-board-play', title: 'Play: ver esta tela funcionando (Enter)', 'aria-label': `Play em ${data.title || data.name}`, text: '▶' });
        caption.prepend(play);
        caption.append(error);
        play.addEventListener('click', (event) => {
            event.stopPropagation();
            this.openFocus(data.id);
        });
        const node = el('div', { class: 'ward-board', 'data-board': data.id }, [caption, frame, flash]);
        error.addEventListener('click', (event) => {
            event.stopPropagation();
            this.editor?.showBoardError(data.id, event);
        });

        label.addEventListener('click', (event) => {
            event.stopPropagation();
            this.openFocus(data.id);
        });

        this.boardsLayer.append(node);
        const entry = { data, node, frame, label, meta, flash, error, errors: [], loaded: false };
        this.boards.set(data.id, entry);
        this.updateBoard(entry, data, true);

        if (this.list) {
            entry.listNode = this.createListItem(entry);
            this.list.append(entry.listNode);
        }
    }

    updateBoard(entry, data, initial = false) {
        // version muda também quando um componente usado ou os tokens do design mudam.
        const changed = !initial && (data.version ?? data.revision) !== (entry.data.version ?? entry.data.revision);
        entry.data = data;

        Object.assign(entry.node.style, {
            left: `${data.x}px`, top: `${data.y}px`, width: `${data.w}px`, height: `${data.h}px`,
        });
        Object.assign(entry.frame.style, { width: `${data.w}px`, height: `${data.h}px` });
        entry.label.textContent = data.title || data.name;
        entry.meta.textContent = `${data.w}×${data.h}${data.interactive ? ' · Play' : ''}`;
        entry.node.classList.toggle('is-selected', this.selected === data.id);
        entry.node.classList.toggle('is-transparent', Boolean(data.transparent) || entry.painted === false);
        entry.listBox?.classList.toggle('is-transparent', Boolean(data.transparent));

        // Mudança feita pela própria pessoa no editor: troca sem o aviso "atualizado agora".
        const quiet = entry.quietRevision !== undefined && data.revision <= entry.quietRevision;
        if (changed && entry.loaded) {
            this.swapFrame(entry, data.url);
            if (!quiet) this.flash(entry);
        } else if (changed && !quiet) {
            this.flash(entry);
        }

        if (entry.listNode) this.updateListItem(entry);

        if (this.focusIndex !== null && this.currentFocusId() === data.id && changed) {
            this.renderFocus();
        }
    }

    /** Carrega a versão nova por baixo e só troca quando ela terminou (sem piscar em branco). */
    swapFrame(entry, url) {
        this.clearErrors(entry);
        const next = entry.frame.cloneNode(false);
        next.removeAttribute('src');
        next.classList.add('is-loading');
        entry.nextFrame = next;
        next.addEventListener('load', () => {
            next.classList.remove('is-loading');
            entry.frame.remove();
            entry.frame = next;
            entry.nextFrame = null;
            this.editor?.frameSwapped(entry.data.id);
            this.queryLinks(entry);
        }, { once: true });
        next.src = url;
        entry.frame.after(next);
    }

    flash(entry) {
        entry.flash.hidden = false;
        entry.node.classList.add('is-updated');
        clearTimeout(entry.flashTimer);
        entry.flashTimer = setTimeout(() => {
            entry.flash.hidden = true;
            entry.node.classList.remove('is-updated');
        }, 4000);
    }

    // ---------- lista (celular) ----------

    createListItem(entry) {
        const frame = el('iframe', {
            title: entry.data.title || entry.data.name,
            sandbox: this.config.sandbox,
            allow: this.config.allow,
            referrerpolicy: 'no-referrer',
            tabindex: '-1',
            loading: 'lazy',
        });
        const box = el('div', { class: 'ward-list-box' }, [frame]);
        const button = el('button', { type: 'button', class: 'ward-list-open', text: 'Abrir' });
        const title = el('div', { class: 'ward-list-title' });
        const item = el('article', { class: 'ward-list-item' }, [
            el('div', { class: 'ward-list-head' }, [title, button]), box,
        ]);
        button.addEventListener('click', () => this.openFocus(entry.data.id));
        box.addEventListener('click', () => this.openFocus(entry.data.id));
        entry.listFrame = frame;
        entry.listBox = box;
        entry.listTitle = title;
        return item;
    }

    updateListItem(entry) {
        const { data } = entry;
        entry.listTitle.textContent = `${data.title || data.name} · ${data.w}×${data.h}`;
        const width = Math.max(1, (this.list.clientWidth || window.innerWidth) - 32);
        const scale = Math.min(1, width / data.w, this.options.listMaxHeight / data.h);
        Object.assign(entry.listBox.style, { width: `${Math.round(data.w * scale)}px`, height: `${Math.round(data.h * scale)}px` });
        Object.assign(entry.listFrame.style, {
            width: `${data.w}px`, height: `${data.h}px`, transform: `scale(${scale})`,
        });
        const version = String(data.version ?? data.revision);
        if (entry.listFrame.dataset.revision !== version) {
            entry.listFrame.src = data.url;
            entry.listFrame.dataset.revision = version;
        }
    }

    isMobile() {
        const mobile = window.innerWidth < this.options.mobileBreakpoint;
        this.root.classList.toggle('is-list', mobile);
        return mobile;
    }

    // ---------- setas das ligações ----------

    toggleLinks(on = !this.showLinks) {
        this.showLinks = on;
        storage(`ward-design-links:${this.config.design}`, on);
        this.linksToggle?.setAttribute('aria-pressed', String(on));
        if (on) for (const entry of this.boards.values()) this.queryLinks(entry);
        this.renderLinks();
    }

    /** Pergunta ao artboard carregado onde ficam as ligações (para a seta sair do botão certo). */
    async queryLinks(entry) {
        if (!this.showLinks || !entry.loaded || !(entry.data.links ?? []).length) return;
        const version = entry.data.version ?? entry.data.revision;
        const rects = await this.ask(entry.data.id, { ward: 'links' }, 1500);
        if (!Array.isArray(rects)) return;
        entry.linkRects = { version, rects };
        this.renderLinks();
    }

    renderLinks() {
        if (!this.linksLayer) {
            this.linksLayer = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            this.linksLayer.setAttribute('class', 'ward-links');
            this.world.append(this.linksLayer);
        }
        const svg = this.linksLayer;
        svg.replaceChildren();
        if (!this.showLinks || this.isMobile()) return;

        const inv = 1 / this.view.scale;
        const byName = new Map(this.pageBoards().map((board) => [board.name, board]));
        const node = (tag, attrs) => {
            const item = document.createElementNS('http://www.w3.org/2000/svg', tag);
            for (const [key, value] of Object.entries(attrs)) item.setAttribute(key, String(value));
            svg.append(item);
            return item;
        };

        for (const source of this.pageBoards()) {
            const entry = this.boards.get(source.id);
            const fresh = entry?.linkRects && entry.linkRects.version === (source.version ?? source.revision) ? entry.linkRects.rects : null;
            // Com o artboard carregado, uma seta por elemento; sem ele, uma por destino, saindo da borda.
            const links = fresh
                ? fresh.filter((link) => link.board).map((link) => ({ to: link.board, overlay: Boolean(link.overlay), rect: link }))
                : (source.links ?? []).map((link) => ({ to: link.to, overlay: link.overlay, rect: null }));

            for (const link of links) {
                const target = byName.get(link.to);
                if (!target || target.id === source.id) continue;
                const from = link.rect
                    ? { x: source.x + link.rect.x, y: source.y + link.rect.y, w: link.rect.w, h: link.rect.h }
                    : { x: source.x, y: source.y, w: source.w, h: source.h };
                if (link.rect) {
                    node('rect', { x: from.x, y: from.y, width: from.w, height: from.h, rx: 4 * inv, 'stroke-width': 1.5 * inv });
                }

                // Sai pelo lado voltado para o destino e chega na borda mais próxima dele.
                const right = target.x >= source.x + source.w;
                const left = target.x + target.w <= source.x;
                let start;
                let end;
                if (right || left) {
                    start = { x: right ? (link.rect ? from.x + from.w : source.x + source.w) : (link.rect ? from.x : source.x), y: from.y + from.h / 2 };
                    end = { x: right ? target.x : target.x + target.w, y: target.y + Math.min(target.h / 2, 120) };
                } else {
                    const below = target.y >= source.y + source.h;
                    start = { x: from.x + from.w / 2, y: below ? (link.rect ? from.y + from.h : source.y + source.h) : (link.rect ? from.y : source.y) };
                    end = { x: target.x + target.w / 2, y: below ? target.y : target.y + target.h };
                }
                const horizontal = right || left;
                const bend = Math.max(60 * inv, Math.abs(horizontal ? end.x - start.x : end.y - start.y) / 2);
                const dir = horizontal ? Math.sign(end.x - start.x) || 1 : Math.sign(end.y - start.y) || 1;
                const c1 = horizontal ? `${start.x + bend * dir} ${start.y}` : `${start.x} ${start.y + bend * dir}`;
                const c2 = horizontal ? `${end.x - bend * dir} ${end.y}` : `${end.x} ${end.y - bend * dir}`;
                node('path', {
                    d: `M ${start.x} ${start.y} C ${c1}, ${c2}, ${end.x} ${end.y}`,
                    'stroke-width': 2 * inv, class: link.overlay ? 'is-overlay' : '',
                });
                const size = 10 * inv;
                const head = horizontal
                    ? `${end.x} ${end.y} ${end.x - size * dir} ${end.y - size * 0.6} ${end.x - size * dir} ${end.y + size * 0.6}`
                    : `${end.x} ${end.y} ${end.x - size * 0.6} ${end.y - size * dir} ${end.x + size * 0.6} ${end.y - size * dir}`;
                node('polygon', { points: head, class: 'ward-link-head' });
            }
        }
    }

    // ---------- pan / zoom ----------

    applyView() {
        const { x, y, scale } = this.view;
        this.world.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
        this.world.style.setProperty('--inv', String(1 / scale));
        if (this.zoomLabel) this.zoomLabel.textContent = `${Math.round(scale * 100)}%`;
        this.loadVisible();
        if (this.showLinks) this.renderLinks();
        this.editor?.afterView();
        if (this.isMobile()) {
            // No celular o canvas fica oculto: não salva uma visualização sem sentido.
            for (const entry of this.boards.values()) if (entry.listNode) this.updateListItem(entry);
            return;
        }
        // Só guarda a visualização que a pessoa escolheu (não o enquadramento automático).
        if (!this.options.persistView || !this.userMoved) return;
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => storage(this.viewKey, this.view), 300);
    }

    zoomAt(factor, clientX, clientY) {
        const rect = this.viewport.getBoundingClientRect();
        const px = (clientX ?? rect.left + rect.width / 2) - rect.left;
        const py = (clientY ?? rect.top + rect.height / 2) - rect.top;
        const next = Math.min(MAX_SCALE, Math.max(MIN_SCALE, this.view.scale * factor));
        const ratio = next / this.view.scale;
        this.view = { scale: next, x: px - (px - this.view.x) * ratio, y: py - (py - this.view.y) * ratio };
        this.applyView();
    }

    setScale(scale) {
        this.zoomAt(scale / this.view.scale);
    }

    bounds(boards = null) {
        const items = [
            ...(boards ?? this.pageBoards()).map((b) => [b.x, b.y, b.x + b.w, b.y + b.h]),
            ...(boards ? [] : this.state.notes.filter((n) => this.inPage(n)).map((n) => [n.x, n.y, n.x + (n.w || 260), n.y + 100])),
        ];
        if (!items.length) return null;
        return items.reduce((acc, [a, b, c, d]) => [
            Math.min(acc[0], a), Math.min(acc[1], b), Math.max(acc[2], c), Math.max(acc[3], d),
        ], [Infinity, Infinity, -Infinity, -Infinity]);
    }

    fit(boards = null) {
        const box = this.bounds(boards);
        const rect = this.viewport.getBoundingClientRect();
        if (!box || !rect.width) {
            this.view = { x: 40, y: 40, scale: 1 };
            return this.applyView();
        }
        const pad = 64;
        const w = box[2] - box[0];
        const h = box[3] - box[1];
        const scale = Math.min(1, Math.max(MIN_SCALE, Math.min((rect.width - pad * 2) / w, (rect.height - pad * 2) / h)));
        this.view = {
            scale,
            x: (rect.width - w * scale) / 2 - box[0] * scale,
            y: (rect.height - h * scale) / 2 - box[1] * scale,
        };
        this.applyView();
    }

    loadVisible() {
        if (this.isMobile()) return;
        const rect = this.viewport.getBoundingClientRect();
        const { x, y, scale } = this.view;
        for (const entry of this.boards.values()) {
            if (entry.loaded || !this.inPage(entry.data)) continue;
            const { data } = entry;
            const left = data.x * scale + x;
            const top = data.y * scale + y;
            const visible = left < rect.width + VISIBLE_MARGIN && left + data.w * scale > -VISIBLE_MARGIN
                && top < rect.height + VISIBLE_MARGIN && top + data.h * scale > -VISIBLE_MARGIN;
            if (visible) {
                entry.frame.addEventListener('load', () => this.queryLinks(entry), { once: true });
                entry.frame.src = data.url;
                entry.loaded = true;
            }
        }
    }

    bindCanvas() {
        const pointers = new Map();
        let drag = null;
        let pinch = null;

        this.viewport.addEventListener('pointerdown', (event) => {
            if (this.editor?.pointerDown(event)) return;
            if (event.target.closest('button, a')) return;
            this.viewport.setPointerCapture(event.pointerId);
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            if (pointers.size === 1) {
                drag = { x: event.clientX, y: event.clientY, vx: this.view.x, vy: this.view.y, moved: false };
            } else if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y), scale: this.view.scale };
                drag = null;
            }
        });

        this.viewport.addEventListener('pointermove', (event) => {
            if (!pointers.has(event.pointerId)) return;
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            if (pinch && pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                const distance = Math.hypot(a.x - b.x, a.y - b.y);
                this.zoomAt((pinch.scale * distance / pinch.distance) / this.view.scale, (a.x + b.x) / 2, (a.y + b.y) / 2);
                return;
            }
            if (!drag) return;
            const dx = event.clientX - drag.x;
            const dy = event.clientY - drag.y;
            if (!drag.moved && Math.hypot(dx, dy) < 4) return;
            drag.moved = true;
            this.userMoved = true;
            this.viewport.classList.add('is-panning');
            this.view.x = drag.vx + dx;
            this.view.y = drag.vy + dy;
            this.applyView();
        });

        const end = (event) => {
            if (!pointers.has(event.pointerId)) return;
            pointers.delete(event.pointerId);
            if (pointers.size < 2) pinch = null;
            if (drag && !drag.moved && event.type === 'pointerup') {
                const board = event.target.closest('[data-board]');
                this.select(board ? Number(board.dataset.board) : null);
            }
            if (pointers.size === 0) {
                drag = null;
                this.viewport.classList.remove('is-panning');
            }
        };
        this.viewport.addEventListener('pointerup', end);
        this.viewport.addEventListener('pointercancel', end);

        this.viewport.addEventListener('dblclick', (event) => {
            const board = event.target.closest('[data-board]');
            if (board) this.openFocus(Number(board.dataset.board));
        });

        this.viewport.addEventListener('wheel', (event) => {
            event.preventDefault();
            this.userMoved = true;
            if (event.ctrlKey || event.metaKey) {
                this.zoomAt(Math.exp(-event.deltaY * 0.01), event.clientX, event.clientY);
            } else {
                this.view.x -= event.deltaX;
                this.view.y -= event.deltaY;
                this.applyView();
            }
        }, { passive: false });

        this.root.querySelector('[data-zoom-toolbar]')?.addEventListener('click', (event) => {
            const action = event.target.closest('[data-zoom]')?.dataset.zoom;
            if (action) this.userMoved = action !== 'fit';
            if (action === 'in') this.zoomAt(1.25);
            if (action === 'out') this.zoomAt(0.8);
            if (action === 'reset') this.setScale(1);
            if (action === 'fit') this.fit();
        });
    }

    select(id) {
        const changed = this.selected !== id;
        this.selected = id;
        for (const [boardId, entry] of this.boards) entry.node.classList.toggle('is-selected', boardId === id);
        if (changed) this.options.onSelect?.(this.boards.get(id)?.data ?? null);
        this.editor?.afterSelect(id);
    }

    /** Estado novo vindo de fora (resultado de uma ferramenta no MCP App, ou do editor). */
    setState(state, force = false) {
        if (!state || (!force && state.revision === this.state.revision)) return;
        this.state = state;
        this.renderAll();
    }

    destroy() {
        this.destroyed = true;
        clearTimeout(this.pollTimer);
    }

    // ---------- Play (protótipo) ----------
    // O artboard abre no tamanho dele (largura × altura reais, só reduzido para caber), dentro de uma
    // moldura de celular, tablet ou navegador conforme o tamanho. Ligações do protótipo (runtime.js)
    // trocam de tela com transição, abrem telas por cima e voltam pelo histórico.

    ordered() {
        return this.pageBoards();
    }

    boardById(id) {
        return this.state.boards.find((board) => board.id === id) ?? null;
    }

    currentFocusId() {
        return this.play?.current ?? null;
    }

    /** Começa o Play nesta tela (o histórico recomeça). */
    openFocus(id) {
        if (!this.boardById(id)) return;
        this.play = { start: id, current: id, stack: [], overlays: [], transition: null };
        this.focus.hidden = false;
        document.body.style.overflow = 'hidden';
        this.showPlayBoard(id, null);
    }

    closeFocus() {
        this.focusIndex = null;
        this.play = null;
        this.focus.hidden = true;
        this.focus.querySelector('[data-focus-stage]').replaceChildren();
        document.body.style.overflow = '';
    }

    stepFocus(delta) {
        const boards = this.ordered();
        if (!this.play || !boards.length) return;
        const index = (Math.max(0, boards.findIndex((board) => board.id === this.play.current)) + delta + boards.length) % boards.length;
        this.playGo(boards[index].id, 'none');
    }

    /** Vai para outra tela guardando a atual no histórico. */
    playGo(id, transition) {
        if (!this.play || id === this.play.current) return;
        this.play.stack.push(this.play.current);
        this.play.overlays = [];
        this.showPlayBoard(id, transition ?? 'slide');
    }

    playBack(transition) {
        if (!this.play) return;
        if (this.play.overlays.length) return this.playCloseOverlay();
        const previous = this.play.stack.pop();
        if (previous === undefined) return this.flashPlayHint('Esta é a primeira tela do caminho.');
        this.showPlayBoard(previous, transition === 'none' ? 'none' : 'back');
    }

    playRestart() {
        if (!this.play) return;
        this.play.stack = [];
        this.play.overlays = [];
        this.showPlayBoard(this.play.start, 'none');
    }

    playOverlay(id, transition) {
        if (!this.play) return;
        this.play.overlays.push(id);
        this.renderOverlays(transition);
    }

    playCloseOverlay() {
        if (!this.play?.overlays.length) return;
        this.play.overlays.pop();
        this.renderOverlays(null);
    }

    /** Celular, tablet, navegador ou nada (posts, banners, componentes). */
    deviceOf(data) {
        if (data.transparent) return 'none';
        if (data.kind === 'page') return 'browser';
        const { w, h } = data;
        if (w <= 500 && h >= w * 1.3) return 'phone';
        const short = Math.min(w, h);
        const long = Math.max(w, h);
        if (short >= 600 && short <= 1100 && long / short >= 1.2 && long / short <= 1.7 && long <= 1400) return 'tablet';
        if (w >= 1024 && h >= 560 && w > h) return 'browser';
        return 'none';
    }

    playPrefs() {
        return { frame: storage('ward-play-frame') ?? true, real: storage('ward-play-real') ?? false };
    }

    /** Tamanho da "janela" da tela: telas compridas e páginas rolam dentro dela, como no aparelho. */
    viewportOf(data, device, availableHeight) {
        if (data.kind === 'page') return { w: data.w, h: Math.max(320, Math.min(data.h, Math.round(availableHeight))) };
        if (device === 'phone' && data.h > Math.round(data.w * 2.4)) return { w: data.w, h: Math.round(data.w * 2.165) };
        return { w: data.w, h: data.h };
    }

    showPlayBoard(id, transition) {
        const data = this.boardById(id);
        if (!data || !this.play) return;
        const previous = this.boardById(this.play.current);
        this.play.current = id;
        this.focusIndex = Math.max(0, this.ordered().findIndex((board) => board.id === id));
        this.select(id);
        this.renderPlayBar(data);

        // Mesmo aparelho e mesmo tamanho: troca só o conteúdo, com transição.
        const sameShell = this.playShell && previous && transition && transition !== 'none'
            && this.playShell.dataset.size === `${data.kind}:${data.w}x${data.h}` && this.focus.contains(this.playShell);
        if (sameShell) {
            this.swapPlayScreen(data, transition);
            return;
        }
        this.renderPlayShell(data);
    }

    renderFocus() {
        if (!this.play) return;
        const data = this.boardById(this.play.current);
        if (!data) return this.closeFocus();
        this.renderPlayBar(data);
        this.renderPlayShell(data);
    }

    playFrame(data) {
        const frame = el('iframe', {
            src: data.url,
            title: data.title || data.name,
            sandbox: this.config.sandbox,
            allow: this.config.allow,
            referrerpolicy: 'no-referrer',
        });
        frame.addEventListener('load', () => {
            frame.contentWindow?.postMessage({ ward: 'play' }, this.config.contentOrigin);
            if (this.play?.hotspots) frame.contentWindow?.postMessage({ ward: 'hotspots', on: true }, this.config.contentOrigin);
        });
        return frame;
    }

    renderPlayShell(data) {
        const stage = this.focus.querySelector('[data-focus-stage]');
        const prefs = this.playPrefs();
        const device = prefs.frame ? this.deviceOf(data) : 'none';
        const rect = stage.getBoundingClientRect();
        const pad = 32;
        const chrome = { phone: [24, 24], tablet: [32, 32], browser: [2, 38], none: [0, 0] }[device];

        // Escala pela largura; a altura da janela de uma página é o que sobra na tela.
        let scale = prefs.real ? 1 : Math.min(1, (rect.width - pad * 2 - chrome[0]) / data.w);
        const view = this.viewportOf(data, device, (rect.height - pad * 2 - chrome[1]) / scale);
        if (!prefs.real && data.kind !== 'page') scale = Math.min(scale, (rect.height - pad * 2 - chrome[1]) / view.h);
        scale = Math.max(0.1, scale);

        const screen = el('div', { class: `ward-play-screen${data.transparent ? ' is-transparent' : ''}`, style: { width: `${view.w}px`, height: `${view.h}px` } });
        const layer = el('div', { class: 'ward-play-layer' }, [this.playFrame(data)]);
        Object.assign(layer.firstChild.style, { width: `${view.w}px`, height: `${view.h}px` });
        screen.append(layer);

        const children = device === 'browser'
            ? [el('div', { class: 'ward-device-bar' }, [el('span'), el('span'), el('span'), el('em', { text: data.title || data.name })]), screen]
            : [screen];
        const shell = el('div', { class: `ward-device is-${device}`, 'data-size': `${data.kind}:${data.w}x${data.h}` }, children);
        shell.style.zoom = String(scale);

        stage.classList.toggle('is-real', prefs.real);
        stage.replaceChildren(shell, el('p', { class: 'ward-play-toast', role: 'status', hidden: true }));
        this.playShell = shell;
        this.playScreen = screen;
        this.renderOverlays(null);
    }

    /** Carrega a tela nova por cima da atual e só anima quando ela terminou de carregar. */
    swapPlayScreen(data, transition) {
        const screen = this.playScreen;
        const old = [...screen.querySelectorAll(':scope > .ward-play-layer')];
        const frame = this.playFrame(data);
        Object.assign(frame.style, { width: screen.style.width, height: screen.style.height });
        const layer = el('div', { class: 'ward-play-layer is-entering' }, [frame]);
        screen.querySelectorAll(':scope > .ward-play-overlay').forEach((node) => node.remove());
        screen.append(layer);

        let done = false;
        const reveal = () => {
            if (done) return;
            done = true;
            layer.classList.remove('is-entering');
            const keyframes = {
                slide: [{ transform: 'translateX(100%)' }, { transform: 'translateX(0)' }],
                back: [{ transform: 'translateX(-30%)', opacity: 0.4 }, { transform: 'translateX(0)', opacity: 1 }],
                'slide-up': [{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }],
                fade: [{ opacity: 0 }, { opacity: 1 }],
            }[transition];
            const finish = () => old.forEach((node) => node.remove());
            if (!keyframes || !layer.animate) return finish();
            layer.animate(keyframes, { duration: 260, easing: 'cubic-bezier(.2,.8,.2,1)' }).finished.then(finish, finish);
        };
        frame.addEventListener('load', () => setTimeout(reveal, 30), { once: true });
        setTimeout(reveal, 1500);
    }

    renderOverlays(transition) {
        const screen = this.playScreen;
        if (!screen || !this.play) return;
        const existing = [...screen.querySelectorAll(':scope > .ward-play-overlay')];
        const wanted = this.play.overlays;

        // Remove as que saíram (fechar) e acrescenta só a nova (abrir), sem recarregar as outras.
        existing.slice(wanted.length).forEach((node) => node.remove());
        wanted.slice(existing.length).forEach((id) => {
            const data = this.boardById(id);
            if (!data) return;
            const sw = parseFloat(screen.style.width);
            const sh = parseFloat(screen.style.height);
            const sheet = data.w >= sw * 0.9;
            const fit = Math.min(1, sw / data.w, sh / data.h);
            const frame = this.playFrame(data);
            Object.assign(frame.style, { width: `${data.w}px`, height: `${data.h}px` });
            const panel = el('div', { class: `ward-play-panel${sheet ? ' is-sheet' : ''}`, style: { zoom: String(fit) } }, [frame]);
            const overlay = el('div', { class: 'ward-play-overlay' }, [panel]);
            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) this.playCloseOverlay();
            });
            screen.append(overlay);
            const effect = transition ?? (sheet ? 'slide-up' : 'fade');
            if (effect !== 'none' && panel.animate) {
                overlay.animate([{ backgroundColor: 'rgb(0 0 0 / 0)' }, { backgroundColor: 'rgb(0 0 0 / .45)' }], { duration: 200 });
                panel.animate(effect === 'slide-up' || effect === 'slide'
                    ? [{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }]
                    : [{ opacity: 0, transform: 'scale(.96)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 240, easing: 'cubic-bezier(.2,.8,.2,1)' });
            }
        });
        const current = this.boardById(this.play.current);
        if (current) this.renderPlayBar(current);
    }

    renderPlayBar(data) {
        const boards = this.ordered();
        const index = boards.findIndex((board) => board.id === data.id);
        const device = { phone: 'celular', tablet: 'tablet', browser: data.kind === 'page' ? 'página' : 'desktop', none: '' }[this.deviceOf(data)];
        this.focus.querySelector('[data-focus-title]').textContent = data.title || data.name;
        this.focus.querySelector('[data-focus-meta]').textContent = [
            index >= 0 ? `${index + 1} de ${boards.length}` : null,
            `${data.w}×${data.h}`,
            device || null,
            this.play?.stack.length ? `${this.play.stack.length} tela(s) no histórico` : null,
        ].filter(Boolean).join(' · ');
        this.focus.querySelector('[data-focus-open]').href = data.url;
        if (this.playControls) {
            const prefs = this.playPrefs();
            this.playControls.back.disabled = !this.play?.stack.length && !this.play?.overlays.length;
            this.playControls.hotspots.setAttribute('aria-pressed', String(Boolean(this.play?.hotspots)));
            this.playControls.frame.setAttribute('aria-pressed', String(prefs.frame));
            this.playControls.real.setAttribute('aria-pressed', String(prefs.real));
        }
    }

    flashPlayHint(text) {
        const toast = this.focus.querySelector('.ward-play-toast');
        if (!toast) return;
        toast.textContent = text;
        toast.hidden = false;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { toast.hidden = true; }, 2200);
    }

    setHotspots(on) {
        if (!this.play) return;
        this.play.hotspots = on;
        for (const frame of this.focus.querySelectorAll('[data-focus-stage] iframe')) {
            frame.contentWindow?.postMessage({ ward: 'hotspots', on }, this.config.contentOrigin);
        }
        this.renderPlayBar(this.boardById(this.play.current));
    }

    /** Ligação clicada dentro de uma tela do Play (mensagem do runtime). */
    playNavigate(message) {
        if (message.close) return this.playCloseOverlay();
        if (message.back) return this.playBack(message.transition);
        const target = this.state.boards.find((board) => board.name === message.board);
        if (!target) return this.flashPlayHint(`A tela "${message.board}" não existe neste design.`);
        if (message.overlay) return this.playOverlay(target.id, message.transition);
        this.playGo(target.id, message.transition);
    }

    bindFocus() {
        const button = (label, title, onClick) => {
            const node = el('button', { type: 'button', class: 'ward-play-btn', title, text: label });
            node.addEventListener('click', onClick);
            return node;
        };
        this.playControls = {
            back: button('← Voltar', 'Voltar à tela anterior (Backspace)', () => this.playBack()),
            restart: button('↺', 'Recomeçar do início', () => this.playRestart()),
            hotspots: button('Áreas clicáveis', 'Mostrar onde dá para clicar (H)', () => this.setHotspots(!this.play?.hotspots)),
            frame: button('Moldura', 'Mostrar a moldura do aparelho', () => {
                storage('ward-play-frame', !this.playPrefs().frame);
                this.renderFocus();
            }),
            real: button('100%', 'Tamanho real (sem reduzir para caber)', () => {
                storage('ward-play-real', !this.playPrefs().real);
                this.renderFocus();
            }),
        };
        const prev = this.focus.querySelector('[data-focus-prev]');
        prev?.before(...Object.values(this.playControls));

        this.focus.querySelector('[data-focus-close]').addEventListener('click', () => this.closeFocus());
        this.focus.querySelector('[data-focus-prev]').addEventListener('click', () => this.stepFocus(-1));
        this.focus.querySelector('[data-focus-next]').addEventListener('click', () => this.stepFocus(1));
        window.addEventListener('resize', () => {
            if (this.play) this.renderFocus();
        });
    }

    bindMessages() {
        this.picks = new Map();
        window.addEventListener('message', (event) => {
            if (event.origin !== this.config.contentOrigin) return;
            const message = event.data;
            if (message?.ward === 'key' && message.key === 'Escape' && this.play) {
                if (this.play.overlays.length) this.playCloseOverlay();
                else this.closeFocus();
                return;
            }
            if (message?.ward === 'navigate') {
                if (this.play) return this.playNavigate(message);
                const target = this.state.boards.find((board) => board.name === message.board);
                if (target) this.openFocus(target.id);
                return;
            }
            if (message?.ward === 'picked' || message?.ward === 'tweaks-read' || message?.ward === 'answer') {
                const value = message.ward === 'picked' ? message.element : message.ward === 'answer' ? message.value : message.tweaks;
                this.picks.get(message.request)?.(value ?? null);
                this.picks.delete(message.request);
                return;
            }
            if (message?.ward === 'text-edited') {
                const entry = [...this.boards.values()].find((item) => item.frame.contentWindow === event.source);
                if (entry) this.editor?.textEdited(entry.data.id, message);
                return;
            }
            if (message?.ward === 'background') {
                const entry = [...this.boards.values()].find((item) => item.frame.contentWindow === event.source
                    || item.nextFrame?.contentWindow === event.source);
                if (!entry) return;
                entry.painted = Boolean(message.painted);
                entry.node.classList.toggle('is-transparent', Boolean(entry.data.transparent) || !entry.painted);
                return;
            }
            if (message?.ward === 'error' && typeof message.message === 'string') {
                // O iframe novo (troca sem piscar) já reporta enquanto carrega por baixo.
                const entry = [...this.boards.values()].find((item) => item.frame.contentWindow === event.source
                    || item.nextFrame?.contentWindow === event.source);
                if (!entry) return;
                entry.errors = [...entry.errors, message.message.slice(0, 300)].slice(-10);
                entry.error.hidden = false;
                entry.error.title = entry.errors[entry.errors.length - 1];
                entry.node.classList.add('has-error');
                this.editor?.afterErrors();
            }
        });
    }

    clearErrors(entry) {
        entry.errors = [];
        entry.error.hidden = true;
        entry.node.classList.remove('has-error');
        this.editor?.afterErrors();
    }

    /** Erros de script dos artboards carregados: [{board: nome, message}]. */
    errors() {
        return [...this.boards.values()].flatMap((entry) => entry.errors.map((message) => ({ board: entry.data.name, message })));
    }

    /**
     * Pergunta ao artboard que elemento está no ponto (x, y em px do artboard).
     * Resolve com a descrição do elemento, ou null (artboard não carregado, sem resposta).
     */
    pick(boardId, x, y) {
        return this.ask(boardId, { ward: 'pick', x, y });
    }

    /** Pergunta algo ao runtime do artboard e espera a resposta (ou null depois de timeout ms). */
    ask(boardId, message, timeout = 800) {
        const entry = this.boards.get(boardId);
        if (!entry || !entry.loaded || !entry.frame.contentWindow) return Promise.resolve(null);
        const request = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        return new Promise((resolve) => {
            this.picks.set(request, resolve);
            setTimeout(() => {
                if (this.picks.delete(request)) resolve(null);
            }, timeout);
            entry.frame.contentWindow.postMessage({ ...message, request }, this.config.contentOrigin);
        });
    }

    /** Manda uma mensagem ao runtime do artboard, sem esperar resposta. */
    tell(boardId, message) {
        const entry = this.boards.get(boardId);
        entry?.frame.contentWindow?.postMessage(message, this.config.contentOrigin);
    }

    /** Artboards que aparecem na tela agora. */
    visibleBoards() {
        const rect = this.viewport.getBoundingClientRect();
        const { x, y, scale } = this.view;
        return this.pageBoards().filter((data) => {
            const left = data.x * scale + x;
            const top = data.y * scale + y;
            return left < rect.width && left + data.w * scale > 0 && top < rect.height && top + data.h * scale > 0;
        });
    }

    bindKeyboard() {
        document.addEventListener('keydown', (event) => {
            if (event.defaultPrevented) return;
            if (event.target.closest('input, textarea, select, [contenteditable]')) return;
            if (this.play) {
                if (event.key === 'Escape') {
                    if (this.play.overlays.length) this.playCloseOverlay();
                    else this.closeFocus();
                }
                if (event.key === 'ArrowRight') this.stepFocus(1);
                if (event.key === 'ArrowLeft') this.stepFocus(-1);
                if (event.key === 'Backspace') this.playBack();
                if (event.key === 'h' || event.key === 'H') this.setHotspots(!this.play.hotspots);
                if (event.key === 'r' || event.key === 'R') this.playRestart();
                return;
            }
            if (event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.key === '+' || event.key === '=') this.zoomAt(1.25);
            else if (event.key === '-') this.zoomAt(0.8);
            else if (event.key === '0') this.setScale(1);
            else if (event.key === '1') this.fit();
            else if ((event.key === 'Enter' || event.key === 'f') && this.selected) this.openFocus(this.selected);
            else if (event.key === 'Escape') this.select(null);
            else return;
            event.preventDefault();
        });
    }

    // ---------- ao vivo ----------

    schedulePoll() {
        clearTimeout(this.pollTimer);
        if (this.destroyed) return;
        const active = document.visibilityState === 'visible' && (this.options.isActive?.() ?? true);
        const delay = active ? this.options.pollVisible : POLL_HIDDEN;
        this.pollTimer = setTimeout(() => this.poll(), delay);
        if (!this.visibilityBound) {
            this.visibilityBound = true;
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.poll();
            });
        }
    }

    async poll() {
        clearTimeout(this.pollTimer);
        if (this.destroyed) return;
        if (this.options.poll) {
            try {
                const state = await this.options.poll(this.state.revision);
                // Durante um arraste ou um envio do editor, o estado do servidor espera.
                if (state && !this.hold) this.setState(state);
            } catch {
                // a fonte decide se tenta de novo; aqui só segue o ciclo
            }
            return this.schedulePoll();
        }
        try {
            const separator = this.config.liveUrl.includes('?') ? '&' : '?';
            const response = await fetch(`${this.config.liveUrl}${separator}rev=${this.state.revision}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (response.status === 403 || response.status === 404) {
                this.showBanner('Este canvas não está mais disponível para esta sessão. Recarregue a página.');
                return;
            }
            // Durante um arraste ou um envio do editor, o estado do servidor espera.
            if (response.status === 200 && !this.hold) {
                this.state = await response.json();
                this.renderAll();
            }
        } catch {
            // rede instável: tenta de novo no próximo ciclo
        }
        this.schedulePoll();
    }

    showBanner(text) {
        this.banner.textContent = text;
        this.banner.hidden = false;
    }
}

window.WardDesignCanvas = DesignCanvas;

const root = document.querySelector('[data-design-canvas]');
const configNode = document.getElementById('ward-design-config');
if (root && configNode) {
    window.wardDesign = new DesignCanvas(root, JSON.parse(configNode.textContent));
}
