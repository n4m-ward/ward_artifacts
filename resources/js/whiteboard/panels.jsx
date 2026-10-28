// Ward Whiteboard: interface do Ward por cima do Excalidraw (barra de ações, diálogos,
// comentários, votação, cronômetro, apresentação e marcadores no quadro).
import { useEffect, useRef, useState } from 'react';
import { isSticky, STICKY_COLORS } from './elements.js';

const ICONS = {
    sticky: 'M5 4h14v10l-6 6H5zM13 20v-6h6',
    templates: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    mermaid: 'M4 6h6v4H4zM14 14h6v4h-6zM7 10v4h10',
    artboard: 'M4 5h16v12H4zM8 21h8M12 17v4',
    comments: 'M4 5h16v11H9l-5 4z',
    vote: 'M12 3l2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.4 6.7 19.4l1.2-6L3.4 9.3l6-.7z',
    timer: 'M12 8v5l3 2M9 2h6M12 22a8 8 0 100-16 8 8 0 000 16z',
    present: 'M3 4h18v12H3zM12 16v4M8 20h8M10 8l4 2-4 2z',
    versions: 'M12 8v4l3 2M3 12a9 9 0 109-9 9.75 9.75 0 00-6.74 2.74L3 8M3 3v5h5',
    close: 'M6 6l12 12M18 6L6 18',
    prev: 'M15 18l-6-6 6-6',
    next: 'M9 18l6-6-6-6',
    plus: 'M12 5v14M5 12h14',
    minus: 'M5 12h14',
};

export function Icon({ name, size = 18 }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d={ICONS[name]} />
        </svg>
    );
}

/** Barra Ward no canto superior direito (renderTopRightUI do Excalidraw). */
export function Toolbar({ canEdit, panel, onAction, openComments }) {
    const button = (action, icon, label, { active = false, badge = null, edit = false } = {}) => {
        if (edit && !canEdit) return null;
        return (
            <button
                type="button"
                className={`ward-wb-tool${active ? ' is-active' : ''}`}
                onClick={() => onAction(action)}
                title={label}
                aria-label={label}
                aria-pressed={active}
            >
                <Icon name={icon} />
                {badge ? <span className="ward-wb-tool-badge">{badge}</span> : null}
            </button>
        );
    };

    return (
        <div className="ward-wb-toolbar" role="toolbar" aria-label="Ferramentas do Ward">
            {button('sticky', 'sticky', 'Post-it (N)', { edit: true })}
            {button('templates', 'templates', 'Modelos', { edit: true })}
            {button('mermaid', 'mermaid', 'Colar Mermaid', { edit: true })}
            {button('artboard', 'artboard', 'Artboard do Ward Design', { edit: true })}
            {canEdit ? <span className="ward-wb-sep" aria-hidden="true" /> : null}
            {button('comments', 'comments', 'Comentários', { active: panel === 'comments', badge: openComments || null })}
            {button('voting', 'vote', 'Votação', { active: panel === 'voting' })}
            {button('timer', 'timer', 'Cronômetro', { edit: true })}
            {button('present', 'present', 'Apresentar por frames')}
            {button('versions', 'versions', 'Versões')}
        </div>
    );
}

export function Dialog({ title, onClose, children, wide = false }) {
    useEffect(() => {
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onClose();
            }
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, [onClose]);

    return (
        <div className="ward-wb-backdrop" onPointerDown={(event) => event.target === event.currentTarget && onClose()}>
            <div className={`ward-wb-dialog${wide ? ' is-wide' : ''}`} role="dialog" aria-modal="true" aria-label={title}>
                <div className="ward-wb-dialog-head">
                    <h2>{title}</h2>
                    <button type="button" className="ward-wb-icon" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
                </div>
                <div className="ward-wb-dialog-body">{children}</div>
            </div>
        </div>
    );
}

export function StickyColors({ onPick }) {
    return (
        <div className="ward-wb-colors">
            {STICKY_COLORS.map(([color, name]) => (
                <button key={color} type="button" style={{ background: color }} title={name} aria-label={`Post-it ${name.toLowerCase()}`} onClick={() => onPick(color)} />
            ))}
        </div>
    );
}

