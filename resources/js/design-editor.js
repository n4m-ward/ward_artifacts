// Ward Design: edição à mão no canvas web (só para quem pode editar o design).
// Liga-se ao canvas (resources/js/design-canvas.js) por canvas.editor e manda as
// mudanças em lote para POST /d/{uuid}/edit, que usa as mesmas ações do MCP.

const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
const NOTE_HANDLES = ['e', 'w'];
const MIN_BOARD = 40;
const MIN_NOTE = 120;
const HISTORY_LIMIT = 100;
const NOTE_COLORS = [
    ['yellow', '#FEF3C7', 'Amarelo'], ['orange', '#FFEDD5', 'Laranja'], ['red', '#FEE2E2', 'Vermelho'],
    ['green', '#DCFCE7', 'Verde'], ['teal', '#CCFBF1', 'Verde-água'], ['blue', '#DBEAFE', 'Azul'],
    ['purple', '#EDE9FE', 'Roxo'], ['pink', '#FCE7F3', 'Rosa'], ['gray', '#E4E4E7', 'Cinza'],
];
const NOTE_SIZES = [['s', 'P'], ['m', 'M'], ['l', 'G'], ['xl', 'GG']];

const ICONS = {
    select: 'M5 3l14 8-6 2-3 6z',
    hand: 'M8 13V5.5a1.5 1.5 0 013 0V12m0-1V4.5a1.5 1.5 0 013 0V12m0-6.5a1.5 1.5 0 013 0V13a7 7 0 01-7 7h-.5a6 6 0 01-4.8-2.4L3 14.5a1.5 1.5 0 012.3-1.9L8 15',
    note: 'M5 4h14v10l-6 6H5zM13 20v-6h6',
    title: 'M5 6V4h14v2M12 4v16M9 20h6',
    undo: 'M9 14L4 9l5-5M4 9h10a6 6 0 010 12h-3',
    redo: 'M15 14l5-5-5-5M20 9H10a6 6 0 000 12h3',
    duplicate: 'M8 8h12v12H8zM16 8V4H4v12h4',
    trash: 'M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3',
    open: 'M14 4h6v6M20 4l-8 8M18 14v6H4V6h6',
    alignLeft: 'M4 3v18M8 7h12v4H8zM8 14h7v4H8z',
    alignCenterH: 'M12 3v18M6 7h12v4H6zM8.5 14h7v4h-7z',
    alignRight: 'M20 3v18M4 7h12v4H4zM9 14h7v4H9z',
    alignTop: 'M3 4h18M7 8h4v12H7zM14 8h4v7h-4z',
    alignMiddle: 'M3 12h18M7 6h4v12H7zM14 8.5h4v7h-4z',
    alignBottom: 'M3 20h18M7 4h4v12H7zM14 9h4v7h-4z',
    distributeH: 'M4 3v18M20 3v18M9 8h6v8H9z',
    distributeV: 'M3 4h18M3 20h18M8 9h8v6H8z',
    comment: 'M4 5h16v11H9l-5 4z',
    comments: 'M7 9h10M7 13h6M4 5h16v11H9l-5 4z',
    check: 'M5 12l5 5 9-10',
    download: 'M12 4v11M7 10l5 5 5-5M5 20h14',
    image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15 9.5h.01',
    components: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM16.5 13v7M13 16.5h7',
    theme: 'M12 3a9 9 0 100 18c1 0 1.5-.8 1.2-1.6-.4-1 .3-2.4 1.6-2.4H17a4 4 0 004-4c0-5-4-10-9-10zM7.5 11h.01M10 7h.01M15 7.5h.01',
    close: 'M6 6l12 12M18 6L6 18',
};

function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', ICONS[name]);
    svg.append(path);
    return svg;
}

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

function button(label, iconName, onClick, { text, ...extra } = {}) {
    const node = el('button', { type: 'button', class: 'ward-ed-btn', 'aria-label': label, title: label, ...extra },
        iconName ? [icon(iconName)] : [text ?? label]);
    node.addEventListener('click', (event) => {
        event.stopPropagation();
        onClick(event);
    });
    return node;
}

const isMac = /Mac|iPhone|iPad/.test(navigator.platform);

class DesignEditor {
    /**
     * config: a configuração da página. config.edit (dono ou admin) liga a edição;
     * config.collab (qualquer pessoa logada) liga comentários e o relato para a IA.
     */
    constructor(canvas, config) {
        this.canvas = canvas;
        this.page = config;
        this.config = config.edit ?? {};
        this.collab = config.collab;
        this.canEdit = Boolean(config.edit);
        this.tool = 'select';
        this.selection = new Set(); // 'b:<id>' ou 'n:<key>'
        this.undoStack = [];
        this.redoStack = [];
        this.queue = Promise.resolve();
        this.pending = 0;
        this.gesture = null;
        this.space = false;
        this.editingNote = null;

        // Dentro do MCP App (sem cookie): as chamadas vão pela ferramenta design_app_request.
        this.inApp = Boolean(config.app);
        canvas.editor = this;
        this.buildUi();
        this.bindKeys();
        this.bindDoubleClick();
        this.initComments();
        if (this.canEdit) this.initInspector();
        this.initAssets();
        this.afterRender();
    }

    // ---------- itens ----------

    boardEntry(key) {
        return key.startsWith('b:') ? this.canvas.boards.get(Number(key.slice(2))) : null;
    }

    noteData(key) {
        return key.startsWith('n:') ? this.canvas.state.notes.find((note) => note.key === key.slice(2)) : null;
    }

    noteNode(noteKey) {
        return this.canvas.notesLayer.querySelector(`[data-note="${CSS.escape(noteKey)}"]`);
    }

    exists(key) {
        return Boolean(this.boardEntry(key) || this.noteData(key));
    }

    rect(key) {
        const entry = this.boardEntry(key);
        if (entry) return { x: entry.data.x, y: entry.data.y, w: entry.data.w, h: entry.data.h };
        const note = this.noteData(key);
        const node = note && this.noteNode(note.key);
        if (!node) return null;
        return { x: note.x, y: note.y, w: node.offsetWidth, h: node.offsetHeight };
    }

    allKeys() {
        return [
            ...this.canvas.pageBoards().map((board) => `b:${board.id}`),
            ...this.canvas.state.notes.filter((note) => this.canvas.inPage(note)).map((note) => `n:${note.key}`),
        ];
    }

    selectionBounds(keys = [...this.selection]) {
        const rects = keys.map((key) => this.rect(key)).filter(Boolean);
        if (!rects.length) return null;
        const x = Math.min(...rects.map((r) => r.x));
        const y = Math.min(...rects.map((r) => r.y));
        return { x, y, w: Math.max(...rects.map((r) => r.x + r.w)) - x, h: Math.max(...rects.map((r) => r.y + r.h)) - y };
    }

    setSelection(keys) {
        this.selection = new Set(keys.filter((key) => this.exists(key)));
        const boards = [...this.selection].filter((key) => key.startsWith('b:'));
        this.syncing = true;
        const single = this.selection.size === 1 && boards.length === 1 ? Number(boards[0].slice(2)) : null;
        this.canvas.select(single);
        this.syncing = false;
        if (this.inspect && this.inspect.boardId !== single) this.exitBoard();
        if (this.canEdit && single !== null && this.tweaksFor !== single) this.loadTweaks(single);
        if (single === null && this.tweaksOpen) {
            this.tweaksOpen = false;
            if (!this.inspect) this.props.hidden = true;
        }
        this.drawSelection();
    }

    // ---------- interface ----------

    buildUi() {
        const root = this.canvas.root;

        this.overlay = el('div', { class: 'ward-ed-overlay' });
        this.canvas.world.append(this.overlay);

        this.marquee = el('div', { class: 'ward-ed-marquee', hidden: true });
        this.canvas.viewport.append(this.marquee);

        this.tools = {};
        const toolbar = el('div', { class: 'ward-ed-toolbar', role: 'toolbar', 'aria-label': 'Ferramentas do canvas' });
        const tools = this.canEdit
            ? [['select', 'Selecionar', 'V', 'select'], ['hand', 'Mão', 'H', 'hand'], ['note', 'Nota adesiva', 'N', 'note'], ['title', 'Título', 'T', 'title'], ['comment', 'Comentar', 'C', 'comment']]
            : [['select', 'Navegar', 'V', 'hand'], ['comment', 'Comentar', 'C', 'comment']];
        for (const [tool, label, key, iconName] of tools) {
            this.tools[tool] = button(`${label} (${key})`, iconName, () => this.setTool(tool), { 'aria-pressed': 'false' });
            toolbar.append(this.tools[tool]);
        }
        toolbar.append(el('span', { class: 'ward-ed-sep', 'aria-hidden': 'true' }));
        this.undoButton = button(`Desfazer (${isMac ? '⌘' : 'Ctrl+'}Z)`, 'undo', () => this.undo());
        this.redoButton = button(`Refazer (${isMac ? '⇧⌘' : 'Ctrl+Shift+'}Z)`, 'redo', () => this.redo());
        if (this.canEdit) toolbar.append(this.undoButton, this.redoButton, el('span', { class: 'ward-ed-sep', 'aria-hidden': 'true' }));
        this.commentsButton = button('Comentários', 'comments', () => this.togglePanel(), { 'aria-pressed': 'false', 'aria-expanded': 'false' });
        this.commentsCount = el('span', { class: 'ward-ed-count', hidden: true });
        this.commentsButton.append(this.commentsCount);
        toolbar.append(this.commentsButton);
        if (this.canEdit) {
            this.imagesButton = button('Imagens do design', 'image', () => this.toggleImages(), { 'aria-pressed': 'false' });
            this.themeButton = button('Tema do design (tokens)', 'theme', () => this.toggleTheme());
            this.componentsButton = button('Componentes de design systems', 'components', () => this.toggleComponents(), { 'aria-pressed': 'false' });
            toolbar.append(this.imagesButton, this.themeButton, this.componentsButton);
        }
        // Exportar PNG/PDF baixa arquivos: só na página do design, não no MCP App.
        if (!this.inApp) {
            this.exportButton = button('Exportar', 'download', () => this.toggleExport(), { 'aria-haspopup': 'menu', 'aria-expanded': 'false' });
            toolbar.append(this.exportButton);
        }
        this.status = el('span', { class: 'ward-ed-status', role: 'status', 'aria-live': 'polite' });
        toolbar.append(this.status);
        root.append(toolbar);

        this.bar = el('div', { class: 'ward-ed-bar', hidden: true, role: 'toolbar', 'aria-label': 'Ações da seleção' });
        root.append(this.bar);

        // Clique no nome do artboard seleciona (no modo leitura ele abria o foco).
        root.addEventListener('click', (event) => {
            if (event.target.closest('.ward-board-name') && !this.canvas.isMobile()) {
                event.stopPropagation();
                event.preventDefault();
            }
        }, true);

        this.setTool('select');
        this.updateHistoryButtons();
    }

    setTool(tool) {
        this.tool = tool;
        for (const [name, node] of Object.entries(this.tools)) node.setAttribute('aria-pressed', String(name === tool));
        this.canvas.viewport.dataset.tool = tool;
        if (tool !== 'comment') this.clearHover();
    }

    updateHistoryButtons() {
        this.undoButton.disabled = this.undoStack.length === 0;
        this.redoButton.disabled = this.redoStack.length === 0;
    }

    setStatus(text, error = false) {
        this.status.textContent = text;
        this.status.classList.toggle('is-error', error);
        clearTimeout(this.statusTimer);
        if (text && !error) this.statusTimer = setTimeout(() => (this.status.textContent = ''), 2500);
    }

    // ---------- desenho da seleção ----------

    afterRender() {
        this.renderPins();
        if (this.themeOpen && !this.props.hidden && !this.props.contains(document.activeElement)) this.renderTheme();
        if (this.inspect) this.drawElement();
        if (this.gesture) return;
        const before = this.selection.size;
        for (const key of [...this.selection]) if (!this.exists(key)) this.selection.delete(key);
        if (before !== this.selection.size) this.setSelection([...this.selection]);
        else this.drawSelection();
    }

    afterView() {
        this.positionBar();
        this.positionThread();
        this.reportView();
    }

    afterSelect(id) {
        this.reportView();
        if (this.syncing) return;
        // O canvas selecionou sozinho (ex.: foco com anterior/próximo, clique sem edição).
        this.selection = new Set(id === null ? [] : [`b:${id}`]);
        this.drawSelection();
    }

    drawSelection() {
        const nodes = [];
        const single = this.selection.size === 1 ? [...this.selection][0] : null;

        for (const key of this.selection) {
            const rect = this.rect(key);
            if (!rect) continue;
            const box = el('div', { class: 'ward-ed-sel', style: rectStyle(rect) });
            if (key === single && !this.editingNote) {
                const handles = key.startsWith('b:') ? HANDLES : NOTE_HANDLES;
                for (const dir of handles) {
                    box.append(el('span', { class: `ward-ed-handle is-${dir}`, 'data-handle': dir }));
                }
            }
            nodes.push(box);
        }

        if (this.selection.size > 1) {
            const bounds = this.selectionBounds();
            if (bounds) nodes.push(el('div', { class: 'ward-ed-group', style: rectStyle(bounds) }));
        }

        this.overlay.replaceChildren(...nodes);
        this.renderBar();
    }

