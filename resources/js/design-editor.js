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

function button(label, iconName, onClick, extra = {}) {
    const node = el('button', { type: 'button', class: 'ward-ed-btn', 'aria-label': label, title: label, ...extra },
        iconName ? [icon(iconName)] : [extra.text ?? label]);
    node.addEventListener('click', (event) => {
        event.stopPropagation();
        onClick(event);
    });
    return node;
}

const isMac = /Mac|iPhone|iPad/.test(navigator.platform);

class DesignEditor {
    constructor(canvas, config) {
        this.canvas = canvas;
        this.config = config;
        this.tool = 'select';
        this.selection = new Set(); // 'b:<id>' ou 'n:<key>'
        this.undoStack = [];
        this.redoStack = [];
        this.queue = Promise.resolve();
        this.pending = 0;
        this.gesture = null;
        this.space = false;
        this.editingNote = null;

        canvas.editor = this;
        this.buildUi();
        this.bindKeys();
        this.bindDoubleClick();
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
            ...this.canvas.state.boards.map((board) => `b:${board.id}`),
            ...this.canvas.state.notes.map((note) => `n:${note.key}`),
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
        this.canvas.select(this.selection.size === 1 && boards.length === 1 ? Number(boards[0].slice(2)) : null);
        this.syncing = false;
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
        for (const [tool, label, key] of [['select', 'Selecionar', 'V'], ['hand', 'Mão', 'H'], ['note', 'Nota adesiva', 'N'], ['title', 'Título', 'T']]) {
            this.tools[tool] = button(`${label} (${key})`, tool, () => this.setTool(tool), { 'aria-pressed': 'false' });
            toolbar.append(this.tools[tool]);
        }
        toolbar.append(el('span', { class: 'ward-ed-sep', 'aria-hidden': 'true' }));
        this.undoButton = button(`Desfazer (${isMac ? '⌘' : 'Ctrl+'}Z)`, 'undo', () => this.undo());
        this.redoButton = button(`Refazer (${isMac ? '⇧⌘' : 'Ctrl+Shift+'}Z)`, 'redo', () => this.redo());
        toolbar.append(this.undoButton, this.redoButton);
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
        if (this.gesture) return;
        const before = this.selection.size;
        for (const key of [...this.selection]) if (!this.exists(key)) this.selection.delete(key);
        if (before !== this.selection.size) this.setSelection([...this.selection]);
        else this.drawSelection();
    }

    afterView() {
        this.positionBar();
    }

    afterSelect(id) {
        if (this.syncing) return;
        // O canvas selecionou sozinho (ex.: foco com anterior/próximo).
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
        if (!keys.length || this.gesture || this.editingNote || this.canvas.isMobile()) {
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
            key, kind, x: Math.round(world.x), y: Math.round(world.y), w: kind === 'title' ? null : 260,
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

    send(ops, label) {
        this.pending++;
        this.canvas.hold = true;
        if (label) this.setStatus(label);

        const run = async () => {
            try {
                const response = await fetch(this.config.url, {
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

function defaultSize(note) {
    return note.kind === 'title' ? 'l' : 'm';
}

function rectStyle(r) {
    return { left: `${r.x}px`, top: `${r.y}px`, width: `${r.w}px`, height: `${r.h}px` };
}

function intersects(a, b) {
    return Boolean(a) && a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
}

const start = () => {
    const config = JSON.parse(document.getElementById('ward-design-config')?.textContent || '{}');
    if (!config.edit || !window.wardDesign) return;
    window.wardDesignEditor = new DesignEditor(window.wardDesign, config.edit);
};

start();