export function TemplatesDialog({ templates, onPick, onClose }) {
    const descriptions = {
        flowchart: 'Início, etapas, decisão e fim ligados por setas.',
        retro: 'Três colunas: o que foi bem, o que melhorar e ações.',
        kanban: 'A fazer, fazendo, revisão e feito.',
        journey: 'Etapas da jornada × ações, pensamentos, sentimentos e oportunidades.',
        matrix: 'Impacto × esforço em quatro quadrantes.',
        mindmap: 'Tema central com ramos dos dois lados.',
        brainstorm: 'Uma pergunta e post-its coloridos para preencher.',
    };

    return (
        <Dialog title="Modelos" onClose={onClose} wide>
            <div className="ward-wb-grid">
                {Object.entries(templates).map(([key, name]) => (
                    <button key={key} type="button" className="ward-wb-card" onClick={() => onPick(key)}>
                        <strong>{name}</strong>
                        <span>{descriptions[key]}</span>
                    </button>
                ))}
            </div>
        </Dialog>
    );
}

export function MermaidDialog({ onSubmit, onClose }) {
    const [text, setText] = useState('flowchart LR\n  A[Pedido] --> B{Pago?}\n  B -->|sim| C[Enviar]\n  B -->|não| D[Cobrar]');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await onSubmit(text);
        } catch (e) {
            setError(e?.message || 'Não foi possível ler esse Mermaid.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog title="Colar Mermaid" onClose={onClose} wide>
            <form onSubmit={submit} className="ward-wb-form">
                <p className="ward-wb-muted">Fluxogramas, sequência e classes viram elementos editáveis do quadro.</p>
                <textarea value={text} onChange={(event) => setText(event.target.value)} rows={10} spellCheck={false} aria-label="Código Mermaid" />
                {error ? <p className="ward-wb-error">{error}</p> : null}
                <div className="ward-wb-actions">
                    <button type="button" className="ward-wb-btn" onClick={onClose}>Cancelar</button>
                    <button type="submit" className="ward-wb-btn is-primary" disabled={busy}>{busy ? 'Convertendo…' : 'Inserir'}</button>
                </div>
            </form>
        </Dialog>
    );
}