    renderBar() {
        const keys = [...this.selection];
        if (!this.canEdit || !keys.length || this.gesture || this.editingNote || this.canvas.isMobile()) {
            this.bar.hidden = true;
            return;
        }

        const boards = keys.filter((key) => key.startsWith('b:'));
        const notes = keys.filter((key) => key.startsWith('n:'));
        const children = [];

        if (boards.length === 1 && notes.length === 0) {
            const data = this.boardEntry(boards[0]).data;
            children.push(el('span', { class: 'ward-ed-label', text: `${data.title || data.name} · ${data.w}×${data.h}` }));
            children.push(button('Abrir em foco (Enter)', 'open', () => this.canvas.openFocus(data.id)));
            children.push(button('Editar elementos: clique duplo no artboard', null, () => this.enterBoardCenter(data.id), { text: 'Editar', class: 'ward-ed-btn is-text' }));
            if (this.tweaksFor === data.id && this.tweaks) {
                children.push(button('Tweaks deste artboard', null, () => {
                    this.tweaksOpen = true;
                    this.exitBoard();
                    this.panel.hidden = true;
                    this.renderTweaks();
                }, { text: 'Tweaks', class: 'ward-ed-btn is-text', 'aria-pressed': String(Boolean(this.tweaksOpen)) }));
            }
        }

        if (boards.length >= 2) {
            children.push(
                button('Alinhar à esquerda', 'alignLeft', () => this.align('left')),
                button('Centralizar na horizontal', 'alignCenterH', () => this.align('centerH')),
                button('Alinhar à direita', 'alignRight', () => this.align('right')),
                button('Alinhar no topo', 'alignTop', () => this.align('top')),
                button('Centralizar na vertical', 'alignMiddle', () => this.align('middle')),
                button('Alinhar embaixo', 'alignBottom', () => this.align('bottom')),
            );
            if (boards.length >= 3) {
                children.push(
                    button('Distribuir na horizontal', 'distributeH', () => this.distribute('x')),
                    button('Distribuir na vertical', 'distributeV', () => this.distribute('y')),
                );
            }
            children.push(el('span', { class: 'ward-ed-sep', 'aria-hidden': 'true' }));
        }

        if (notes.length && !boards.length) {
            const first = this.noteData(notes[0]);
            const swatches = el('div', { class: 'ward-ed-swatches', role: 'group', 'aria-label': 'Cor da nota' });
            for (const [color, hex, label] of NOTE_COLORS) {
                const swatch = button(label, null, () => this.styleNotes({ color, kind: 'sticky' }), {
                    class: 'ward-ed-swatch', 'aria-pressed': String(first.kind !== 'title' && (first.color || 'yellow') === color), text: '',
                });
                swatch.style.background = hex;
                swatches.append(swatch);
            }
            children.push(swatches, el('span', { class: 'ward-ed-sep', 'aria-hidden': 'true' }));

            for (const [size, label] of NOTE_SIZES) {
                children.push(button(`Tamanho ${label}`, null, () => this.styleNotes({ size }), {
                    text: label, 'aria-pressed': String((first.size || defaultSize(first)) === size),
                }));
            }
            if (first.kind !== 'title') {
                children.push(button('Negrito', null, () => this.styleNotes({ bold: !first.bold }), {
                    text: 'B', class: 'ward-ed-btn is-bold', 'aria-pressed': String(Boolean(first.bold)),
                }));
            }
            children.push(button(first.kind === 'title' ? 'Virar nota adesiva' : 'Virar título', null,
                () => this.styleNotes({ kind: first.kind === 'title' ? 'sticky' : 'title' }),
                { text: first.kind === 'title' ? 'Adesiva' : 'Título', class: 'ward-ed-btn is-text' }));
            children.push(el('span', { class: 'ward-ed-sep', 'aria-hidden': 'true' }));
        }

        if (boards.length && !notes.length) {
            children.push(button(`Duplicar (${isMac ? '⌘' : 'Ctrl+'}D)`, 'duplicate', () => this.duplicate()));
        }
        if (boards.length === 1 && !notes.length) {
            const data = this.boardEntry(boards[0]).data;
            if (this.collab?.isSystem) {
                children.push(button('Descrição e tags do componente', null, () => this.describeComponent(data), { text: 'Detalhes', class: 'ward-ed-btn is-text' }));
            } else {
                children.push(button('Publicar no design system', null, () => this.publishComponent(data), { text: 'Publicar no DS', class: 'ward-ed-btn is-text' }));
            }
        }
        if (this.canvas.pages().length > 1) {
            const move = el('select', { class: 'ward-ed-select', 'aria-label': 'Mover para a página' }, [
                el('option', { value: '', text: 'Mover para…' }),
                ...this.canvas.pages().filter((page) => page.key !== this.canvas.currentPage())
                    .map((page) => el('option', { value: page.key, text: page.name })),
            ]);
            move.addEventListener('change', () => move.value && this.moveToPage(move.value));
            children.push(move);
        }
        children.push(button('Apagar (Delete)', 'trash', () => this.deleteSelection(), { class: 'ward-ed-btn is-danger' }));

        this.bar.replaceChildren(...children);
        this.bar.hidden = false;
        this.positionBar();
    }

    positionBar() {
        if (this.bar.hidden) return;
        const bounds = this.selectionBounds();
        if (!bounds) return;
        const { x, y, scale } = this.canvas.view;
        const rootRect = this.canvas.root.getBoundingClientRect();
        const viewRect = this.canvas.viewport.getBoundingClientRect();
        const centerX = viewRect.left - rootRect.left + x + (bounds.x + bounds.w / 2) * scale;
        let top = viewRect.top - rootRect.top + y + bounds.y * scale - this.bar.offsetHeight - 40;
        if (top < 8) top = viewRect.top - rootRect.top + y + (bounds.y + bounds.h) * scale + 16;
        const half = this.bar.offsetWidth / 2;
        const left = Math.min(Math.max(centerX, half + 8), rootRect.width - half - 8);
        Object.assign(this.bar.style, { left: `${left}px`, top: `${Math.min(Math.max(top, 8), rootRect.height - this.bar.offsetHeight - 72)}px` });
    }

    // ---------- ponteiro ----------

    pointerDown(event) {
        if (this.canvas.isMobile() || this.canvas.focusIndex !== null) return false;
        if (event.button === 0 && !this.space && this.commentPointerDown(event)) return true;
        if (!this.canEdit) return false;

        // Dentro de um artboard em edição: o clique escolhe outro elemento.
        if (this.inspect && event.button === 0 && !this.space && this.tool === 'select') {
            const inside = event.target.closest('[data-board]');
            if (inside && Number(inside.dataset.board) === this.inspect.boardId && !event.target.closest('[data-handle], .ward-board-caption')) {
                this.enterBoard(this.inspect.boardId, event.clientX, event.clientY);
                return true;
            }
            this.exitBoard();
        }
        if (this.editingNote) {
            if (event.target.closest('[data-note]') === this.editingNote) return true;
            this.editingNote.blur();
        }
        // Mão, espaço e botão do meio arrastam o canvas.
        if (event.button === 1 || this.space || this.tool === 'hand') return false;
        if (event.button !== 0) return false;

        const target = event.target;
        const world = this.canvas.toWorld(event.clientX, event.clientY);

        const handle = target.closest('[data-handle]');
        if (handle && this.selection.size === 1) {
            this.startResize(event, [...this.selection][0], handle.dataset.handle);
            return true;
        }

        if (this.tool === 'note' || this.tool === 'title') {
            this.createNote(this.tool === 'title' ? 'title' : 'sticky', world);
            this.setTool('select');
            return true;
        }

        const noteNode = target.closest('[data-note]');
        const boardNode = target.closest('[data-board]');
        const key = noteNode ? `n:${noteNode.dataset.note}` : boardNode ? `b:${boardNode.dataset.board}` : null;

        if (key) {
            if (event.shiftKey) {
                const next = new Set(this.selection);
                if (next.has(key)) next.delete(key);
                else next.add(key);
                this.setSelection([...next]);
                if (!next.has(key)) return true;
            } else if (!this.selection.has(key)) {
                this.setSelection([key]);
            }
            this.startMove(event);
            return true;
        }

        this.startMarquee(event, world);
        return true;
    }

    track(event, onMove, onUp) {
        const move = (e) => onMove(e);
        const up = (e) => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', up);
            onUp(e);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', up);
    }

    startMove(event) {
        const keys = [...this.selection];
        const start = { x: event.clientX, y: event.clientY };
        const origins = keys.map((key) => ({ key, ...this.rect(key) }));
        let moved = false;

        this.track(event, (e) => {
            const dx = (e.clientX - start.x) / this.canvas.view.scale;
            const dy = (e.clientY - start.y) / this.canvas.view.scale;
            if (!moved && Math.hypot(e.clientX - start.x, e.clientY - start.y) < 3) return;
            if (!moved) {
                moved = true;
                this.gesture = 'move';
                this.canvas.hold = true;
                this.bar.hidden = true;
                this.canvas.viewport.classList.add('is-moving');
            }
            for (const origin of origins) this.place(origin.key, { x: Math.round(origin.x + dx), y: Math.round(origin.y + dy) });
            this.drawSelection();
        }, () => {
            this.canvas.viewport.classList.remove('is-moving');
            if (!moved) return;
            this.gesture = null;
            const redo = [];
            const undo = [];
            for (const origin of origins) {
                const now = this.rect(origin.key);
                if (!now || (now.x === origin.x && now.y === origin.y)) continue;
                redo.push(this.positionOp(origin.key, now));
                undo.push(this.positionOp(origin.key, origin));
            }
            this.commit(redo, undo, 'Movido');
        });
    }

    startResize(event, key, dir) {
        const start = { x: event.clientX, y: event.clientY };
        const origin = this.rect(key);
        const isNote = key.startsWith('n:');
        const min = isNote ? MIN_NOTE : MIN_BOARD;
        this.gesture = 'resize';
        this.canvas.hold = true;
        this.bar.hidden = true;

        this.track(event, (e) => {
            const dx = (e.clientX - start.x) / this.canvas.view.scale;
            const dy = (e.clientY - start.y) / this.canvas.view.scale;
            const next = { ...origin };
            if (dir.includes('e')) next.w = Math.max(min, origin.w + dx);
            if (dir.includes('w')) {
                next.w = Math.max(min, origin.w - dx);
                next.x = origin.x + origin.w - next.w;
            }
            if (!isNote && dir.includes('s')) next.h = Math.max(min, origin.h + dy);
            if (!isNote && dir.includes('n')) {
                next.h = Math.max(min, origin.h - dy);
                next.y = origin.y + origin.h - next.h;
            }
            for (const k of ['x', 'y', 'w', 'h']) next[k] = Math.round(Math.min(next[k], k === 'w' || k === 'h' ? 8000 : next[k]));
            this.place(key, next);
            this.drawSelection();
        }, () => {
            this.gesture = null;
            const now = this.rect(key);
            if (!now || (now.x === origin.x && now.y === origin.y && now.w === origin.w && now.h === origin.h)) {
                this.release();
                this.drawSelection();
                return;
            }
            this.commit([this.geometryOp(key, now)], [this.geometryOp(key, origin)], 'Redimensionado');
        });
    }

    startMarquee(event, world) {
        const initial = event.shiftKey ? new Set(this.selection) : new Set();
        const viewRect = this.canvas.viewport.getBoundingClientRect();
        const start = { x: event.clientX, y: event.clientY, world };
        let active = false;
        if (!event.shiftKey) this.setSelection([]);

        this.track(event, (e) => {
            if (!active && Math.hypot(e.clientX - start.x, e.clientY - start.y) < 3) return;
            active = true;
            this.gesture = 'marquee';
            const left = Math.min(start.x, e.clientX) - viewRect.left;
            const top = Math.min(start.y, e.clientY) - viewRect.top;
            Object.assign(this.marquee.style, {
                left: `${left}px`, top: `${top}px`,
                width: `${Math.abs(e.clientX - start.x)}px`, height: `${Math.abs(e.clientY - start.y)}px`,
            });
            this.marquee.hidden = false;
            const end = this.canvas.toWorld(e.clientX, e.clientY);
            const box = {
                x: Math.min(start.world.x, end.x), y: Math.min(start.world.y, end.y),
                w: Math.abs(end.x - start.world.x), h: Math.abs(end.y - start.world.y),
            };
            const hit = this.allKeys().filter((key) => intersects(this.rect(key), box));
            this.selection = new Set([...initial, ...hit]);
            this.drawSelection();
        }, () => {
            this.marquee.hidden = true;
            this.gesture = null;
            this.setSelection([...this.selection]);
        });
    }

    /** Mostra um item numa posição/tamanho novos (antes de salvar). */
    place(key, rect) {
        const entry = this.boardEntry(key);
        if (entry) {
            Object.assign(entry.data, rect);
            Object.assign(entry.node.style, { left: `${rect.x}px`, top: `${rect.y}px`, width: `${rect.w}px`, height: `${rect.h}px` });
            Object.assign(entry.frame.style, { width: `${rect.w}px`, height: `${rect.h}px` });
            entry.meta.textContent = `${Math.round(rect.w)}×${Math.round(rect.h)}${entry.data.interactive ? ' · Play' : ''}`;
            return;
        }
        const note = this.noteData(key);
        if (!note) return;
        note.x = rect.x;
        note.y = rect.y;
        if (rect.w !== undefined) note.w = Math.round(rect.w);
        const node = this.noteNode(note.key);
        if (node) {
            node.style.left = `${rect.x}px`;
            node.style.top = `${rect.y}px`;
            if (rect.w !== undefined) node.style[note.kind === 'title' ? 'maxWidth' : 'width'] = `${Math.round(rect.w)}px`;
        }
    }

    positionOp(key, rect) {
        return key.startsWith('b:')
            ? { op: 'board.update', id: Number(key.slice(2)), x: rect.x, y: rect.y }
            : { op: 'note.upsert', key: key.slice(2), x: rect.x, y: rect.y };
    }

    geometryOp(key, rect) {
        return key.startsWith('b:')
            ? { op: 'board.update', id: Number(key.slice(2)), x: rect.x, y: rect.y, w: rect.w, h: rect.h }
            : { op: 'note.upsert', key: key.slice(2), x: rect.x, y: rect.y, w: rect.w };
    }

    // ---------- comandos ----------

    align(mode) {
        const keys = [...this.selection].filter((key) => key.startsWith('b:'));
        const bounds = this.selectionBounds(keys);
        const redo = [];
        const undo = [];
        for (const key of keys) {
            const r = this.rect(key);
            const next = { ...r };
            if (mode === 'left') next.x = bounds.x;
            if (mode === 'right') next.x = bounds.x + bounds.w - r.w;
            if (mode === 'centerH') next.x = Math.round(bounds.x + (bounds.w - r.w) / 2);
            if (mode === 'top') next.y = bounds.y;
            if (mode === 'bottom') next.y = bounds.y + bounds.h - r.h;
            if (mode === 'middle') next.y = Math.round(bounds.y + (bounds.h - r.h) / 2);
            if (next.x === r.x && next.y === r.y) continue;
            this.place(key, next);
            redo.push(this.positionOp(key, next));
            undo.push(this.positionOp(key, r));
        }
        this.drawSelection();
        this.commit(redo, undo, 'Alinhado');
    }

    distribute(axis) {
        const size = axis === 'x' ? 'w' : 'h';
        const items = [...this.selection].filter((key) => key.startsWith('b:'))
            .map((key) => ({ key, r: this.rect(key) })).sort((a, b) => a.r[axis] - b.r[axis]);
        const first = items[0].r;
        const last = items[items.length - 1].r;
        const total = items.reduce((sum, item) => sum + item.r[size], 0);
        const gap = (last[axis] + last[size] - first[axis] - total) / (items.length - 1);
        let cursor = first[axis];
        const redo = [];
        const undo = [];
        for (const item of items) {
            const next = { ...item.r, [axis]: Math.round(cursor) };
            cursor += item.r[size] + gap;
            if (next[axis] === item.r[axis]) continue;
            this.place(item.key, next);
            redo.push(this.positionOp(item.key, next));
            undo.push(this.positionOp(item.key, item.r));
        }
        this.drawSelection();
        this.commit(redo, undo, 'Distribuído');
    }