export function ArtboardDialog({ http, url, onPick, onClose }) {
    const [query, setQuery] = useState('');
    const [designs, setDesigns] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        const timer = setTimeout(() => {
            const target = new URL(url, window.location.href);
            if (query) target.searchParams.set('q', query);
            http(target.toString()).then((data) => setDesigns(data.designs)).catch((e) => setError(e.message));
        }, 250);
        return () => clearTimeout(timer);
    }, [query]);

    return (
        <Dialog title="Artboard do Ward Design" onClose={onClose} wide>
            <div className="ward-wb-form">
                <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar design" aria-label="Buscar design" autoFocus />
                {error ? <p className="ward-wb-error">{error}</p> : null}
                {designs === null ? <p className="ward-wb-muted">Carregando…</p> : null}
                {designs?.length === 0 ? <p className="ward-wb-muted">Nenhum design com artboards.</p> : null}
                <div className="ward-wb-list">
                    {designs?.map((design) => (
                        <section key={design.id}>
                            <h3>{design.title} <span className="ward-wb-muted">@{design.owner} · {design.project}</span></h3>
                            <div className="ward-wb-chips">
                                {design.boards.map((board) => (
                                    <button key={board.embed} type="button" className="ward-wb-chip" onClick={() => onPick(board)}>
                                        {board.title || board.name} <span className="ward-wb-muted">{board.w}×{board.h}</span>
                                    </button>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        </Dialog>
    );
}

export function VersionsDialog({ http, routes, canEdit, onRestored, onClose, notify }) {
    const [versions, setVersions] = useState(null);
    const [label, setLabel] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        http(routes.versions).then((data) => setVersions(data.versions)).catch((e) => notify(e.message));
    }, []);

    const save = async (event) => {
        event.preventDefault();
        setBusy(true);
        try {
            const data = await http(routes.saveVersion, { method: 'POST', body: { label } });
            setVersions(data.versions);
            setLabel('');
            notify('Versão salva.');
        } catch (e) {
            notify(e.message);
        } finally {
            setBusy(false);
        }
    };

    const restore = async (number) => {
        if (!window.confirm(`Voltar o quadro para a versão ${number}? O que não existia nela será apagado (dá para voltar restaurando outra versão).`)) return;
        try {
            await http(routes.restoreVersion.replace(/\/0\/restore$/, `/${number}/restore`), { method: 'POST' });
            onRestored();
            notify(`Quadro restaurado para a versão ${number}.`);
            onClose();
        } catch (e) {
            notify(e.message);
        }
    };

    return (
        <Dialog title="Versões" onClose={onClose}>
            <div className="ward-wb-form">
                {canEdit ? (
                    <form onSubmit={save} className="ward-wb-row">
                        <input value={label} onChange={(event) => setLabel(event.target.value)} placeholder="Nome da versão (opcional)" aria-label="Nome da versão" maxLength={120} />
                        <button type="submit" className="ward-wb-btn is-primary" disabled={busy}>Salvar versão</button>
                    </form>
                ) : null}
                {versions === null ? <p className="ward-wb-muted">Carregando…</p> : null}
                {versions?.length === 0 ? <p className="ward-wb-muted">Nenhuma versão salva ainda.</p> : null}
                <ul className="ward-wb-versions">
                    {versions?.map((version) => (
                        <li key={version.number}>
                            <div>
                                <strong>v{version.number}{version.label ? ` · ${version.label}` : ''}</strong>
                                <span className="ward-wb-muted">{version.elements} elementos · {version.by || '—'} · {version.at ? new Date(version.at).toLocaleString('pt-BR') : ''}</span>
                            </div>
                            {canEdit ? <button type="button" className="ward-wb-btn" onClick={() => restore(version.number)}>Restaurar</button> : null}
                        </li>
                    ))}
                </ul>
            </div>
        </Dialog>
    );
}

export function TimerDialog({ onStart, onClose }) {
    const [minutes, setMinutes] = useState(5);
    return (
        <Dialog title="Cronômetro" onClose={onClose}>
            <form className="ward-wb-form" onSubmit={(event) => { event.preventDefault(); onStart(Math.round(minutes * 60)); }}>
                <div className="ward-wb-chips">
                    {[1, 3, 5, 10, 15].map((value) => (
                        <button key={value} type="button" className={`ward-wb-chip${minutes === value ? ' is-active' : ''}`} onClick={() => setMinutes(value)}>{value} min</button>
                    ))}
                </div>
                <label className="ward-wb-row">
                    <span>Minutos</span>
                    <input type="number" min="0.5" max="120" step="0.5" value={minutes} onChange={(event) => setMinutes(Number(event.target.value))} />
                </label>
                <div className="ward-wb-actions">
                    <button type="button" className="ward-wb-btn" onClick={onClose}>Cancelar</button>
                    <button type="submit" className="ward-wb-btn is-primary">Começar</button>
                </div>
            </form>
        </Dialog>
    );
}

const format = (ms) => {
    const total = Math.max(0, Math.ceil(ms / 1000));
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
};

/** Cronômetro compartilhado (todos veem o mesmo tempo). */
export function TimerBadge({ timer, canEdit, onAction }) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        if (!timer?.running) return undefined;
        const id = setInterval(() => setNow(Date.now()), 250);
        return () => clearInterval(id);
    }, [timer?.running]);

    if (!timer) return null;
    const remaining = timer.running ? timer.ends_at - now : timer.remaining;
    const done = remaining <= 0;
    // Acabou há mais de 30 s: sai da tela (fica no estado até alguém encerrar).
    if (done && timer.running && now - timer.ends_at > 30_000) return null;

    return (
        <div className={`ward-wb-timer${done ? ' is-done' : ''}`} role="timer" aria-live="polite">
            <Icon name="timer" size={16} />
            <span>{done ? 'Tempo!' : format(remaining)}</span>
            {canEdit ? (
                <>
                    {!done ? <button type="button" onClick={() => onAction(timer.running ? 'pause' : 'resume')}>{timer.running ? 'Pausar' : 'Continuar'}</button> : null}
                    <button type="button" onClick={() => onAction('stop')} aria-label="Encerrar cronômetro"><Icon name="close" size={14} /></button>
                </>
            ) : null}
        </div>
    );
}

/** Texto de um elemento para listas (post-it, forma com texto, texto solto). */
export function elementText(api, id) {
    const elements = api.getSceneElements();
    const element = elements.find((item) => item.id === id);
    if (!element) return null;
    if (element.type === 'text') return element.originalText || element.text;
    const bound = element.boundElements?.find((item) => item.type === 'text');
    const text = bound ? elements.find((item) => item.id === bound.id) : null;
    return text?.originalText || text?.text || (element.type === 'frame' ? element.name : null) || (isSticky(element) ? 'Post-it' : element.type);
}

export function VotingPanel({ api, voting, canEdit, onVoting, onFocus, onClose }) {
    const [max, setMax] = useState(3);
    const ranking = voting ? Object.entries(voting.totals || {}).sort((a, b) => b[1] - a[1]) : [];

    return (
        <aside className="ward-wb-panel" aria-label="Votação">
            <div className="ward-wb-dialog-head">
                <h2>Votação</h2>
                <button type="button" className="ward-wb-icon" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
            </div>
            <div className="ward-wb-panel-body">
                {!voting ? (
                    canEdit ? (
                        <form className="ward-wb-form" onSubmit={(event) => { event.preventDefault(); onVoting('start', max); }}>
                            <p className="ward-wb-muted">Cada pessoa distribui votos nos post-its e formas do quadro.</p>
                            <label className="ward-wb-row"><span>Votos por pessoa</span><input type="number" min="1" max="20" value={max} onChange={(event) => setMax(Number(event.target.value))} /></label>
                            <button type="submit" className="ward-wb-btn is-primary">Abrir votação</button>
                        </form>
                    ) : <p className="ward-wb-muted">Nenhuma votação aberta. Quem edita o quadro pode abrir uma.</p>
                ) : (
                    <>
                        <p>{voting.open ? `Votação aberta por ${voting.started_by}.` : 'Votação encerrada.'} {voting.open ? `Você usou ${voting.used} de ${voting.max} votos.` : ''}</p>
                        <p className="ward-wb-muted">{voting.voters} pessoa(s) votaram.{voting.open ? ' Use os botões + nos elementos.' : ''}</p>
                        {canEdit ? (
                            <div className="ward-wb-actions">
                                {voting.open ? <button type="button" className="ward-wb-btn" onClick={() => onVoting('close')}>Encerrar</button> : null}
                                <button type="button" className="ward-wb-btn" onClick={() => onVoting('clear')}>Limpar votação</button>
                            </div>
                        ) : null}
                        <ol className="ward-wb-ranking">
                            {ranking.map(([id, total]) => (
                                <li key={id}>
                                    <button type="button" onClick={() => onFocus(id)}>{elementText(api, id) || 'Elemento apagado'}</button>
                                    <strong>{total}</strong>
                                </li>
                            ))}
                        </ol>
                    </>
                )}
            </div>
        </aside>
    );
}