    nudge(dx, dy) {
        const redo = [];
        const undo = [];
        for (const key of this.selection) {
            const r = this.rect(key);
            if (!r) continue;
            const next = { ...r, x: r.x + dx, y: r.y + dy };
            this.place(key, next);
            redo.push(this.positionOp(key, next));
            undo.push(this.positionOp(key, r));
        }
        this.drawSelection();
        // Várias setas seguidas viram um passo só no desfazer.
        clearTimeout(this.nudgeTimer);
        this.nudgeBatch ??= { redo: new Map(), undo: new Map() };
        for (const op of redo) this.nudgeBatch.redo.set(op.id ?? op.key, op);
        for (const op of undo) if (!this.nudgeBatch.undo.has(op.id ?? op.key)) this.nudgeBatch.undo.set(op.id ?? op.key, op);
        this.canvas.hold = true;
        this.nudgeTimer = setTimeout(() => {
            const batch = this.nudgeBatch;
            this.nudgeBatch = null;
            this.commit([...batch.redo.values()], [...batch.undo.values()], 'Movido');
        }, 400);
    }

    async duplicate() {
        const boards = [...this.selection].filter((key) => key.startsWith('b:'));
        if (!boards.length) return;
        const ops = boards.map((key) => ({ op: 'board.duplicate', id: Number(key.slice(2)) }));
        const results = await this.send(ops, 'Duplicando…');
        if (!results) return;
        const created = results.map((result) => result.id);
        this.push({
            redo: ops.map((op, i) => ({ ...op, _ref: created[i] })),
            undo: created.map((id) => ({ op: 'board.delete', id })),
        });
        this.setSelection(created.map((id) => `b:${id}`));
        this.setStatus(created.length > 1 ? `${created.length} artboards duplicados` : 'Artboard duplicado');
    }

    async deleteSelection() {
        const keys = [...this.selection];
        if (!keys.length) return;
        const ops = keys.map((key) => (key.startsWith('b:')
            ? { op: 'board.delete', id: Number(key.slice(2)) }
            : { op: 'note.delete', key: key.slice(2) }));
        this.setSelection([]);
        const results = await this.send(ops, 'Apagando…');
        if (!results) return;
        const undo = results.map((result, i) => (ops[i].op === 'board.delete'
            ? { op: 'board.create', ...result.removed, _ref: ops[i].id }
            : { op: 'note.upsert', ...result.removed }));
        this.push({ redo: ops, undo });
        this.setStatus('Apagado. Desfaça com ' + (isMac ? '⌘Z' : 'Ctrl+Z'));
    }

    styleNotes(change) {
        const keys = [...this.selection].filter((key) => key.startsWith('n:'));
        const redo = [];
        const undo = [];
        for (const key of keys) {
            const note = this.noteData(key);
            const before = { op: 'note.upsert', key: note.key };
            for (const field of Object.keys(change)) {
                before[field] = note[field] ?? (field === 'bold' ? false : field === 'size' ? defaultSize(note) : field === 'color' ? 'yellow' : note[field]);
            }
            Object.assign(note, change);
            redo.push({ op: 'note.upsert', key: note.key, ...change });
            undo.push(before);
        }
        this.canvas.renderNotes();
        this.drawSelection();
        this.commit(redo, undo, 'Nota atualizada');
    }