export function CommentsPanel({ comments, user, canEdit, selection, onCreate, onReply, onResolve, onDelete, onFocus, onClose }) {
    const [body, setBody] = useState('');
    const [showResolved, setShowResolved] = useState(false);
    const threads = (comments || []).filter((thread) => showResolved || !thread.resolved);

    return (
        <aside className="ward-wb-panel" aria-label="Comentários">
            <div className="ward-wb-dialog-head">
                <h2>Comentários</h2>
                <button type="button" className="ward-wb-icon" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
            </div>
            <div className="ward-wb-panel-body">
                {user ? (
                    <form className="ward-wb-form" onSubmit={(event) => { event.preventDefault(); if (body.trim()) onCreate(body).then(() => setBody('')); }}>
                        <textarea value={body} onChange={(event) => setBody(event.target.value)} rows={3} placeholder={selection.length === 1 ? 'Comentar o elemento selecionado' : 'Comentar neste ponto do quadro'} aria-label="Novo comentário" />
                        <button type="submit" className="ward-wb-btn is-primary">Comentar</button>
                    </form>
                ) : null}
                <label className="ward-wb-check"><input type="checkbox" checked={showResolved} onChange={(event) => setShowResolved(event.target.checked)} /> Mostrar resolvidos</label>
                {threads.length === 0 ? <p className="ward-wb-muted">Nenhum comentário{showResolved ? '' : ' aberto'}.</p> : null}
                {threads.map((thread) => (
                    <Thread key={thread.id} thread={thread} user={user} canEdit={canEdit} onReply={onReply} onResolve={onResolve} onDelete={onDelete} onFocus={onFocus} />
                ))}
            </div>
        </aside>
    );
}

function Thread({ thread, user, canEdit, onReply, onResolve, onDelete, onFocus }) {
    const [reply, setReply] = useState('');
    const first = thread.messages[0];

    return (
        <article className={`ward-wb-thread${thread.resolved ? ' is-resolved' : ''}`}>
            <header>
                <button type="button" className="ward-wb-pin-label" onClick={() => onFocus(thread)}>#{thread.number}</button>
                <span>{first?.author}</span>
                <span className="ward-wb-muted">{first?.at ? new Date(first.at).toLocaleString('pt-BR') : ''}</span>
            </header>
            {thread.messages.map((message) => (
                <div key={message.id} className="ward-wb-message">
                    {message !== first ? <strong>{message.author}{message.source === 'ai' ? ' (IA)' : ''}: </strong> : null}
                    <span>{message.body}</span>
                    {user && (message.user_id === Number(user.id.slice(1)) || canEdit) ? (
                        <button type="button" className="ward-wb-link" onClick={() => onDelete(message.id)}>apagar</button>
                    ) : null}
                </div>
            ))}
            {user ? (
                <form className="ward-wb-row" onSubmit={(event) => { event.preventDefault(); if (reply.trim()) onReply(thread.id, reply).then(() => setReply('')); }}>
                    <input value={reply} onChange={(event) => setReply(event.target.value)} placeholder="Responder" aria-label={`Responder ao comentário ${thread.number}`} />
                    <button type="button" className="ward-wb-btn" onClick={() => onResolve(thread.id, !thread.resolved)}>{thread.resolved ? 'Reabrir' : 'Resolver'}</button>
                </form>
            ) : null}
        </article>
    );
}

/**
 * Marcadores por cima do quadro: alfinetes dos comentários e votos nos elementos.
 * Acompanha a câmera num laço de animação, sem re-renderizar o Excalidraw.
 */
export function Overlays({ api, comments, voting, canVote, onOpenComment, onVote }) {
    const [, setFrame] = useState(0);
    const last = useRef('');

    useEffect(() => {
        if (!api) return undefined;
        let id;
        const loop = () => {
            const state = api.getAppState();
            const key = `${state.scrollX}|${state.scrollY}|${state.zoom.value}|${state.width}|${state.height}|${api.getSceneElements().length}|${sceneStamp(api)}`;
            if (key !== last.current) {
                last.current = key;
                setFrame((value) => value + 1);
            }
            id = requestAnimationFrame(loop);
        };
        id = requestAnimationFrame(loop);
        return () => cancelAnimationFrame(id);
    }, [api]);

    if (!api) return null;
    const state = api.getAppState();
    const zoom = state.zoom.value;
    const toView = (x, y) => ({ left: (x + state.scrollX) * zoom, top: (y + state.scrollY) * zoom });
    const elements = api.getSceneElements();
    const byId = new Map(elements.map((element) => [element.id, element]));
    const inView = ({ left, top }) => left > -40 && top > -40 && left < state.width + 40 && top < state.height + 40;

    const pins = (comments || []).filter((thread) => !thread.resolved).map((thread) => {
        const element = thread.element ? byId.get(thread.element) : null;
        const point = element ? toView(element.x + element.width, element.y) : (thread.x !== null && thread.x !== undefined ? toView(thread.x, thread.y) : null);
        return point && inView(point) ? (
            <button key={`c${thread.id}`} type="button" className="ward-wb-pin" style={point} onClick={() => onOpenComment(thread)} title={thread.messages[0]?.body} aria-label={`Comentário ${thread.number}`}>
                {thread.number}
            </button>
        ) : null;
    });

    const votes = voting ? elements.filter((element) => !element.containerId && ['rectangle', 'ellipse', 'diamond', 'image'].includes(element.type)).map((element) => {
        const total = voting.totals?.[element.id] || 0;
        const mine = voting.mine?.[element.id] || 0;
        if (!voting.open && !total) return null;
        // Com o quadro muito afastado o selo cobriria o elemento: só aparece em quem está legível.
        if (element.width * zoom < 70 || element.height * zoom < 40) return null;
        const point = toView(element.x + element.width, element.y + element.height);
        if (!inView(point)) return null;
        return (
            <div key={`v${element.id}`} className={`ward-wb-votes${mine ? ' is-mine' : ''}`} style={point}>
                {canVote && voting.open && mine ? <button type="button" onClick={() => onVote(element.id, -1)} aria-label="Tirar voto"><Icon name="minus" size={12} /></button> : null}
                <span title={`${total} voto(s)${mine ? `, ${mine} seu(s)` : ''}`}>{total}</span>
                {canVote && voting.open ? <button type="button" onClick={() => onVote(element.id, 1)} aria-label="Votar"><Icon name="plus" size={12} /></button> : null}
            </div>
        );
    }) : null;

    return <div className="ward-wb-overlays">{pins}{votes}</div>;
}

function sceneStamp(api) {
    let sum = 0;
    for (const element of api.getSceneElements()) sum += element.version;
    return sum;
}

export function AiBanner({ ai, following, onToggle }) {
    const [visible, setVisible] = useState(false);
    useEffect(() => {
        // Só o que a IA fez agora (não a última ação de minutos atrás, ao abrir o quadro).
        if (!ai || Date.now() - ai.at > 60_000) return undefined;
        setVisible(true);
        const id = setTimeout(() => setVisible(false), 8000);
        return () => clearTimeout(id);
    }, [ai?.seq]);

    if (!ai || !visible) return null;
    const verbs = { editing: 'está desenhando', reading: 'está lendo o quadro', focus: 'está mostrando', fit: 'está mostrando o quadro', point: 'está apontando' };

    return (
        <div className="ward-wb-ai" role="status">
            <span className="ward-wb-ai-dot" aria-hidden="true" />
            <span><strong>{ai.actor}</strong> {verbs[ai.action] || 'está no quadro'}{ai.message ? `: ${ai.message}` : ''}</span>
            <button type="button" onClick={onToggle}>{following ? 'Parar de seguir' : 'Seguir'}</button>
        </div>
    );
}

export function PresentationBar({ frames, index, onGo, onClose }) {
    const frame = frames[index];
    return (
        <div className="ward-wb-present" role="toolbar" aria-label="Apresentação">
            <button type="button" onClick={() => onGo(index - 1)} disabled={index === 0} aria-label="Frame anterior"><Icon name="prev" /></button>
            <span>{frame?.name || `Frame ${index + 1}`} <span className="ward-wb-muted">{index + 1}/{frames.length}</span></span>
            <button type="button" onClick={() => onGo(index + 1)} disabled={index === frames.length - 1} aria-label="Próximo frame"><Icon name="next" /></button>
            <button type="button" onClick={onClose} aria-label="Sair da apresentação"><Icon name="close" /></button>
        </div>
    );
}