    createNote(kind, world) {
        const key = `n${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
        const note = {
            key, kind, page: this.canvas.currentPage(), x: Math.round(world.x), y: Math.round(world.y), w: kind === 'title' ? null : 260,
            text: kind === 'title' ? 'Título' : 'Nota', color: kind === 'title' ? null : 'yellow', size: null, bold: false,
        };
        this.canvas.state.notes.push(note);
        this.canvas.renderNotes();
        this.setSelection([`n:${key}`]);
        this.editNote(this.noteNode(key), { created: note });
    }

    editNote(node, { created = null } = {}) {
        if (!node) return;
        const key = node.dataset.note;
        const before = node.textContent;
        this.editingNote = node;
        this.canvas.hold = true;
        node.classList.add('is-editing');
        try {
            node.contentEditable = 'plaintext-only';
        } catch {
            node.contentEditable = 'true';
        }
        node.focus();
        const range = document.createRange();
        range.selectNodeContents(node);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        this.drawSelection();

        const keydown = (event) => {
            event.stopPropagation();
            if (event.key === 'Escape' || (event.key === 'Enter' && (event.metaKey || event.ctrlKey))) {
                event.preventDefault();
                node.blur();
            }
        };
        node.addEventListener('keydown', keydown);
        node.addEventListener('blur', () => {
            node.removeEventListener('keydown', keydown);
            node.contentEditable = 'false';
            node.classList.remove('is-editing');
            this.editingNote = null;
            const text = node.innerText.replace(/\n{3,}/g, '\n\n').trim();
            const note = this.canvas.state.notes.find((n) => n.key === key);

            if (created) {
                if (!text) {
                    this.canvas.state.notes = this.canvas.state.notes.filter((n) => n.key !== key);
                    this.canvas.renderNotes();
                    this.release();
                    this.setSelection([]);
                    return;
                }
                note.text = text;
                const op = { op: 'note.upsert', ...created, text };
                this.commit([op], [{ op: 'note.delete', key }], 'Nota criada');
            } else if (!text) {
                note.text = before;
                this.canvas.renderNotes();
                this.release();
            } else if (text !== before) {
                note.text = text;
                this.commit([{ op: 'note.upsert', key, text }], [{ op: 'note.upsert', key, text: before }], 'Nota atualizada');
            } else {
                this.release();
            }
            this.canvas.renderNotes();
            this.drawSelection();
        }, { once: true });
    }

    renameBoard(nameNode) {
        const boardNode = nameNode.closest('[data-board]');
        const entry = this.canvas.boards.get(Number(boardNode.dataset.board));
        if (!entry) return;
        const before = entry.data.title || '';
        const input = el('input', {
            class: 'ward-ed-rename', value: entry.data.title || entry.data.name, 'aria-label': 'Nome do artboard', maxlength: '120',
        });
        nameNode.replaceWith(input);
        input.focus();
        input.select();
        let done = false;
        const finish = (save) => {
            if (done) return;
            done = true;
            input.replaceWith(nameNode);
            const title = input.value.trim();
            if (!save || title === (entry.data.title || entry.data.name)) return;
            entry.data.title = title;
            nameNode.textContent = title || entry.data.name;
            this.commit(
                [{ op: 'board.update', id: entry.data.id, title }],
                [{ op: 'board.update', id: entry.data.id, title: before }],
                'Renomeado',
            );
        };
        input.addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Enter') finish(true);
            if (event.key === 'Escape') finish(false);
        });
        input.addEventListener('blur', () => finish(true));
        input.addEventListener('pointerdown', (event) => event.stopPropagation());
    }

    // ---------- envio e histórico ----------

    release() {
        if (!this.pending && !this.gesture && !this.editingNote && !this.nudgeBatch) this.canvas.hold = false;
    }

    commit(redo, undo, label) {
        if (!redo.length) {
            this.release();
            this.drawSelection();
            return;
        }
        this.push({ redo, undo });
        this.send(redo, 'Salvando…').then((results) => {
            if (results) this.setStatus(label);
        });
    }

    push(entry) {
        this.undoStack.push(entry);
        if (this.undoStack.length > HISTORY_LIMIT) this.undoStack.shift();
        this.redoStack = [];
        this.updateHistoryButtons();
    }

    async undo() {
        const entry = this.undoStack.pop();
        if (!entry) return;
        this.updateHistoryButtons();
        this.active = entry;
        const results = await this.send(entry.undo, 'Desfazendo…');
        this.active = null;
        if (!results) return;
        this.redoStack.push(entry);
        this.updateHistoryButtons();
        this.setStatus('Desfeito');
    }

    async redo() {
        const entry = this.redoStack.pop();
        if (!entry) return;
        this.updateHistoryButtons();
        this.active = entry;
        const results = await this.send(entry.redo, 'Refazendo…');
        this.active = null;
        if (!results) return;
        this.undoStack.push(entry);
        this.updateHistoryButtons();
        this.setStatus('Refeito');
    }

    /** Um artboard recriado ganha id novo: atualiza o histórico que apontava para o antigo. */
    remap(oldId, newId) {
        if (oldId === newId) return;
        // Inclui a entrada em andamento (ela está fora das pilhas durante o envio).
        for (const entry of [...this.undoStack, ...this.redoStack, this.active].filter(Boolean)) {
            for (const op of [...entry.undo, ...entry.redo]) {
                if (op.id === oldId) op.id = newId;
                if (op._ref === oldId) op._ref = newId;
            }
        }
        this.selection = new Set([...this.selection].map((key) => (key === `b:${oldId}` ? `b:${newId}` : key)));
    }

    /**
     * fetch da página do design (cookie + CSRF) ou, no MCP App, o transporte do app
     * (window.wardDesignTransport, que chama a ferramenta design_app_request).
     */
    http(url, init = {}) {
        return (window.wardDesignTransport ?? window.fetch.bind(window))(url, { credentials: 'same-origin', ...init });
    }

    /** Confirmação dentro do canvas (o MCP App roda num iframe sem window.confirm). */
    confirmDialog(message, confirmText = 'Confirmar') {
        return this.dialog({ message, confirmText });
    }

    /** Texto dentro do canvas no lugar de window.prompt; null quando cancela. */
    promptDialog(message, value = '') {
        return this.dialog({ message, value: String(value ?? ''), confirmText: 'OK' });
    }

    dialog({ message, value, confirmText }) {
        return new Promise((resolve) => {
            const withInput = value !== undefined;
            const input = withInput ? el('input', { class: 'ward-ed-input', type: 'text', value }) : null;
            const cancelButton = el('button', { type: 'button', class: 'ward-ed-btn is-text', text: 'Cancelar' });
            const okButton = el('button', { type: 'button', class: 'ward-ed-btn is-primary', text: confirmText });
            const overlay = el('div', { class: 'ward-ed-dialog-overlay', role: 'presentation' }, [
                el('div', { class: 'ward-ed-dialog', role: 'dialog', 'aria-modal': 'true' }, [
                    el('p', { class: 'ward-ed-dialog-text', text: message }),
                    input,
                    el('div', { class: 'ward-ed-dialog-actions' }, [cancelButton, okButton]),
                ]),
            ]);
            const close = (result) => {
                overlay.remove();
                document.removeEventListener('keydown', onKey, true);
                resolve(result);
            };
            const ok = () => close(withInput ? input.value : true);
            const cancel = () => close(withInput ? null : false);
            const onKey = (event) => {
                if (event.key !== 'Escape' && event.key !== 'Enter') return;
                event.preventDefault();
                event.stopPropagation();
                if (event.key === 'Escape') cancel();
                else ok();
            };
            okButton.addEventListener('click', ok);
            cancelButton.addEventListener('click', cancel);
            overlay.addEventListener('pointerdown', (event) => { if (event.target === overlay) cancel(); });
            document.addEventListener('keydown', onKey, true);
            this.canvas.root.append(overlay);
            (input ?? okButton).focus();
            input?.select();
        });
    }

    send(ops, label) {
        this.pending++;
        this.canvas.hold = true;
        if (label) this.setStatus(label);

        const run = async () => {
            try {
                const response = await this.http(this.config.url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.config.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ ops: ops.map(({ _ref, ...op }) => op) }),
                });
                const json = await response.json().catch(() => ({}));
                if (!response.ok) {
                    this.setStatus(json.message || (response.status === 419
                        ? 'Sua sessão expirou. Recarregue a página.'
                        : 'Não foi possível salvar. Tente de novo.'), true);
                    if (json.state) this.canvas.setState(json.state, true);
                    return null;
                }
                // Artboard criado (duplicar, desfazer um apagar): o histórico passa a usar o id novo.
                for (const [i, op] of ops.entries()) {
                    if ((op.op === 'board.create' || op.op === 'board.duplicate') && op._ref !== undefined && json.results[i]?.id) {
                        this.remap(op._ref, json.results[i].id);
                    }
                }
                this.canvas.setState(json.state, true);
                return json.results;
            } catch {
                this.setStatus('Sem conexão: a mudança não foi salva.', true);
                return null;
            } finally {
                this.pending--;
                this.release();
            }
        };

        const result = this.queue.then(run);
        this.queue = result.catch(() => null);
        return result;
    }

    // ---------- teclado e clique duplo ----------

    bindKeys() {
        document.addEventListener('keydown', (event) => {
            if (event.code === 'Space' && !event.repeat && !event.target.closest('input, textarea, select, [contenteditable="true"], [contenteditable="plaintext-only"]')) {
                this.space = true;
                this.canvas.viewport.dataset.space = 'true';
            }
            if (this.canvas.focusIndex !== null || this.canvas.isMobile()) return;
            if (event.target.closest('input, textarea, select, [contenteditable="true"], [contenteditable="plaintext-only"]')) return;

            const mod = isMac ? event.metaKey : event.ctrlKey;
            const key = event.key.toLowerCase();
            let handled = true;

            if (!this.canEdit) {
                if (key === 'c' && !mod) this.setTool('comment');
                else if (key === 'v' && !mod) this.setTool('select');
                else if (key === 'escape' && (this.thread || this.tool !== 'select')) {
                    this.closeThread();
                    this.setTool('select');
                } else handled = false;
                if (handled) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
                return;
            }
            if (key === 'escape' && this.thread) {
                this.closeThread();
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            if (this.inspect) {
                let used = true;
                if (key === 'escape') this.exitBoard();
                else if (key === 'enter') this.editTextInPlace();
                else if (key === 'delete' || key === 'backspace') this.elementAction('delete');
                else if (mod && key === 'd') this.elementAction('duplicate');
                else if (mod && key === 'z' && !event.shiftKey) this.undo();
                else if ((mod && key === 'z' && event.shiftKey) || (mod && key === 'y')) this.redo();
                else used = false;
                if (used) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    return;
                }
                if (key.startsWith('arrow')) return;
            }

            if (mod && key === 'z' && !event.shiftKey) this.undo();
            else if ((mod && key === 'z' && event.shiftKey) || (mod && key === 'y')) this.redo();
            else if (mod && key === 'd') this.duplicate();
            else if (mod && key === 'a') this.setSelection(this.allKeys());
            else if (mod || event.altKey) handled = false;
            else if (key === 'delete' || key === 'backspace') this.deleteSelection();
            else if (key === 'v') this.setTool('select');
            else if (key === 'h') this.setTool('hand');
            else if (key === 'n') this.setTool('note');
            else if (key === 't') this.setTool('title');
            else if (key === 'c') this.setTool('comment');
            else if (key.startsWith('arrow') && this.selection.size) {
                const step = event.shiftKey ? 10 : 1;
                this.nudge(key === 'arrowleft' ? -step : key === 'arrowright' ? step : 0, key === 'arrowup' ? -step : key === 'arrowdown' ? step : 0);
            } else if (key === 'escape' && this.tool !== 'select') this.setTool('select');
            else if (key === 'escape' && this.selection.size) this.setSelection([]);
            else if (key === 'enter' && this.selection.size === 1 && [...this.selection][0].startsWith('n:')) {
                this.editNote(this.noteNode([...this.selection][0].slice(2)));
            } else handled = false;

            if (handled) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);

        document.addEventListener('keyup', (event) => {
            if (event.code === 'Space') {
                this.space = false;
                delete this.canvas.viewport.dataset.space;
            }
        });
    }

    bindDoubleClick() {
        this.canvas.viewport.addEventListener('dblclick', (event) => {
            if (this.canvas.isMobile()) return;
            const note = event.target.closest('[data-note]');
            const name = event.target.closest('.ward-board-name');
            const board = event.target.closest('[data-board]');
            if (this.canEdit && board && !name && this.tool === 'select') {
                event.stopImmediatePropagation();
                const id = Number(board.dataset.board);
                if (this.inspect?.boardId === id && this.inspect.element?.textOnly) this.editTextInPlace();
                else this.enterBoard(id, event.clientX, event.clientY);
                return;
            }
            if (note) {
                event.stopImmediatePropagation();
                this.setSelection([`n:${note.dataset.note}`]);
                this.editNote(note);
            } else if (name) {
                event.stopImmediatePropagation();
                this.renameBoard(name);
            }
        }, true);
    }
}

// ---------- edição visual (elementos dentro do artboard) e Tweaks ----------

let colorProbe = null;
/** Qualquer cor CSS (rgb, oklch, nome...) em #rrggbb; null se transparente ou inválida. */
const toHex = (color) => {
    if (!color || color === 'transparent' || color === 'none') return null;
    if (/^#[0-9a-f]{6}$/i.test(color)) return color.toLowerCase();
    try {
        colorProbe ??= document.createElement('canvas').getContext('2d', { willReadFrequently: true });
        colorProbe.clearRect(0, 0, 1, 1);
        colorProbe.fillStyle = '#000';
        colorProbe.fillStyle = color;
        colorProbe.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = colorProbe.getImageData(0, 0, 1, 1).data;
        if (a === 0) return null;
        return `#${[r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('')}`;
    } catch {
        return null;
    }
};
const firstFamily = (family) => (family || '').split(',')[0].replace(/["']/g, '').trim();
const SHADOWS = {
    none: ['Nenhuma', 'none'],
    soft: ['Suave', '0 1px 3px rgb(0 0 0 / .12)'],
    medium: ['Média', '0 6px 20px rgb(0 0 0 / .14)'],
    strong: ['Forte', '0 18px 50px rgb(0 0 0 / .24)'],
};

const escapeHtml = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** Realce simples de CSS: comentários, seletores, propriedades e valores. */
function highlightCss(text) {
    return text.split('\n').map((line) => {
        if (/^\s*\/\*.*\*\/\s*$/.test(line)) return `<span class="c">${escapeHtml(line)}</span>`;
        const declaration = /^(\s*)([-\w]+)(\s*:\s*)(.*?)(;?)(\s*)$/.exec(line);
        if (declaration && !line.includes('{')) {
            const [, lead, prop, colon, value, semi, tail] = declaration;
            return `${lead}<span class="p">${escapeHtml(prop)}</span>${escapeHtml(colon)}<span class="v">${escapeHtml(value)}</span>${semi}${tail}`;
        }
        if (line.includes('{')) {
            const at = line.indexOf('{');
            return `<span class="s">${escapeHtml(line.slice(0, at))}</span>${escapeHtml(line.slice(at))}`;
        }
        return escapeHtml(line);
    }).join('\n');
}

/**
 * Mini editor de código: números de linha, realce, Tab indenta, prévia enquanto
 * digita (onInput) e aplica com ⌘/Ctrl+Enter ou ao sair (onCommit). Esc volta ao texto inicial.
 */
function codeArea(initial, { label, placeholder = '', onInput, onCommit }) {
    const gutter = el('div', { class: 'ward-ed-code-gutter', 'aria-hidden': 'true' });
    const pre = el('pre', { class: 'ward-ed-code-hl', 'aria-hidden': 'true' });
    const input = el('textarea', {
        class: 'ward-ed-code-input', spellcheck: 'false', autocapitalize: 'off', autocomplete: 'off', 'aria-label': label, placeholder,
    });
    input.value = initial;
    let committed = initial;
    let timer = null;
    const paint = () => {
        pre.innerHTML = `${highlightCss(input.value)}\n`;
        gutter.textContent = Array.from({ length: Math.max(1, input.value.split('\n').length) }, (_, i) => i + 1).join('\n');
        input.style.height = 'auto';
        input.style.height = `${Math.max(input.scrollHeight, 60)}px`;
    };
    const commit = () => {
        clearTimeout(timer);
        if (input.value === committed) return;
        committed = input.value;
        onCommit(input.value);
    };
    input.addEventListener('input', () => {
        paint();
        clearTimeout(timer);
        timer = setTimeout(() => onInput?.(input.value), 200);
    });
    input.addEventListener('keydown', (event) => {
        event.stopPropagation();
        if (event.key === 'Tab') {
            event.preventDefault();
            const { selectionStart: a, selectionEnd: b } = input;
            input.setRangeText('  ', a, b, 'end');
            input.dispatchEvent(new Event('input'));
        } else if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            commit();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            input.value = committed;
            paint();
            onInput?.(committed);
            input.blur();
        }
    });
    input.addEventListener('blur', commit);
    const node = el('div', { class: 'ward-ed-code-area' }, [gutter, el('div', { class: 'ward-ed-code-body' }, [pre, input])]);
    requestAnimationFrame(paint);
    paint();
    return { node, get value() { return input.value; } };
}

function field(label, control, extra = null) {
    return el('label', { class: 'ward-ed-field' }, [el('span', { text: label }), control, extra]);
}

Object.assign(DesignEditor.prototype, {
    initInspector() {
        this.inspect = null;
        this.sources = new Map();
        this.htmlBatch = new Map();
        this.props = el('aside', { class: 'ward-ed-props', hidden: true, 'aria-label': 'Propriedades do elemento' });
        this.canvas.root.append(this.props);
        this.elementBox = el('div', { class: 'ward-ed-element', hidden: true });
        this.canvas.world.append(this.elementBox);
    },

    sourceUrl(boardId) {
        return this.config.sourceUrl.replace(/\/boards\/0\/source$/, `/boards/${boardId}/source`);
    },

    loadSource(boardId) {
        const entry = this.canvas.boards.get(boardId);
        const cached = this.sources.get(boardId);
        // Com edição pendente, o documento em memória é o certo (o servidor ainda não tem).
        if (cached && (this.htmlBatch.has(boardId) || (entry && cached.revision === entry.data.revision))) return Promise.resolve(cached);
        // Duas edições seguidas esperam a mesma leitura (senão uma apagaria a outra).
        this.sourceLoads ??= new Map();
        if (this.sourceLoads.has(boardId)) return this.sourceLoads.get(boardId);
        const load = (async () => {
            const response = await this.http(this.sourceUrl(boardId), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('source');
            const json = await response.json();
            const source = { revision: json.revision, html: json.html, doc: new DOMParser().parseFromString(json.html, 'text/html') };
            this.sources.set(boardId, source);
            return source;
        })();
        this.sourceLoads.set(boardId, load);
        load.finally(() => this.sourceLoads.delete(boardId)).catch(() => {});
        return load;
    },

    sourceNode(doc, path) {
        let node = doc.body;
        if (path === '') return node;
        for (const index of String(path).split('/')) node = node?.children[Number(index)];
        return node ?? null;
    },

    serialize(doc) {
        return `<!doctype html>\n${doc.documentElement.outerHTML}`;
    },

    // ----- seleção de elemento -----

    async enterBoard(boardId, clientX, clientY) {
        const board = this.canvas.boards.get(boardId)?.data;
        if (!board) return;
        const world = this.canvas.toWorld(clientX, clientY);
        const element = await this.canvas.pick(boardId, world.x - board.x, world.y - board.y);
        if (element) this.selectElement(boardId, element);
    },

    selectElement(boardId, element) {
        this.inspect = { boardId, element, path: element.path };
        const board = this.canvas.boards.get(boardId)?.data;
        this.lastPicked = board ? { board: board.name, element } : null;
        if (!this.selection.has(`b:${boardId}`) || this.selection.size !== 1) this.setSelection([`b:${boardId}`]);
        this.drawElement();
        this.renderProps();
        this.renderImages?.();
        this.reportView();
    },

    enterBoardCenter(boardId) {
        const entry = this.canvas.boards.get(boardId);
        if (!entry) return;
        const rect = entry.frame.getBoundingClientRect();
        this.enterBoard(boardId, rect.left + rect.width / 2, rect.top + Math.min(rect.height / 2, 80));
    },

    exitBoard() {
        if (!this.inspect) return;
        this.inspect = null;
        this.elementBox.hidden = true;
        this.props.hidden = true;
        this.renderBar();
    },

    drawElement() {
        const board = this.inspect && this.canvas.boards.get(this.inspect.boardId)?.data;
        if (!board || !this.inspect.element) {
            this.elementBox.hidden = true;
            return;
        }
        const r = this.inspect.element.rect;
        Object.assign(this.elementBox.style, rectStyle({ x: board.x + r.x, y: board.y + r.y, w: r.w, h: r.h }));
        this.elementBox.dataset.tag = this.inspect.element.tag;
        this.elementBox.hidden = false;
    },

    async refreshElement() {
        if (!this.inspect) return;
        const element = await this.canvas.ask(this.inspect.boardId, { ward: 'describe', path: this.inspect.path });
        if (!this.inspect) return;
        if (!element) return this.exitBoard();
        this.inspect.element = element;
        this.drawElement();
        this.renderProps();
    },

    frameSwapped(boardId) {
        if (this.inspect?.boardId === boardId) this.refreshElement();
        if (this.tweaksFor === boardId) this.loadTweaks(boardId);
    },

    // ----- edição -----

    async editElement(mutate, { live = null, label = 'Elemento atualizado', select = undefined } = {}) {
        if (!this.inspect) return;
        const { boardId, path, element } = this.inspect;
        let source;
        try {
            source = await this.loadSource(boardId);
        } catch {
            return this.setStatus('Não consegui ler o HTML deste artboard.', true);
        }
        const node = this.sourceNode(source.doc, path);
        if (!node || node.tagName.toLowerCase() !== element.tag) {
            return this.setStatus('Este elemento é criado por script: peça a mudança à IA (comentário).', true);
        }
        if (live) this.canvas.tell(boardId, { ward: 'apply', path, ...live });
        const result = mutate(node, source.doc);
        if (select !== undefined) this.inspect.path = select ?? result ?? path;
        this.scheduleHtmlSave(boardId, label);
    },

    setStyle(prop, value) {
        this.editElement((node) => {
            if (value === null || value === '') node.style.removeProperty(prop);
            else node.style.setProperty(prop, value);
            if (!node.getAttribute('style')) node.removeAttribute('style');
        }, { live: { style: { [prop]: value || null } }, label: 'Estilo atualizado' });
    },

    setAttr(name, value) {
        this.editElement((node) => {
            if (value === null || value === '') node.removeAttribute(name);
            else node.setAttribute(name, value);
        }, { live: { attrs: { [name]: value || null } }, label: 'Atributo atualizado' });
    },

    setInlineCss(css) {
        const value = css.replace(/\s*\n\s*/g, ' ').trim();
        this.editElement((node) => {
            if (value) node.setAttribute('style', value);
            else node.removeAttribute('style');
        }, { live: { attrs: { style: value || null } }, label: 'CSS atualizado' });
    },

    /** Troca uma regra de um <style> do HTML (achada pelo seletor) pelo texto novo. */
    async setRule(selector, text) {
        if (!this.inspect) return;
        const { boardId } = this.inspect;
        let source;
        try {
            source = await this.loadSource(boardId);
        } catch {
            return this.setStatus('Não consegui ler o HTML deste artboard.', true);
        }
        const norm = (value) => value.replace(/\s+/g, '').replace(/["']/g, '').toLowerCase();
        for (const style of source.doc.querySelectorAll('style')) {
            const css = style.textContent;
            let start = 0;
            for (let k = 0; k < css.length; k++) {
                const ch = css[k];
                if (ch === '/' && css[k + 1] === '*') {
                    const end = css.indexOf('*/', k + 2);
                    if (end < 0) break;
                    k = end + 1;
                    start = k + 1;
                    continue;
                }
                if (ch === '}' || ch === ';') {
                    start = k + 1;
                } else if (ch === '{') {
                    const prelude = css.slice(start, k);
                    if (norm(prelude) === norm(selector)) {
                        let depth = 1;
                        let j = k + 1;
                        while (j < css.length && depth > 0) {
                            if (css[j] === '{') depth++;
                            else if (css[j] === '}') depth--;
                            j++;
                        }
                        const lead = prelude.match(/^\s*/)[0];
                        style.textContent = css.slice(0, start) + lead + text.trim() + css.slice(j);
                        this.scheduleHtmlSave(boardId, 'Regra CSS atualizada');
                        return;
                    }
                    start = k + 1;
                }
            }
        }
        this.setStatus(`Não achei "${selector}" nos <style> do artboard (pode vir de um arquivo externo).`, true);
    },

    copy(text, label = 'Copiado') {
        navigator.clipboard?.writeText(text).then(() => this.setStatus(label), () => this.setStatus('Não consegui copiar.', true));
    },

    cssSection(e) {
        const inlineText = (e.inline || '').split(';').map((x) => x.trim()).filter(Boolean).map((x) => `${x};`).join('\n');
        const own = codeArea(inlineText, {
            label: 'CSS do elemento',
            placeholder: 'color: #111;\npadding: 12px 16px;',
            onInput: (value) => this.canvas.tell(this.inspect.boardId, { ward: 'apply', path: this.inspect.path, attrs: { style: value.replace(/\s*\n\s*/g, ' ').trim() || null } }),
            onCommit: (value) => this.setInlineCss(value),
        });
        const rules = el('div', { class: 'ward-ed-rules' }, [el('p', { class: 'ward-ed-muted', text: 'Lendo as regras…' })]);

        const boardId = this.inspect.boardId;
        const path = this.inspect.path;
        this.canvas.ask(boardId, { ward: 'rules', path }).then((list) => {
            if (this.inspect?.path !== path) return;
            if (!Array.isArray(list) || !list.length) {
                rules.replaceChildren(el('p', { class: 'ward-ed-muted', text: 'Nenhuma regra de <style> se aplica a este elemento.' }));
                return;
            }
            rules.replaceChildren(...list.map((rule) => {
                const body = rule.css.split(';').map((x) => x.trim()).filter(Boolean).map((x) => `  ${x};`).join('\n');
                const text = `${rule.selector} {\n${body}\n}`;
                const area = codeArea(text, {
                    label: `Regra ${rule.selector}`,
                    onInput: (value) => {
                        const inner = value.slice(value.indexOf('{') + 1, value.lastIndexOf('}'));
                        this.canvas.tell(boardId, { ward: 'rule-preview', selector: rule.selector, css: inner.replace(/\s*\n\s*/g, ' ').trim() });
                    },
                    onCommit: (value) => this.setRule(rule.selector, value),
                });
                return el('div', { class: 'ward-ed-rule' }, [
                    el('div', { class: 'ward-ed-rule-head' }, [
                        el('code', { text: rule.media ? `@media ${rule.media}` : 'regra' }),
                        button(`Copiar ${rule.selector}`, null, () => this.copy(area.value, 'Regra copiada'), { text: 'Copiar', class: 'ward-ed-btn is-text is-small' }),
                    ]),
                    area.node,
                ]);
            }));
        });

        return el('section', { class: 'ward-ed-section' }, [
            el('div', { class: 'ward-ed-rule-head' }, [
                el('h3', { text: 'CSS do elemento' }),
                el('span', { class: 'ward-ed-spacer' }),
                button('Copiar o CSS do elemento', null, () => this.copy(own.value, 'CSS copiado'), { text: 'Copiar', class: 'ward-ed-btn is-text is-small' }),
                button('Copiar o HTML do elemento', null, async () => {
                    const source = await this.loadSource(boardId).catch(() => null);
                    const node = source && this.sourceNode(source.doc, path);
                    this.copy(node ? node.outerHTML : e.html, 'HTML copiado');
                }, { text: 'HTML', class: 'ward-ed-btn is-text is-small' }),
            ]),
            own.node,
            el('p', { class: 'ward-ed-muted', text: '⌘Enter ou sair do campo aplica. Esc desfaz a digitação.' }),
            el('h3', { text: 'Regras que se aplicam' }),
            rules,
        ]);
    },

    /** Junta edições seguidas (ex.: arrastar um controle) num passo só do desfazer. */
    scheduleHtmlSave(boardId, label) {
        const source = this.sources.get(boardId);
        if (!source) return;
        if (!this.htmlBatch.has(boardId)) this.htmlBatch.set(boardId, { before: source.html, base: source.revision, label });
        this.canvas.hold = true;
        clearTimeout(this.htmlTimer);
        this.htmlTimer = setTimeout(() => this.flushHtml(), 450);
    },

    async flushHtml() {
        const batches = [...this.htmlBatch.entries()];
        this.htmlBatch.clear();
        for (const [boardId, batch] of batches) {
            const source = this.sources.get(boardId);
            const after = this.serialize(source.doc);
            if (after === batch.before) continue;
            const entry = this.canvas.boards.get(boardId);
            const results = await this.send([{ op: 'board.html', id: boardId, html: after, base: batch.base }], 'Salvando…');
            if (!results) {
                this.sources.delete(boardId); // o servidor recusou: relê o HTML na próxima edição
                continue;
            }
            source.html = after;
            source.revision = results[0].revision;
            if (entry) entry.quietRevision = results[0].revision;
            this.push({
                redo: [{ op: 'board.html', id: boardId, html: after }],
                undo: [{ op: 'board.html', id: boardId, html: batch.before }],
            });
            this.setStatus(batch.label);
        }
        this.release();
    },

    textEdited(boardId, message) {
        const entry = this.canvas.boards.get(boardId);
        entry?.node.classList.remove('is-editing-text');
        if (message.text === null || message.text === message.before || this.inspect?.boardId !== boardId) return;
        this.editElement((node) => {
            node.textContent = message.text;
        }, { label: 'Texto atualizado' });
    },

    editTextInPlace() {
        if (!this.inspect?.element?.textOnly) return;
        const entry = this.canvas.boards.get(this.inspect.boardId);
        entry.node.classList.add('is-editing-text');
        entry.frame.focus();
        this.canvas.tell(this.inspect.boardId, { ward: 'edit-text', path: this.inspect.path });
    },

    // ----- ações de estrutura -----

    elementAction(action) {
        const path = this.inspect?.path;
        if (path === undefined) return;
        const parts = path === '' ? [] : path.split('/').map(Number);
        const index = parts[parts.length - 1];
        const parentPath = parts.slice(0, -1).join('/');

        if (action === 'parent') {
            if (!parts.length) return;
            this.inspect.path = parentPath;
            return this.refreshElement();
        }
        if (action === 'duplicate') {
            return this.editElement((node) => node.after(node.cloneNode(true)), { label: 'Elemento duplicado', select: [...parts.slice(0, -1), index + 1].join('/') });
        }
        if (action === 'delete') {
            this.editElement((node) => node.remove(), { label: 'Elemento apagado' }).then(() => this.exitBoard());
            return;
        }
        if (action === 'up' || action === 'down') {
            return this.editElement((node) => {
                const sibling = action === 'up' ? node.previousElementSibling : node.nextElementSibling;
                if (!sibling) return path;
                if (action === 'up') sibling.before(node);
                else sibling.after(node);
                return [...parts.slice(0, -1), index + (action === 'up' ? -1 : 1)].join('/');
            }, { label: 'Elemento movido', select: null });
        }
        if (action === 'flex' || action === 'grid') {
            return this.editElement((node, doc) => {
                const wrapper = doc.createElement('div');
                wrapper.setAttribute('style', action === 'flex'
                    ? 'display: flex; align-items: center; gap: 12px'
                    : 'display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px');
                node.replaceWith(wrapper);
                wrapper.append(node);
            }, { label: action === 'flex' ? 'Envolvido em flex' : 'Envolvido em grid', select: path });
        }
    },

    // ----- painel de propriedades -----

    renderProps() {
        if (!this.inspect?.element || this.canvas.isMobile()) {
            this.props.hidden = true;
            return;
        }
        if (this.props.contains(document.activeElement) && /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
            this.propsDirty = true;
            return; // não troca o painel no meio de uma digitação
        }
        this.panel.hidden = true;
        const e = this.inspect.element;
        const st = e.styles || {};
        const inline = {};
        for (const part of (e.inline || '').split(';')) {
            const [k, ...v] = part.split(':');
            if (k?.trim()) inline[k.trim()] = v.join(':').trim();
        }
        const value = (prop) => inline[prop] ?? '';
        const text = (prop, placeholder) => {
            const input = el('input', { type: 'text', value: value(prop), placeholder: placeholder ?? st[prop] ?? '' });
            input.addEventListener('change', () => this.setStyle(prop, input.value.trim()));
            input.addEventListener('keydown', (event) => event.stopPropagation());
            return input;
        };
        const color = (prop) => {
            const current = toHex(inline[prop] || st[prop]) || '#000000';
            const picker = el('input', { type: 'color', value: current });
            const hex = el('input', { type: 'text', value: inline[prop] ?? '', placeholder: toHex(st[prop]) ?? 'transparente', class: 'ward-ed-hex' });
            picker.addEventListener('input', () => {
                hex.value = picker.value;
                this.setStyle(prop, picker.value);
            });
            hex.addEventListener('change', () => this.setStyle(prop, hex.value.trim()));
            hex.addEventListener('keydown', (event) => event.stopPropagation());
            return el('span', { class: 'ward-ed-color' }, [picker, hex]);
        };
        const select = (prop, options) => {
            const input = el('select', {}, [el('option', { value: '', text: `— (${st[prop] || 'padrão'})` }),
                ...options.map(([v, label]) => el('option', { value: v, text: label, selected: value(prop) === v }))]);
            input.addEventListener('change', () => this.setStyle(prop, input.value));
            return input;
        };
        const section = (title, children) => el('section', { class: 'ward-ed-section' }, [el('h3', { text: title }), ...children.filter(Boolean)]);
        const act = (label, iconName, action, cls = '') => button(label, iconName, () => this.elementAction(action), { class: `ward-ed-btn ${cls}`.trim() });

        const shadowSelect = el('select', {}, Object.entries(SHADOWS).map(([key, [label, css]]) => el('option', { value: css, text: label, selected: value('box-shadow') === css })));
        shadowSelect.prepend(el('option', { value: '', text: `— (${st['box-shadow'] === 'none' ? 'nenhuma' : 'atual'})`, selected: !Object.values(SHADOWS).some(([, css]) => css === value('box-shadow')) }));
        shadowSelect.addEventListener('change', () => this.setStyle('box-shadow', shadowSelect.value));

        const opacity = el('input', { type: 'range', min: '0', max: '100', value: String(Math.round(Number(st.opacity ?? 1) * 100)) });
        opacity.addEventListener('input', () => this.setStyle('opacity', String(Number(opacity.value) / 100)));


        const isFlex = (st.display || '').includes('flex') || (st.display || '').includes('grid');
        const attrFields = [];
        if (e.tag === 'img') {
            for (const name of ['src', 'alt']) {
                const input = el('input', { type: 'text', value: e.attrs?.[name] ?? '' });
                input.addEventListener('change', () => this.setAttr(name, input.value.trim()));
                input.addEventListener('keydown', (event) => event.stopPropagation());
                attrFields.push(field(name === 'src' ? 'Imagem (URL)' : 'Texto alternativo', input));
            }
        }
        if (e.tag === 'a') {
            const input = el('input', { type: 'text', value: e.attrs?.href ?? '', placeholder: 'https://… ou board:Tela' });
            input.addEventListener('change', () => this.setAttr('href', input.value.trim()));
            input.addEventListener('keydown', (event) => event.stopPropagation());
            attrFields.push(field('Link', input));
        }

        const board = this.canvas.boards.get(this.inspect.boardId)?.data;
        if (e.component) {
            const target = this.canvas.state.boards.find((item) => item.name === e.component);
            this.props.replaceChildren(
                el('div', { class: 'ward-ed-props-head' }, [el('strong', { text: `<${e.tag}>` }), button('Fechar (Esc)', 'close', () => this.exitBoard())]),
                el('p', { class: 'ward-ed-hint', text: `Este elemento vem do componente "${e.component}". Edite o componente: a mudança aparece em todo artboard que o usa.` }),
                target ? button(`Abrir o componente ${e.component}`, null, () => {
                    this.exitBoard();
                    if (!this.canvas.inPage(target)) this.canvas.setPage(this.canvas.pageOf(target));
                    this.setSelection([`b:${target.id}`]);
                    this.canvas.fit([target]);
                }, { text: `Abrir "${e.component}"`, class: 'ward-ed-btn is-primary' }) : el('p', { class: 'ward-ed-muted', text: 'O componente vem de um design system: abra-o pelo painel Componentes.' }),
            );
            this.props.hidden = false;
            return;
        }
        this.props.replaceChildren(...[
            el('div', { class: 'ward-ed-props-head' }, [
                el('div', {}, [el('strong', { text: `<${e.tag}>` }), el('span', { class: 'ward-ed-muted', text: ` ${board?.title || board?.name || ''}` })]),
                el('span', { class: 'ward-ed-spacer' }),
                button(this.props.classList.contains('is-wide') ? 'Estreitar o painel' : 'Alargar o painel', null, () => {
                    this.props.classList.toggle('is-wide');
                    this.renderProps();
                }, { text: this.props.classList.contains('is-wide') ? '⇥' : '⇤', class: 'ward-ed-btn' }),
                button('Fechar (Esc)', 'close', () => this.exitBoard()),
            ]),
            e.text ? el('p', { class: 'ward-ed-muted ward-ed-snippet', text: `“${e.text.slice(0, 80)}”` }) : null,
            el('div', { class: 'ward-ed-props-actions' }, [
                button('Selecionar o pai', null, () => this.elementAction('parent'), { text: 'Pai', class: 'ward-ed-btn is-text' }),
                act('Duplicar elemento', 'duplicate', 'duplicate'),
                button('Subir', null, () => this.elementAction('up'), { text: '↑', class: 'ward-ed-btn' }),
                button('Descer', null, () => this.elementAction('down'), { text: '↓', class: 'ward-ed-btn' }),
                button('Envolver em flex', null, () => this.elementAction('flex'), { text: 'Flex', class: 'ward-ed-btn is-text' }),
                button('Envolver em grid', null, () => this.elementAction('grid'), { text: 'Grid', class: 'ward-ed-btn is-text' }),
                act('Apagar elemento', 'trash', 'delete', 'is-danger'),
            ]),
            e.textOnly ? el('p', { class: 'ward-ed-hint', text: 'Clique duplo no texto (ou Enter) para editar no lugar.' }) : null,
            section('Tipografia', [
                field('Fonte', text('font-family', firstFamily(st['font-family']))),
                el('div', { class: 'ward-ed-row' }, [field('Tamanho', text('font-size')), field('Peso', select('font-weight', ['300', '400', '500', '600', '700', '800', '900'].map((w) => [w, w])))]),
                el('div', { class: 'ward-ed-row' }, [field('Altura da linha', text('line-height')), field('Espaçamento', text('letter-spacing'))]),
                field('Cor do texto', color('color')),
                field('Alinhamento', select('text-align', [['left', 'Esquerda'], ['center', 'Centro'], ['right', 'Direita'], ['justify', 'Justificado']])),
            ]),
            section('Preenchimento e borda', [
                field('Fundo', color('background-color')),
                el('div', { class: 'ward-ed-row' }, [field('Raio', text('border-radius')), field('Borda', text('border', st.border?.startsWith('0px') ? 'ex.: 1px solid #ddd' : st.border))]),
                field('Sombra', shadowSelect),
                field(`Opacidade`, opacity),
            ]),
            section('Espaço e tamanho', [
                el('div', { class: 'ward-ed-row' }, [field('Padding', text('padding')), field('Margem', text('margin'))]),
                el('div', { class: 'ward-ed-row' }, [field('Largura', text('width')), field('Altura', text('height'))]),
            ]),
            section('Layout', [
                field('Exibição', select('display', [['block', 'Bloco'], ['flex', 'Flex'], ['grid', 'Grid'], ['inline-block', 'Em linha'], ['none', 'Oculto']])),
                isFlex ? el('div', { class: 'ward-ed-row' }, [
                    field('Direção', select('flex-direction', [['row', 'Linha'], ['column', 'Coluna']])),
                    field('Espaço (gap)', text('gap')),
                ]) : null,
                isFlex ? el('div', { class: 'ward-ed-row' }, [
                    field('Distribuir', select('justify-content', [['flex-start', 'Início'], ['center', 'Centro'], ['flex-end', 'Fim'], ['space-between', 'Entre']])),
                    field('Alinhar', select('align-items', [['stretch', 'Esticar'], ['flex-start', 'Início'], ['center', 'Centro'], ['flex-end', 'Fim']])),
                ]) : null,
            ]),
            attrFields.length ? section('Atributos', attrFields) : null,
            this.cssSection(e),
        ].filter(Boolean));
        this.props.hidden = false;
    },

    // ----- Tweaks -----

    async loadTweaks(boardId) {
        const tweaks = await this.canvas.ask(boardId, { ward: 'tweaks' });
        this.tweaksFor = boardId;
        this.tweaks = tweaks && typeof tweaks === 'object' ? tweaks : null;
        this.renderBar();
        if (this.tweaksOpen) this.renderTweaks();
    },

    renderTweaks() {
        if (!this.tweaks || !this.tweaksOpen) return;
        const boardId = this.tweaksFor;
        const controls = Object.entries(this.tweaks).map(([key, def]) => {
            const label = def.label || key;
            let input;
            if (def.type === 'color') {
                input = el('input', { type: 'color', value: toHex(def.value) || '#000000' });
            } else if (def.type === 'range') {
                input = el('input', { type: 'range', min: String(def.min ?? 0), max: String(def.max ?? 100), step: String(def.step ?? 1), value: String(def.value ?? 0) });
            } else if (def.type === 'boolean') {
                input = el('input', { type: 'checkbox', checked: Boolean(def.value) });
            } else if (def.type === 'enum') {
                input = el('select', {}, (def.options || []).map((option) => el('option', { value: option, text: option, selected: option === def.value })));
            } else {
                input = el('input', { type: 'text', value: String(def.value ?? '') });
                input.addEventListener('keydown', (event) => event.stopPropagation());
            }
            const read = () => (def.type === 'boolean' ? input.checked : def.type === 'range' ? Number(input.value) : input.value);
            const show = el('span', { class: 'ward-ed-muted', text: def.type === 'range' ? `${def.value}${def.unit || ''}` : '' });
            input.addEventListener(def.type === 'text' ? 'change' : 'input', () => {
                const v = read();
                def.value = v;
                if (def.type === 'range') show.textContent = `${v}${def.unit || ''}`;
                this.canvas.tell(boardId, { ward: 'tweak', key, value: v });
                this.saveTweak(boardId, key, v);
            });
            return field(label, input, show);
        });
        this.props.replaceChildren(
            el('div', { class: 'ward-ed-props-head' }, [el('strong', { text: 'Tweaks' }), button('Fechar', 'close', () => { this.tweaksOpen = false; this.props.hidden = true; })]),
            el('p', { class: 'ward-ed-hint', text: 'Controles que a IA deixou neste artboard. A mudança vale para o arquivo salvo.' }),
            section('Ajustes', controls),
        );
        this.props.hidden = false;

        function section(title, children) {
            return el('section', { class: 'ward-ed-section' }, [el('h3', { text: title }), ...children]);
        }
    },

    async saveTweak(boardId, key, value) {
        let source;
        try {
            source = await this.loadSource(boardId);
        } catch {
            return;
        }
        const node = source.doc.getElementById('ward-tweaks');
        if (!node) return;
        try {
            const tweaks = JSON.parse(node.textContent);
            if (!tweaks[key]) return;
            tweaks[key].value = value;
            node.textContent = `\n${JSON.stringify(tweaks, null, 2)}\n`;
        } catch {
            return;
        }
        this.scheduleHtmlSave(boardId, 'Tweak aplicado');
    },
});

// ---------- imagens do design e exportação ----------

Object.assign(DesignEditor.prototype, {
    initAssets() {
        this.exportMenu = el('div', { class: 'ward-ed-menu', hidden: true, role: 'menu', 'aria-label': 'Exportar' });
        this.canvas.root.append(this.exportMenu);
        document.addEventListener('pointerdown', (event) => {
            if (!this.exportMenu.hidden && !event.target.closest('.ward-ed-menu') && event.target.closest('button') !== this.exportButton) this.toggleExport(false);
        }, true);
        if (!this.canEdit) return;

        this.assets = [];
        this.images = el('aside', { class: 'ward-ed-panel ward-ed-images', hidden: true, 'aria-label': 'Imagens do design' });
        this.canvas.root.append(this.images);

        // Arrastar arquivos para o canvas e colar imagens enviam para a biblioteca.
        const viewport = this.canvas.viewport;
        viewport.addEventListener('dragover', (event) => {
            if ([...(event.dataTransfer?.types || [])].includes('Files')) {
                event.preventDefault();
                viewport.classList.add('is-dropping');
            }
        });
        viewport.addEventListener('dragleave', () => viewport.classList.remove('is-dropping'));
        viewport.addEventListener('drop', (event) => {
            viewport.classList.remove('is-dropping');
            const files = [...(event.dataTransfer?.files || [])].filter((file) => file.type.startsWith('image/'));
            if (!files.length) return;
            event.preventDefault();
            this.upload(files);
        });
        document.addEventListener('paste', (event) => {
            if (event.target.closest('input, textarea, [contenteditable="true"], [contenteditable="plaintext-only"]')) return;
            const files = [...(event.clipboardData?.files || [])].filter((file) => file.type.startsWith('image/'));
            if (!files.length) return;
            event.preventDefault();
            this.upload(files);
        });
    },

    // ----- exportar -----

    toggleExport(force) {
        const open = force ?? this.exportMenu.hidden;
        this.exportMenu.hidden = !open;
        this.exportButton.setAttribute('aria-expanded', String(open));
        if (!open) return;
        const selected = [...this.selection].filter((key) => key.startsWith('b:')).map((key) => Number(key.slice(2)));
        const single = selected.length === 1 ? this.canvas.boards.get(selected[0])?.data : null;
        const item = (label, action, disabled = false) => {
            const node = el('button', { type: 'button', role: 'menuitem', class: 'ward-ed-menu-item', text: label, disabled });
            node.addEventListener('click', () => {
                this.toggleExport(false);
                action();
            });
            return node;
        };
        const hint = single ? `“${single.title || single.name}”` : 'selecione um artboard';
        this.exportMenu.replaceChildren(
            el('div', { class: 'ward-ed-menu-label', text: `Artboard: ${hint}` }),
            item('PNG', () => this.exportPng(single, 1), !single),
            item('PNG em alta (2×)', () => this.exportPng(single, 2), !single),
            item('PDF deste artboard', () => this.exportPdf([single.id]), !single),
            el('div', { class: 'ward-ed-menu-sep' }),
            selected.length > 1 ? item(`PDF dos ${selected.length} selecionados`, () => this.exportPdf(selected)) : null,
            item(`PDF de todos (${this.canvas.state.boards.length})`, () => this.exportPdf([]), !this.canvas.state.boards.length),
        );
        const rect = this.exportButton.getBoundingClientRect();
        const rootRect = this.canvas.root.getBoundingClientRect();
        Object.assign(this.exportMenu.style, { left: `${rect.left - rootRect.left}px`, bottom: `${rootRect.bottom - rect.top + 8}px` });
    },

    async exportPng(board, scale) {
        const entry = this.canvas.boards.get(board.id);
        if (!entry.loaded) {
            entry.frame.src = board.url;
            entry.loaded = true;
            await new Promise((resolve) => entry.frame.addEventListener('load', resolve, { once: true }));
        }
        this.setStatus('Gerando PNG…');
        const result = await this.canvas.ask(board.id, { ward: 'export-png', scale }, 60000);
        if (!result?.url) return this.setStatus(result?.error ? `PNG: ${result.error}` : 'Não consegui gerar o PNG.', true);
        const link = el('a', { href: result.url, download: `${board.name}${scale > 1 ? `@${scale}x` : ''}.png` });
        document.body.append(link);
        link.click();
        link.remove();
        this.setStatus('PNG baixado');
    },

    exportPdf(ids) {
        if (ids.length === 1) {
            const board = this.canvas.boards.get(ids[0])?.data;
            if (board) window.open(`${board.url}${board.url.includes('?') ? '&' : '?'}print=1`, '_blank', 'noopener');
            return;
        }
        const url = `${this.collab.printUrl}${ids.length ? `?boards=${ids.join(',')}` : ''}`;
        window.open(url, '_blank', 'noopener');
    },

    // ----- imagens -----

    toggleImages(force) {
        const open = force ?? this.images.hidden;
        this.images.hidden = !open;
        this.imagesButton.setAttribute('aria-pressed', String(open));
        if (open) {
            this.panel.hidden = true;
            this.loadAssets();
        }
        this.renderImages();
    },

    async loadAssets() {
        try {
            const response = await this.http(this.config.assetsUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            this.assets = (await response.json()).assets ?? [];
        } catch {
            this.assets = [];
        }
        this.renderImages();
    },

    async upload(files) {
        for (const file of files) {
            this.setStatus(`Enviando ${file.name}…`);
            const form = new FormData();
            form.append('file', file);
            try {
                const response = await this.http(this.config.assetsUrl, {
                    method: 'POST', credentials: 'same-origin', body: form,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const json = await response.json().catch(() => ({}));
                if (!response.ok) {
                    this.setStatus(json.message || json.errors?.file?.[0] || `Não consegui enviar ${file.name}.`, true);
                    continue;
                }
                this.assets = json.assets ?? this.assets;
                this.setStatus(`Imagem pronta: ${json.asset.path}`);
            } catch {
                this.setStatus('Sem conexão.', true);
            }
        }
        if (this.images.hidden) this.toggleImages(true);
        else this.renderImages();
    },

    useAsset(asset) {
        const element = this.inspect?.element;
        if (!element) {
            this.copy(asset.path, `Caminho copiado: ${asset.path}`);
            return;
        }
        if (element.tag === 'img') {
            this.setAttr('src', asset.path);
            if (!element.attrs?.alt) this.setAttr('alt', asset.original_name?.replace(/\.[a-z0-9]+$/i, '') ?? '');
        } else {
            this.editElement((node) => {
                node.style.setProperty('background-image', `url(${asset.path})`);
                node.style.setProperty('background-size', 'cover');
                node.style.setProperty('background-position', 'center');
            }, { live: { style: { 'background-image': `url(${asset.path})`, 'background-size': 'cover', 'background-position': 'center' } }, label: 'Imagem de fundo aplicada' });
        }
    },

    async removeAsset(asset) {
        if (!(await this.confirmDialog(`Apagar ${asset.name}? Artboards que usam a imagem ficam sem ela.`, 'Apagar'))) return;
        const response = await this.http(`${this.config.assetsUrl}/${encodeURIComponent(asset.name)}`, {
            method: 'DELETE', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (response.ok) this.assets = (await response.json()).assets ?? [];
        this.renderImages();
    },

    renderImages() {
        if (!this.images || this.images.hidden) return;
        const input = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp,image/gif,image/avif,image/svg+xml', multiple: true, hidden: true });
        input.addEventListener('change', () => this.upload([...input.files]));
        const target = this.inspect?.element;
        const hint = target
            ? (target.tag === 'img' ? 'Clique numa imagem para trocar a do <img> selecionado.' : `Clique numa imagem para usar como fundo do <${target.tag}>.`)
            : 'Arraste arquivos para o canvas ou cole (⌘V). Clique numa imagem para copiar o caminho; com um elemento selecionado, ela é aplicada nele.';

        const grid = this.assets.map((asset) => {
            const use = el('button', { type: 'button', class: 'ward-ed-asset', title: `${asset.name}${asset.width ? ` · ${asset.width}×${asset.height}` : ''}` }, [
                el('img', { src: asset.url, alt: asset.original_name || asset.name, loading: 'lazy' }),
            ]);
            use.addEventListener('click', () => this.useAsset(asset));
            const remove = button(`Apagar ${asset.name}`, 'close', () => this.removeAsset(asset), { class: 'ward-ed-btn ward-ed-asset-remove' });
            return el('div', { class: 'ward-ed-asset-cell' }, [use, remove]);
        });

        this.images.replaceChildren(
            el('div', { class: 'ward-ed-panel-head' }, [el('strong', { text: 'Imagens' }), button('Fechar', 'close', () => this.toggleImages(false))]),
            el('div', { class: 'ward-ed-thread-actions' }, [
                button('Enviar imagens', null, () => input.click(), { text: 'Enviar imagens', class: 'ward-ed-btn is-primary' }), input,
            ]),
            el('p', { class: 'ward-ed-hint', text: hint }),
            grid.length ? el('div', { class: 'ward-ed-assets' }, grid) : el('p', { class: 'ward-ed-muted ward-ed-empty', text: 'Nenhuma imagem ainda.' }),
        );
    },
});

// ---------- páginas e tema (tokens) ----------

Object.assign(DesignEditor.prototype, {
    decoratePages(bar) {
        if (!this.canEdit || this.canvas.isMobile()) return;
        for (const tab of bar.querySelectorAll('[data-page]')) {
            tab.addEventListener('dblclick', () => this.renamePage(tab.dataset.page));
            tab.addEventListener('contextmenu', (event) => {
                event.preventDefault();
                this.deletePage(tab.dataset.page);
            });
            tab.title += ' · clique duplo renomeia, botão direito apaga';
        }
        const add = el('button', { type: 'button', class: 'ward-page-tab ward-page-add', text: '+ Página', title: 'Nova página' });
        add.addEventListener('click', () => this.addPage());
        bar.append(add);
    },

    async addPage() {
        const pages = this.canvas.pages();
        const key = `p${Date.now().toString(36)}`;
        const ops = [];
        // A primeira página nova também cria a "Página 1" para o que já existe.
        if (!pages.length) ops.push({ op: 'page.upsert', key: 'principal', name: 'Página 1', sort: 0 });
        ops.push({ op: 'page.upsert', key, name: `Página ${pages.length + (pages.length ? 1 : 2)}`, sort: pages.length + 1 });
        const results = await this.send(ops, 'Criando página…');
        if (!results) return;
        this.push({ redo: ops, undo: [...ops].reverse().map((op) => ({ op: 'page.delete', key: op.key })) });
        this.canvas.setPage(key);
        this.setStatus('Página criada. Clique duplo no nome para renomear.');
    },

    async renamePage(key) {
        const page = this.canvas.pages().find((item) => item.key === key);
        const name = await this.promptDialog('Nome da página', page?.name ?? '');
        if (!name || !name.trim() || name.trim() === page.name) return;
        this.commit([{ op: 'page.upsert', key, name: name.trim() }], [{ op: 'page.upsert', key, name: page.name }], 'Página renomeada');
    },

    async deletePage(key) {
        const page = this.canvas.pages().find((item) => item.key === key);
        if (!page || !(await this.confirmDialog(`Apagar a página "${page.name}"? Os artboards e notas dela vão para a primeira página.`, 'Apagar'))) return;
        const moved = this.canvas.state.boards.filter((board) => board.page === key).map((board) => ({ op: 'board.update', id: board.id, page: key }));
        const notes = this.canvas.state.notes.filter((note) => note.page === key).map((note) => ({ op: 'note.upsert', key: note.key, page: key }));
        const results = await this.send([{ op: 'page.delete', key }], 'Apagando página…');
        if (!results) return;
        this.push({ redo: [{ op: 'page.delete', key }], undo: [{ op: 'page.upsert', key, name: page.name }, ...moved, ...notes] });
        this.canvas.setPage(null);
    },

    moveToPage(key) {
        const redo = [];
        const undo = [];
        for (const item of this.selection) {
            if (item.startsWith('b:')) {
                const data = this.boardEntry(item).data;
                redo.push({ op: 'board.update', id: data.id, page: key });
                undo.push({ op: 'board.update', id: data.id, page: data.page ?? '' });
            } else {
                const note = this.noteData(item);
                redo.push({ op: 'note.upsert', key: note.key, page: key });
                undo.push({ op: 'note.upsert', key: note.key, page: note.page ?? '' });
            }
        }
        this.setSelection([]);
        const name = this.canvas.pages().find((page) => page.key === key)?.name;
        this.commit(redo, undo, `Movido para "${name}"`);
    },

    // ----- tema -----

    toggleTheme(force) {
        const open = force ?? (this.props.hidden || !this.themeOpen);
        this.themeOpen = open;
        if (!open) {
            this.props.hidden = true;
            return;
        }
        this.exitBoard();
        this.tweaksOpen = false;
        this.panel.hidden = true;
        this.renderTheme();
    },

    saveTokens(css, label = 'Tema atualizado') {
        const before = this.canvas.state.tokens ?? '';
        if (css.trim() === before.trim()) return;
        this.canvas.state.tokens = css;
        this.commit([{ op: 'design.tokens', css }], [{ op: 'design.tokens', css: before }], label);
    },

    renderTheme() {
        if (!this.themeOpen) return;
        const css = this.canvas.state.tokens ?? '';
        const tokens = [...css.matchAll(/(--[A-Za-z0-9_-]+)\s*:\s*([^;}]+);?/g)].map((m) => [m[1], m[2].trim()]);
        const write = (list) => list.map(([name, value]) => `${name}: ${value};`).join('\n');

        const rows = tokens.map(([name, value], index) => {
            const hex = toHex(value);
            const input = el('input', { type: 'text', value });
            input.addEventListener('keydown', (event) => event.stopPropagation());
            input.addEventListener('change', () => {
                tokens[index][1] = input.value.trim();
                this.saveTokens(write(tokens));
            });
            const controls = [input];
            if (hex && !/var\(|gradient|url\(/.test(value)) {
                const picker = el('input', { type: 'color', value: hex });
                picker.addEventListener('change', () => {
                    tokens[index][1] = picker.value;
                    this.saveTokens(write(tokens));
                });
                controls.unshift(picker);
            }
            const remove = button(`Remover ${name}`, 'close', () => {
                tokens.splice(index, 1);
                this.saveTokens(write(tokens), 'Token removido');
            }, { class: 'ward-ed-btn is-small' });
            return el('div', { class: 'ward-ed-token' }, [el('code', { text: name }), el('span', { class: 'ward-ed-color' }, controls), remove]);
        });

        const newName = el('input', { type: 'text', placeholder: '--accent' });
        const newValue = el('input', { type: 'text', placeholder: '#c2410c' });
        for (const input of [newName, newValue]) input.addEventListener('keydown', (event) => event.stopPropagation());
        const add = button('Adicionar token', null, () => {
            const name = newName.value.trim().replace(/^(?!--)/, '--');
            if (!/^--[A-Za-z0-9_-]+$/.test(name) || !newValue.value.trim()) return this.setStatus('Use um nome como --accent e um valor.', true);
            this.saveTokens(write([...tokens, [name, newValue.value.trim()]]), 'Token adicionado');
        }, { text: 'Adicionar', class: 'ward-ed-btn is-primary' });

        const raw = codeArea(css, { label: 'Tokens do design (CSS)', placeholder: '--ink: #1c1917;\n--accent: #c2410c;', onCommit: (value) => this.saveTokens(value) });

        this.props.replaceChildren(
            el('div', { class: 'ward-ed-props-head' }, [el('strong', { text: 'Tema do design' }), button('Fechar', 'close', () => this.toggleTheme(false))]),
            el('p', { class: 'ward-ed-hint', text: 'Variáveis CSS que entram no :root de todos os artboards. Use var(--nome) no CSS das telas.' }),
            el('section', { class: 'ward-ed-section' }, [el('h3', { text: 'Tokens' }), ...(rows.length ? rows : [el('p', { class: 'ward-ed-muted', text: 'Nenhum token ainda.' })])]),
            el('section', { class: 'ward-ed-section' }, [el('h3', { text: 'Novo token' }), el('div', { class: 'ward-ed-row' }, [newName, newValue]), add]),
            el('section', { class: 'ward-ed-section' }, [
                el('div', { class: 'ward-ed-rule-head' }, [el('h3', { text: 'CSS' }), el('span', { class: 'ward-ed-spacer' }),
                    button('Copiar os tokens', null, () => this.copy(raw.value, 'Tokens copiados'), { text: 'Copiar', class: 'ward-ed-btn is-text is-small' })]),
                raw.node,
            ]),
        );
        this.props.hidden = false;
    },
});

// ---------- design systems: componentes ----------

Object.assign(DesignEditor.prototype, {
    toggleComponents(force) {
        this.componentsPanel ??= (() => {
            const node = el('aside', { class: 'ward-ed-panel ward-ed-components', hidden: true, 'aria-label': 'Componentes de design systems' });
            this.canvas.root.append(node);
            return node;
        })();
        const open = force ?? this.componentsPanel.hidden;
        this.componentsPanel.hidden = !open;
        this.componentsButton.setAttribute('aria-pressed', String(open));
        if (open) {
            this.panel.hidden = true;
            if (this.images) this.images.hidden = true;
            this.loadSystems();
        }
    },

    async loadSystems(query = '') {
        const url = `${this.collab.systemsUrl}?q=${encodeURIComponent(query)}&prefer=${encodeURIComponent(this.collab.projectSystem ?? '')}`;
        try {
            this.systems = (await (await this.http(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json()).design_systems ?? [];
        } catch {
            this.systems = [];
        }
        if (!this.activeSystem || !this.systems.some((system) => system.ref === this.activeSystem.ref)) {
            this.activeSystem = this.systems.find((system) => system.ref === this.collab.projectSystem) ?? this.systems[0] ?? null;
            this.systemState = null;
        }
        await this.loadSystemState();
        this.renderComponents();
    },

    async loadSystemState() {
        if (!this.activeSystem) return;
        try {
            this.systemState = await (await this.http(this.activeSystem.live_url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
        } catch {
            this.systemState = null;
        }
    },

    renderComponents() {
        const panel = this.componentsPanel;
        if (!panel || panel.hidden) return;
        const query = this.componentQuery ?? '';
        const select = el('select', { class: 'ward-ed-select', 'aria-label': 'Design system' }, this.systems.map((system) => el('option', {
            value: system.ref, text: `${system.title} · ${system.ref}`, selected: system.ref === this.activeSystem?.ref,
        })));
        select.addEventListener('change', async () => {
            this.activeSystem = this.systems.find((system) => system.ref === select.value);
            this.systemState = null;
            await this.loadSystemState();
            this.renderComponents();
        });
        const search = el('input', { type: 'search', class: 'ward-filter', placeholder: 'Filtrar componentes', value: query, 'aria-label': 'Filtrar componentes' });
        search.addEventListener('keydown', (event) => event.stopPropagation());
        search.addEventListener('input', () => {
            this.componentQuery = search.value.trim().toLowerCase();
            clearTimeout(this.componentTimer);
            this.componentTimer = setTimeout(() => {
                this.renderComponents();
                const again = panel.querySelector('input[type=search]');
                again?.focus();
                again?.setSelectionRange(again.value.length, again.value.length);
            }, 200);
        });

        const boards = (this.systemState?.boards ?? []).filter((board) => !query
            || `${board.name} ${board.title ?? ''} ${board.description ?? ''} ${board.tags ?? ''}`.toLowerCase().includes(query));
        const cards = boards.map((board) => {
            const scale = Math.min(1, 268 / board.w, 160 / board.h);
            const preview = el('div', { class: 'ward-ed-cmp-preview', style: { height: `${Math.round(board.h * scale)}px` } }, [
                el('iframe', {
                    src: board.url, title: board.title || board.name, loading: 'lazy', tabindex: '-1', referrerpolicy: 'no-referrer', allowtransparency: 'true',
                    sandbox: this.canvas.config.sandbox, style: { width: `${board.w}px`, height: `${board.h}px`, transform: `scale(${scale})` },
                }),
            ]);
            return el('div', { class: 'ward-ed-cmp' }, [
                preview,
                el('strong', { text: board.title || board.name }),
                board.description ? el('p', { class: 'ward-ed-muted', text: board.description }) : null,
                el('div', { class: 'ward-ed-thread-actions' }, [
                    button(`Inserir ${board.name} vinculado`, null, () => this.importComponent(board, 'link'), { text: 'Vincular', class: 'ward-ed-btn is-primary is-small' }),
                    button(`Inserir uma cópia de ${board.name}`, null, () => this.importComponent(board, 'copy'), { text: 'Copiar', class: 'ward-ed-btn is-text is-small' }),
                    el('span', { class: 'ward-ed-spacer' }),
                    el('span', { class: 'ward-ed-muted', text: `${board.w}×${board.h}` }),
                ]),
            ]);
        });

        panel.replaceChildren(
            el('div', { class: 'ward-ed-panel-head' }, [el('strong', { text: 'Componentes' }), button('Fechar', 'close', () => this.toggleComponents(false))]),
            this.systems.length ? select : el('p', { class: 'ward-ed-muted', text: 'Nenhum design system ainda.' }),
            this.activeSystem ? el('a', { class: 'ward-ed-link', href: this.activeSystem.url, target: '_blank', rel: 'noopener', text: 'Abrir o design system ↗' }) : null,
            search,
            el('p', { class: 'ward-ed-hint', text: 'Vincular: o artboard muda junto com o componente original. Copiar: uma versão para adaptar aqui.' }),
            cards.length ? el('div', { class: 'ward-ed-cmp-list' }, cards) : el('p', { class: 'ward-ed-muted ward-ed-empty', text: this.systemState ? 'Nenhum componente com esse filtro.' : 'Carregando…' }),
        );
    },

    async importComponent(board, mode) {
        const rect = this.canvas.viewport.getBoundingClientRect();
        const center = this.canvas.toWorld(rect.left + rect.width / 2, rect.top + rect.height / 2);
        const op = {
            op: 'component.import', system: this.activeSystem.ref, component: board.name, mode,
            x: Math.round(center.x - board.w / 2), y: Math.round(center.y - board.h / 2), page: this.canvas.currentPage() ?? '',
        };
        const results = await this.send([op], 'Importando…');
        if (!results) return;
        const id = results[0].id;
        this.push({ redo: [{ ...op, _ref: id }], undo: [{ op: 'board.delete', id }] });
        this.setSelection([`b:${id}`]);
        this.setStatus(mode === 'link' ? `"${board.name}" vinculado` : `Cópia de "${board.name}" criada`);
    },

    async publishComponent(board) {
        const systems = (await (await this.http(`${this.collab.systemsUrl}?prefer=${encodeURIComponent(this.collab.projectSystem ?? '')}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json()).design_systems ?? [];
        const mine = systems.filter((system) => system.owner === this.collab.user?.username);
        if (!mine.length) {
            this.setStatus('Crie um design system para um projeto seu em Design systems.', true);
            return;
        }
        const options = mine.map((system, i) => `${i + 1}. ${system.title} (${system.ref})`).join('\n');
        const pick = mine.length === 1 ? '1' : await this.promptDialog(`Publicar "${board.name}" em qual design system?\n${options}`, '1');
        const system = mine[Number(pick) - 1];
        if (!system) return;
        const description = (await this.promptDialog('Descrição do componente (o que é e quando usar):', board.description ?? '')) ?? undefined;
        const results = await this.send([{ op: 'component.publish', id: board.id, system: system.ref, description }], 'Publicando…');
        if (results) this.setStatus(`"${results[0].name}" publicado em ${results[0].system}`);
    },

    async describeComponent(board) {
        const description = await this.promptDialog('Descrição (o que é e quando usar):', board.description ?? '');
        if (description === null) return;
        const tags = await this.promptDialog('Tags (separadas por vírgula):', board.tags ?? '');
        if (tags === null) return;
        this.commit(
            [{ op: 'board.describe', id: board.id, description, tags }],
            [{ op: 'board.describe', id: board.id, description: board.description ?? '', tags: board.tags ?? '' }],
            'Componente descrito',
        );
    },
});

// ---------- comentários, erros e o que a pessoa está vendo ----------

const relativeTime = (iso) => {
    const seconds = (Date.now() - new Date(iso).getTime()) / 1000;
    if (!Number.isFinite(seconds) || seconds < 60) return 'agora';
    if (seconds < 3600) return `há ${Math.floor(seconds / 60)} min`;
    if (seconds < 86400) return `há ${Math.floor(seconds / 3600)} h`;
    return new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

Object.assign(DesignEditor.prototype, {
    initComments() {
        const root = this.canvas.root;
        this.pinsLayer = el('div', { class: 'ward-ed-pins' });
        this.hoverBox = el('div', { class: 'ward-ed-hover', hidden: true });
        this.canvas.world.append(this.hoverBox, this.pinsLayer);
        this.threadBox = el('div', { class: 'ward-ed-thread', hidden: true, role: 'dialog', 'aria-label': 'Comentário' });
        this.panel = el('aside', { class: 'ward-ed-panel', hidden: true, 'aria-label': 'Comentários' });
        root.append(this.threadBox, this.panel);
        this.showResolved = false;
        this.thread = null;
        this.lastPicked = null;

        // Na ferramenta Comentar, o elemento sob o ponteiro fica realçado.
        this.canvas.viewport.addEventListener('pointermove', (event) => {
            if (this.tool !== 'comment' || this.canvas.isMobile() || event.buttons) return;
            clearTimeout(this.hoverTimer);
            this.hoverTimer = setTimeout(() => this.hoverAt(event.clientX, event.clientY), 40);
        });
        this.canvas.viewport.addEventListener('pointerleave', () => this.clearHover());

        this.renderPins();
        this.reportView();
    },

    boardAt(world) {
        return [...this.canvas.state.boards].reverse().find((b) => world.x >= b.x && world.x <= b.x + b.w && world.y >= b.y && world.y <= b.y + b.h) ?? null;
    },

    async hoverAt(clientX, clientY) {
        const world = this.canvas.toWorld(clientX, clientY);
        const board = this.boardAt(world);
        if (!board) return this.clearHover();
        const element = await this.canvas.pick(board.id, world.x - board.x, world.y - board.y);
        if (this.tool !== 'comment' || !element) return this.clearHover();
        Object.assign(this.hoverBox.style, rectStyle({
            x: board.x + element.rect.x, y: board.y + element.rect.y, w: element.rect.w, h: element.rect.h,
        }));
        this.hoverBox.dataset.tag = element.tag;
        this.hoverBox.hidden = false;
    },

    clearHover() {
        if (this.hoverBox) this.hoverBox.hidden = true;
    },

    commentPointerDown(event) {
        const pin = event.target.closest('[data-pin]');
        if (pin) {
            this.openThread(Number(pin.dataset.pin));
            return true;
        }
        if (this.tool !== 'comment') return false;
        this.openComposer(this.canvas.toWorld(event.clientX, event.clientY));
        return true;
    },

    async openComposer(world) {
        const board = this.boardAt(world);
        const point = { bx: board ? Math.round(world.x - board.x) : null, by: board ? Math.round(world.y - board.y) : null };
        const element = board ? await this.canvas.pick(board.id, point.bx, point.by) : null;
        this.clearHover();
        if (board) {
            this.lastPicked = { board: board.name, element };
            this.reportView();
        }

        this.thread = { draft: true, world: { x: Math.round(world.x), y: Math.round(world.y) } };
        const where = board
            ? `${board.title || board.name}${element ? ` · <${element.tag}>${element.text ? ` “${element.text.slice(0, 40)}”` : ''}` : ''}`
            : 'canvas';
        const textarea = el('textarea', { class: 'ward-ed-input', rows: '3', placeholder: 'Escreva um comentário (a IA também lê)', 'aria-label': 'Comentário', maxlength: '4000' });
        const submit = button('Comentar', null, () => send(), { text: 'Comentar', class: 'ward-ed-btn is-primary' });
        const send = async () => {
            const body = textarea.value.trim();
            if (!body) return textarea.focus();
            submit.disabled = true;
            const json = await this.request('POST', this.collab.commentsUrl, {
                body,
                board: board?.id ?? null,
                anchor: board ? { ...(element ?? {}), ...point } : null,
                x: board ? null : this.thread.world.x,
                y: board ? null : this.thread.world.y,
            });
            submit.disabled = false;
            if (!json) return;
            this.setTool('select');
            this.openThread(json.thread);
        };
        textarea.addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) send();
            if (event.key === 'Escape') this.closeThread();
        });

        delete this.threadBox.dataset.thread;
        this.threadBox.replaceChildren(
            el('div', { class: 'ward-ed-thread-head' }, [
                el('strong', { text: 'Novo comentário' }), el('span', { class: 'ward-ed-muted', text: where }),
                button('Fechar', 'close', () => this.closeThread()),
            ]),
            textarea,
            el('div', { class: 'ward-ed-thread-actions' }, [button('Cancelar', null, () => this.closeThread(), { text: 'Cancelar', class: 'ward-ed-btn is-text' }), submit]),
        );
        this.threadBox.hidden = false;
        this.renderPins();
        this.positionThread();
        textarea.focus();
    },

    threads() {
        return this.canvas.state.comments ?? [];
    },

    pinPosition(thread) {
        if (thread.board) {
            const board = this.canvas.state.boards.find((b) => b.id === thread.board);
            if (!board) return null;
            return {
                x: board.x + Math.min(Math.max(thread.anchor?.bx ?? board.w - 28, 0), board.w),
                y: board.y + Math.min(Math.max(thread.anchor?.by ?? 28, 0), board.h),
            };
        }
        return thread.x === null || thread.x === undefined ? null : { x: thread.x, y: thread.y };
    },

    renderPins() {
        if (!this.pinsLayer) return;
        const threads = this.threads();
        const pins = [];
        for (const thread of threads) {
            if (thread.resolved && !this.showResolved && this.thread?.id !== thread.id) continue;
            const at = this.pinPosition(thread);
            if (!at) continue;
            const pin = el('button', {
                type: 'button', class: `ward-ed-pin${thread.resolved ? ' is-resolved' : ''}${this.thread?.id === thread.id ? ' is-active' : ''}`,
                'data-pin': thread.id, text: String(thread.number), 'aria-label': `Comentário ${thread.number}`,
                style: { left: `${at.x}px`, top: `${at.y}px` },
            });
            pins.push(pin);
        }
        if (this.thread?.draft) {
            pins.push(el('span', { class: 'ward-ed-pin is-draft', text: '+', style: { left: `${this.thread.world.x}px`, top: `${this.thread.world.y}px` } }));
        }
        this.pinsLayer.replaceChildren(...pins);

        const open = threads.filter((thread) => !thread.resolved).length;
        this.commentsCount.textContent = String(open);
        this.commentsCount.hidden = open === 0;
        this.commentsButton.setAttribute('aria-label', `Comentários (${open} abertos)`);
        if (!this.panel.hidden) this.renderPanel();
        if (this.thread && !this.thread.draft) this.renderThread();
    },

    openThread(id) {
        this.thread = { id };
        this.renderThread(true);
        this.renderPins();
    },

    closeThread() {
        this.thread = null;
        this.threadBox.hidden = true;
        this.renderPins();
    },

    renderThread(force = false) {
        const thread = this.threads().find((item) => item.id === this.thread?.id);
        if (!thread) {
            this.thread = null;
            this.threadBox.hidden = true;
            return;
        }
        if (!force && this.threadBox.dataset.thread === String(thread.id)
            && this.threadBox.contains(document.activeElement) && document.activeElement.tagName === 'TEXTAREA' && document.activeElement.value) {
            return; // não atrapalha quem está digitando uma resposta
        }

        const me = this.collab.user?.id;
        const author = thread.messages[0]?.user_id;
        const canManage = this.canEdit || author === me;
        const where = thread.board_name
            ? `${thread.board_name}${thread.anchor?.tag ? ` · <${thread.anchor.tag}>` : ''}`
            : 'canvas';

        const messages = thread.messages.map((message) => el('div', { class: 'ward-ed-message' }, [
            el('div', { class: 'ward-ed-message-head' }, [
                el('strong', { text: message.author }),
                message.source === 'ai' ? el('span', { class: 'ward-ed-badge', text: 'IA' }) : null,
                el('span', { class: 'ward-ed-muted', text: relativeTime(message.at) }),
            ]),
            el('p', { text: message.body }),
        ]));

        const textarea = el('textarea', { class: 'ward-ed-input', rows: '2', placeholder: 'Responder', 'aria-label': 'Responder', maxlength: '4000' });
        const reply = async () => {
            const body = textarea.value.trim();
            if (!body) return;
            textarea.value = '';
            await this.request('POST', this.collab.commentsUrl, { body, parent: thread.id });
        };
        textarea.addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) reply();
            if (event.key === 'Escape') this.closeThread();
        });

        const actions = [];
        if (canManage) {
            actions.push(button(thread.resolved ? 'Reabrir' : 'Resolver', thread.resolved ? null : 'check',
                () => this.request('PATCH', `${this.collab.commentsUrl}/${thread.id}`, { resolved: !thread.resolved }),
                { text: thread.resolved ? 'Reabrir' : 'Resolver', class: 'ward-ed-btn is-text' }));
            actions.push(button('Apagar comentário', 'trash', async () => {
                if (!(await this.confirmDialog(`Apagar o comentário #${thread.number} e as respostas?`, 'Apagar'))) return;
                const json = await this.request('DELETE', `${this.collab.commentsUrl}/${thread.id}`);
                if (json) this.closeThread();
            }, { class: 'ward-ed-btn is-danger' }));
        }

        this.threadBox.replaceChildren(
            el('div', { class: 'ward-ed-thread-head' }, [
                el('strong', { text: `#${thread.number}` }), el('span', { class: 'ward-ed-muted', text: where }),
                thread.resolved ? el('span', { class: 'ward-ed-badge is-ok', text: 'Resolvido' }) : null,
                button('Fechar', 'close', () => this.closeThread()),
            ]),
            el('div', { class: 'ward-ed-messages' }, messages),
            textarea,
            el('div', { class: 'ward-ed-thread-actions' }, [
                ...actions, el('span', { class: 'ward-ed-spacer' }),
                button('Responder', null, () => reply(), { text: 'Responder', class: 'ward-ed-btn is-primary' }),
            ]),
        );
        this.threadBox.dataset.thread = String(thread.id);
        this.threadBox.hidden = false;
        this.positionThread();
    },

    positionThread() {
        if (!this.thread || this.threadBox.hidden) return;
        const thread = this.thread.draft ? null : this.threads().find((item) => item.id === this.thread.id);
        const at = this.thread.draft ? this.thread.world : thread && this.pinPosition(thread);
        if (!at) return;
        const { x, y, scale } = this.canvas.view;
        const rootRect = this.canvas.root.getBoundingClientRect();
        const viewRect = this.canvas.viewport.getBoundingClientRect();
        const px = viewRect.left - rootRect.left + x + at.x * scale;
        const py = viewRect.top - rootRect.top + y + at.y * scale;
        const width = this.threadBox.offsetWidth || 320;
        const height = this.threadBox.offsetHeight || 200;
        let left = px + 24;
        if (left + width > rootRect.width - 8) left = px - width - 24;
        const top = Math.min(Math.max(py - 24, 8), rootRect.height - height - 8);
        Object.assign(this.threadBox.style, { left: `${Math.max(8, left)}px`, top: `${top}px` });
    },

    togglePanel(force) {
        const open = force ?? this.panel.hidden;
        this.panel.hidden = !open;
        if (open && this.props) {
            this.props.hidden = true;
            this.tweaksOpen = false;
        }
        this.commentsButton.setAttribute('aria-pressed', String(open));
        this.commentsButton.setAttribute('aria-expanded', String(open));
        if (open) this.renderPanel();
    },

    renderPanel() {
        const threads = this.threads();
        const resolved = threads.filter((thread) => thread.resolved).length;
        const list = threads.filter((thread) => this.showResolved || !thread.resolved);
        const toggle = el('label', { class: 'ward-ed-check' }, [
            el('input', { type: 'checkbox', checked: this.showResolved }),
            ` Mostrar resolvidos (${resolved})`,
        ]);
        toggle.querySelector('input').addEventListener('change', (event) => {
            this.showResolved = event.target.checked;
            this.renderPins();
        });

        const items = list.map((thread) => {
            const first = thread.messages[0];
            const item = el('button', { type: 'button', class: `ward-ed-panel-item${thread.resolved ? ' is-resolved' : ''}` }, [
                el('span', { class: 'ward-ed-panel-meta' }, [
                    el('strong', { text: `#${thread.number}` }),
                    ` ${thread.board_name || 'canvas'} · ${first?.author ?? ''} · ${relativeTime(first?.at)}`,
                ]),
                el('span', { class: 'ward-ed-panel-body', text: first?.body ?? '' }),
                thread.messages.length > 1 ? el('span', { class: 'ward-ed-muted', text: `${thread.messages.length - 1} resposta(s)` }) : null,
            ]);
            item.addEventListener('click', () => this.goToThread(thread));
            return item;
        });

        this.panel.replaceChildren(
            el('div', { class: 'ward-ed-panel-head' }, [el('strong', { text: 'Comentários' }), button('Fechar', 'close', () => this.togglePanel(false))]),
            toggle,
            items.length ? el('div', { class: 'ward-ed-panel-list' }, items)
                : el('p', { class: 'ward-ed-muted ward-ed-empty', text: 'Nenhum comentário aberto. Use a ferramenta Comentar (C) num artboard.' }),
        );
    },

    goToThread(thread) {
        const at = this.pinPosition(thread);
        if (at) {
            const rect = this.canvas.viewport.getBoundingClientRect();
            const scale = Math.max(this.canvas.view.scale, 0.5);
            this.canvas.userMoved = true;
            this.canvas.view = { scale, x: rect.width / 2 - at.x * scale, y: rect.height / 2 - at.y * scale };
            this.canvas.applyView();
        }
        this.openThread(thread.id);
    },

    async request(method, url, body) {
        try {
            const response = await this.http(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': this.collab.csrf, 'X-Requested-With': 'XMLHttpRequest',
                },
                body: body ? JSON.stringify(body) : undefined,
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.setStatus(json.message || (response.status === 419 ? 'Sua sessão expirou. Recarregue a página.' : 'Não foi possível salvar.'), true);
                return null;
            }
            if (json.state) this.canvas.setState(json.state, true);
            return json;
        } catch {
            this.setStatus('Sem conexão.', true);
            return null;
        }
    },

    /** Conta à IA o que a pessoa está vendo (get_design lê isso). */
    reportView() {
        if (!this.collab?.viewUrl) return;
        clearTimeout(this.viewTimer);
        this.viewTimer = setTimeout(() => {
            const selected = this.canvas.state.boards.find((b) => b.id === this.canvas.selected) ?? null;
            const payload = {
                board: selected?.name ?? null,
                element: selected && this.lastPicked?.board === selected.name ? this.lastPicked.element : null,
                visible: this.canvas.visibleBoards().map((b) => b.name),
                errors: this.canvas.errors(),
            };
            const json = JSON.stringify(payload);
            if (json === this.lastView) return;
            this.lastView = json;
            this.http(this.collab.viewUrl, {
                method: 'POST', credentials: 'same-origin', keepalive: true,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.collab.csrf },
                body: json,
            }).catch(() => {});
        }, 800);
    },

    afterErrors() {
        this.reportView();
    },

    showBoardError(boardId) {
        const entry = this.canvas.boards.get(boardId);
        if (!entry) return;
        const data = entry.data;
        this.thread = { draft: true, error: true, world: { x: data.x + data.w, y: data.y } };
        const ask = button('Pedir à IA para corrigir', null, async () => {
            ask.disabled = true;
            const json = await this.request('POST', this.collab.commentsUrl, {
                body: `Erro de script neste artboard: ${entry.errors.join(' | ').slice(0, 1500)}. Corrija, por favor.`,
                board: data.id,
                anchor: { bx: data.w - 28, by: 28 },
            });
            if (json) {
                this.openThread(json.thread);
                this.setStatus('Pedido enviado: a IA vê no get_design.');
            }
        }, { text: 'Pedir à IA para corrigir', class: 'ward-ed-btn is-primary' });

        this.threadBox.replaceChildren(
            el('div', { class: 'ward-ed-thread-head' }, [
                el('strong', { text: 'Erro no script' }), el('span', { class: 'ward-ed-muted', text: data.title || data.name }),
                button('Fechar', 'close', () => this.closeThread()),
            ]),
            el('pre', { class: 'ward-ed-error-list', text: entry.errors.join('\n') }),
            el('div', { class: 'ward-ed-thread-actions' }, [el('span', { class: 'ward-ed-spacer' }), ask]),
        );
        this.threadBox.hidden = false;
        this.positionThread();
    },
});

function defaultSize(note) {
    return note.kind === 'title' ? 'l' : 'm';
}

function rectStyle(r) {
    return { left: `${r.x}px`, top: `${r.y}px`, width: `${r.w}px`, height: `${r.h}px` };
}

function intersects(a, b) {
    return Boolean(a) && a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
}

window.WardDesignEditor = DesignEditor;

const start = () => {
    const config = JSON.parse(document.getElementById('ward-design-config')?.textContent || '{}');
    if (!config.collab || !window.wardDesign) return;
    window.wardDesignEditor = new DesignEditor(window.wardDesign, config);
};

start();
