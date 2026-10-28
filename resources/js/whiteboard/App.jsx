// Ward Whiteboard: o Excalidraw com o ao vivo do Ward, presença de pessoas e da IA,
// post-its, modelos, Mermaid, artboards, comentários, votação, cronômetro,
// apresentação por frames e versões.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    CaptureUpdateAction, Excalidraw, FONT_FAMILY, MainMenu, convertToExcalidrawElements, getCommonBounds, restoreElements,
} from '@excalidraw/excalidraw';
import { WhiteboardSync, createHttp } from './sync.js';
import { embeddableElement, embeddableValidator, framesInOrder, placeAt, stickyElements, wardLibrary } from './elements.js';
import {
    AiBanner, ArtboardDialog, CommentsPanel, MermaidDialog, Overlays, PresentationBar, StickyColors,
    TemplatesDialog, TimerBadge, TimerDialog, Toolbar, VersionsDialog, VotingPanel,
} from './panels.jsx';

const AI_COLOR = '#7048e8';

export default function WhiteboardApp({ config, options = {} }) {
    const http = useMemo(() => createHttp(config), [config]);
    const [api, setApi] = useState(null);
    const [canEdit, setCanEdit] = useState(Boolean(config.canEdit));
    const [people, setPeople] = useState(config.initial.people || []);
    const [ai, setAi] = useState(config.initial.ai || null);
    const [session, setSession] = useState(config.initial.session || {});
    const [comments, setComments] = useState(config.initial.comments ?? null);
    const [status, setStatus] = useState(null);
    const [toast, setToast] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [panel, setPanel] = useState(null);
    const [followAi, setFollowAi] = useState(true);
    const [presentation, setPresentation] = useState(null);
    const [selection, setSelection] = useState([]);
    const [stickyMenu, setStickyMenu] = useState(false);
    const followRef = useRef(null);
    const pointerRef = useRef(null);
    const focusAfterSync = useRef(null);
    const lastAi = useRef(config.initial.ai?.seq ?? null);

    const notify = useCallback((message) => {
        setToast(message);
        clearTimeout(notify.timer);
        notify.timer = setTimeout(() => setToast(null), 5000);
    }, []);

    // Elementos iniciais: o servidor já tem estas versões (o que o Excalidraw mudar ao abrir volta para ele).
    const initialData = useMemo(() => ({
        elements: restoreElements(config.initial.elements, null, { repairBindings: true }),
        appState: {
            viewBackgroundColor: '#ffffff',
            currentItemFontFamily: FONT_FAMILY.Nunito,
            currentItemRoughness: 0,
            currentItemStrokeWidth: 2,
            name: config.title,
        },
        libraryItems: wardLibrary(),
    }), []);

    const sync = useMemo(() => {
        const instance = new WhiteboardSync(config, http, {
            onLive: ({ session: next, comments: threads }) => {
                setSession(next || {});
                if (threads !== null) setComments(threads);
            },
            onPresence: (list, presence) => {
                setPeople(list);
                setAi(presence);
            },
            onStatus: setStatus,
            onError: notify,
            onReadOnly: () => {
                setCanEdit(false);
                notify('Você não pode mais editar este quadro (o convite foi removido). Ele ficou só para leitura.');
            },
            onApplied: () => {
                const ids = focusAfterSync.current;
                if (!ids || !instance.api) return;
                const targets = instance.api.getSceneElements().filter((element) => ids.includes(element.id));
                if (targets.length) {
                    focusAfterSync.current = null;
                    instance.api.scrollToContent(targets, { fitToViewport: true, viewportZoomFactor: 0.8, animate: true });
                }
            },
        });
        instance.fallbackPoll = options.fallbackPoll ?? null;
        instance.markSynced(config.initial.elements);
        return instance;
    }, []);

    useEffect(() => {
        if (!api) return undefined;
        sync.attach(api);
        // Abre enquadrando o quadro todo (sem passar de 100%).
        const fit = setTimeout(() => {
            if (api.getSceneElements().length) api.scrollToContent(undefined, { fitToViewport: true, viewportZoomFactor: 0.9, maxZoom: 1 });
        }, 60);
        // Para inspeção e testes no navegador (como window.wardDesign no canvas de design).
        window.wardWhiteboard = { api, sync };
        return () => {
            clearTimeout(fit);
            sync.destroy();
        };
    }, [api]);

    // ---------- câmera ----------

    const viewCenter = useCallback(() => {
        const state = api.getAppState();
        const zoom = state.zoom.value;
        return { x: -state.scrollX + state.width / zoom / 2, y: -state.scrollY + state.height / zoom / 2 };
    }, [api]);

    /** Lugar livre: à direita de tudo que já está no quadro (o que entra não cobre o conteúdo). */
    const freeSpot = useCallback(() => {
        const elements = api.getSceneElements();
        if (!elements.length) return viewCenter();
        const [minX, minY, maxX] = getCommonBounds(elements);
        return { x: maxX + 180, y: minY };
    }, [api, viewCenter]);

    const showRect = useCallback((rect) => {
        const state = api.getAppState();
        const zoom = Math.max(0.1, Math.min(4, Math.min(state.width / rect.w, state.height / rect.h)));
        api.updateScene({
            appState: {
                scrollX: state.width / (2 * zoom) - (rect.x + rect.w / 2),
                scrollY: state.height / (2 * zoom) - (rect.y + rect.h / 2),
                zoom: { value: zoom },
            },
            captureUpdate: CaptureUpdateAction.NEVER,
        });
    }, [api]);

    const focusIds = useCallback((ids, zoomFactor = 0.8) => {
        const targets = api.getSceneElements().filter((element) => ids.includes(element.id));
        if (targets.length) api.scrollToContent(targets, { fitToViewport: true, viewportZoomFactor: zoomFactor, maxZoom: 1, animate: true });
        return targets.length > 0;
    }, [api]);

    // ---------- presença: cursores das pessoas e da IA ----------

    useEffect(() => {
        if (!api) return;
        const collaborators = new Map();
        for (const person of people) {
            collaborators.set(person.id, {
                id: person.id,
                username: person.name,
                color: { background: person.color, stroke: person.color },
                pointer: person.pointer ? { x: person.pointer.x, y: person.pointer.y, tool: 'pointer' } : undefined,
                button: person.button || 'up',
                selectedElementIds: Object.fromEntries((person.selected || []).map((id) => [id, true])),
            });
        }
        if (ai && Date.now() - ai.at < 5 * 60 * 1000) {
            const targets = api.getSceneElements().filter((element) => (ai.elements || []).includes(element.id));
            const point = ai.point || (targets.length ? { x: targets[0].x + targets[0].width / 2, y: targets[0].y + targets[0].height / 2 } : null);
            collaborators.set('ai', {
                id: 'ai',
                username: ai.actor,
                color: { background: AI_COLOR, stroke: AI_COLOR },
                pointer: point ? { x: point.x, y: point.y, tool: 'pointer' } : undefined,
                selectedElementIds: Object.fromEntries((ai.elements || []).slice(0, 50).map((id) => [id, true])),
            });
        }
        api.updateScene({ collaborators });

        // Seguindo alguém (clique no avatar): a câmera vai para o que a pessoa vê.
        const following = api.getAppState().userToFollow?.socketId ?? followRef.current;
        const followed = following && people.find((person) => person.id === following);
        if (followed?.view) showRect(followed.view);
    }, [api, people, ai]);

    // A IA mostrou algo (show_in_canvas) ou desenhou: quem segue a IA vai junto.
    useEffect(() => {
        if (!api || !ai || ai.seq === lastAi.current) return;
        lastAi.current = ai.seq;
        if (!followAi || presentation) return;
        if (ai.action === 'fit' && !(ai.elements || []).length) {
            api.scrollToContent(undefined, { fitToViewport: true, viewportZoomFactor: 0.9, animate: true });
            return;
        }
        const ids = [...(ai.elements || []), ...(ai.frame ? [ai.frame] : [])];
        if (ids.length && focusIds(ids, ai.action === 'point' ? 0.5 : 0.8)) return;
        if (ids.length) focusAfterSync.current = ids;
        else if (ai.point) showRect({ x: ai.point.x - 400, y: ai.point.y - 300, w: 800, h: 600 });
    }, [api, ai, followAi, presentation]);

    // ---------- ações ----------

    const addElements = useCallback((elements, select = true) => {
        api.updateScene({
            elements: [...api.getSceneElementsIncludingDeleted(), ...elements],
            appState: select ? { selectedElementIds: Object.fromEntries(elements.filter((element) => !element.containerId).map((element) => [element.id, true])) } : undefined,
            captureUpdate: CaptureUpdateAction.IMMEDIATELY,
        });
    }, [api]);

    const addSticky = useCallback((color) => {
        const at = pointerRef.current ?? viewCenter();
        const elements = stickyElements(at.x - 100, at.y - 100, color);
        addElements(elements);
        setStickyMenu(false);
        // Entra direto na edição do texto: Enter num contêiner selecionado. O Excalidraw só
        // ouve o teclado dentro do próprio container (handleKeyboardGlobally desligado).
        setTimeout(() => {
            const container = document.querySelector('.ward-wb .excalidraw');
            container?.focus();
            container?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true, cancelable: true }));
        }, 30);
    }, [api, addElements, viewCenter]);

    const insertTemplate = useCallback(async (name) => {
        setDialog(null);
        try {
            const result = await http(config.routes.template, { method: 'POST', body: { name } });
            // O ao vivo pode ter trazido o modelo antes desta resposta: tenta já, senão depois da próxima busca.
            if (!focusIds(result.ids)) {
                focusAfterSync.current = result.ids;
                sync.pollNow();
            }
        } catch (error) {
            notify(error.message);
        }
    }, [api, focusIds]);

    const insertMermaid = useCallback(async (text) => {
        const { parseMermaidToExcalidraw } = await import('@excalidraw/mermaid-to-excalidraw');
        const { elements: skeleton, files } = await parseMermaidToExcalidraw(text, { themeVariables: { fontSize: '16px' } });
        const spot = freeSpot();
        const elements = placeAt(convertToExcalidrawElements(skeleton, { regenerateIds: true }), spot.x, spot.y)
            .map((element) => ({
                ...element,
                roughness: 0,
                ...(element.type === 'text' ? { fontFamily: FONT_FAMILY.Nunito } : {}),
            }));
        if (files) api.addFiles(Object.values(files));
        addElements(elements);
        setDialog(null);
        api.scrollToContent(elements, { fitToViewport: true, viewportZoomFactor: 0.8, maxZoom: 1, animate: true });
    }, [api, addElements, freeSpot]);

    const insertArtboard = useCallback((board) => {
        const width = Math.min(board.w, 900);
        const height = Math.min(board.h * (width / board.w), 1600);
        const spot = freeSpot();
        const element = embeddableElement(spot.x, spot.y, width, height, board.embed);
        addElements([element]);
        setDialog(null);
        api.scrollToContent(element, { fitToViewport: true, viewportZoomFactor: 0.8, maxZoom: 1, animate: true });
    }, [api, addElements, freeSpot]);

    const commentAnchor = useCallback(() => {
        const selected = Object.keys(api.getAppState().selectedElementIds || {});
        if (selected.length === 1) return { element: selected[0] };
        const center = viewCenter();
        return { x: center.x, y: center.y };
    }, [api, viewCenter]);

    const commentsCall = useCallback(async (url, method, body) => {
        try {
            const data = await http(url, { method, body });
            setComments(data.comments);
        } catch (error) {
            notify(error.message);
        }
    }, []);

    const commentUrl = (id) => config.routes.comment.replace(/\/0$/, `/${id}`);

    const vote = useCallback(async (element, delta) => {
        try {
            const data = await http(config.routes.votes, { method: 'POST', body: { element, delta } });
            setSession(data.session);
        } catch (error) {
            notify(error.message);
        }
    }, []);

    const voting = useCallback(async (action, max) => {
        try {
            const data = await http(config.routes.voting, { method: 'POST', body: { action, max } });
            setSession(data.session);
        } catch (error) {
            notify(error.message);
        }
    }, []);

    const timer = useCallback(async (action, seconds) => {
        try {
            const data = await http(config.routes.timer, { method: 'POST', body: { action, seconds } });
            setSession(data.session);
            setDialog(null);
        } catch (error) {
            notify(error.message);
        }
    }, []);

    const present = useCallback((index, frames = presentation?.frames) => {
        if (!frames?.length) return;
        const next = Math.max(0, Math.min(frames.length - 1, index));
        const frame = api.getSceneElements().find((element) => element.id === frames[next].id);
        if (frame) api.scrollToContent(frame, { fitToViewport: true, viewportZoomFactor: 0.95, animate: true });
        setPresentation({ frames, index: next });
    }, [api, presentation]);

    const startPresentation = useCallback(() => {
        const frames = framesInOrder(api.getSceneElements()).map((frame) => ({ id: frame.id, name: frame.name }));
        if (!frames.length) {
            notify('Crie frames (ferramenta Frame, tecla F) para apresentar um por vez.');
            return;
        }
        api.updateScene({ appState: { zenModeEnabled: true }, captureUpdate: CaptureUpdateAction.NEVER });
        present(0, frames);
    }, [api, present]);

    const stopPresentation = useCallback(() => {
        api.updateScene({ appState: { zenModeEnabled: false }, captureUpdate: CaptureUpdateAction.NEVER });
        setPresentation(null);
    }, [api]);

    const onAction = useCallback((action) => {
        switch (action) {
            case 'sticky': setStickyMenu((open) => !open); break;
            case 'comments': setPanel((open) => (open === 'comments' ? null : 'comments')); break;
            case 'voting': setPanel((open) => (open === 'voting' ? null : 'voting')); break;
            case 'timer': setDialog('timer'); break;
            case 'present': startPresentation(); break;
            default: setDialog(action);
        }
    }, [startPresentation]);

    // Teclas: N cria post-it; setas e Esc durante a apresentação.
    useEffect(() => {
        if (!api) return undefined;
        const onKey = (event) => {
            const target = event.target;
            const typing = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
            if (typing || event.metaKey || event.ctrlKey || event.altKey) return;
            if (presentation) {
                if (['ArrowRight', 'ArrowDown', 'PageDown', ' '].includes(event.key)) present(presentation.index + 1);
                else if (['ArrowLeft', 'ArrowUp', 'PageUp'].includes(event.key)) present(presentation.index - 1);
                else if (event.key === 'Escape') stopPresentation();
                else return;
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            if ((event.key === 'n' || event.key === 'N') && canEdit && !api.getAppState().editingTextElement) {
                event.preventDefault();
                event.stopPropagation();
                addSticky();
            }
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, [api, presentation, canEdit, addSticky, present, stopPresentation]);

    // ---------- props estáveis do Excalidraw ----------

    const onChange = useCallback((elements, appState) => {
        sync.changed(elements);
        const ids = Object.keys(appState.selectedElementIds || {});
        setSelection((current) => (current.length === ids.length && current.every((id, i) => id === ids[i]) ? current : ids));
    }, []);

    const onPointerUpdate = useCallback((payload) => {
        pointerRef.current = { x: payload.pointer.x, y: payload.pointer.y };
        sync.pointer(payload);
    }, []);

    const onUserFollow = useCallback(({ userToFollow, action }) => {
        followRef.current = action === 'FOLLOW' ? userToFollow.socketId : null;
        if (followRef.current === 'ai') {
            followRef.current = null;
            setFollowAi(action === 'FOLLOW');
        }
    }, []);

    const validateEmbeddable = useMemo(() => embeddableValidator(config), []);

    // Artboard do Ward Design: iframe nosso, com o sandbox dos artboards (o padrão do
    // Excalidraw tira allow-same-origin e o artboard não abre).
    const renderEmbeddable = useCallback((element) => {
        if (!element.link || !element.link.startsWith(`${config.appOrigin}/w/artboard/`)) return null;
        return (
            <iframe
                className="excalidraw__embeddable"
                src={element.link}
                title="Artboard do Ward Design"
                sandbox={config.artboardSandbox}
                allow="clipboard-write; fullscreen"
                referrerPolicy="same-origin"
                style={{ width: '100%', height: '100%', border: 0, background: '#fff' }}
            />
        );
    }, []);
    const openComments = (comments || []).filter((thread) => !thread.resolved).length;


    return (
        <div className="ward-wb">
            <Excalidraw
                excalidrawAPI={setApi}
                initialData={initialData}
                langCode="pt-BR"
                viewModeEnabled={!canEdit}
                isCollaborating
                onChange={onChange}
                onPointerUpdate={onPointerUpdate}
                onUserFollow={onUserFollow}
                validateEmbeddable={validateEmbeddable}
                renderEmbeddable={renderEmbeddable}
                UIOptions={{ canvasActions: { loadScene: false, saveToActiveFile: false, export: { saveFileToDisk: true } } }}
            >
                <MainMenu>
                    <MainMenu.DefaultItems.SaveAsImage />
                    <MainMenu.DefaultItems.Export />
                    {config.routes.download ? (
                        <MainMenu.Item onSelect={() => { window.location.href = config.routes.download; }}>Baixar quadro (.excalidraw)</MainMenu.Item>
                    ) : null}
                    {config.routes.versions ? <MainMenu.Item onSelect={() => setDialog('versions')}>Versões</MainMenu.Item> : null}
                    <MainMenu.Separator />
                    <MainMenu.DefaultItems.SearchMenu />
                    <MainMenu.DefaultItems.Help />
                    <MainMenu.DefaultItems.ToggleTheme />
                    <MainMenu.DefaultItems.ChangeCanvasBackground />
                    {canEdit ? <MainMenu.DefaultItems.ClearCanvas /> : null}
                </MainMenu>
            </Excalidraw>

            {options.app ? null : (
                <div className="ward-wb-dock">
                    <Toolbar canEdit={canEdit} panel={panel} onAction={onAction} openComments={openComments} />
                </div>
            )}

            <Overlays
                api={api}
                comments={comments}
                voting={session.voting || null}
                canVote={Boolean(config.user && config.routes.votes)}
                onOpenComment={() => setPanel('comments')}
                onVote={vote}
            />

            {stickyMenu && canEdit ? (
                <div className="ward-wb-popover" role="dialog" aria-label="Cor do post-it">
                    <span>Post-it</span>
                    <StickyColors onPick={addSticky} />
                </div>
            ) : null}

            <div className="ward-wb-status-row">
                <TimerBadge timer={session.timer} canEdit={canEdit} onAction={(action) => timer(action)} />
                {!canEdit ? <span className="ward-wb-pill">Só leitura</span> : null}
                {status ? <span className="ward-wb-pill is-warn">{status}</span> : null}
            </div>

            <AiBanner ai={ai} following={followAi} onToggle={() => setFollowAi((value) => !value)} />

            {presentation ? <PresentationBar frames={presentation.frames} index={presentation.index} onGo={(index) => present(index)} onClose={stopPresentation} /> : null}

            {panel === 'comments' && api ? (
                <CommentsPanel
                    comments={comments}
                    user={config.user}
                    canEdit={canEdit}
                    selection={selection}
                    onCreate={(body) => commentsCall(config.routes.comments, 'POST', { body, ...commentAnchor() })}
                    onReply={(parent, body) => commentsCall(config.routes.comments, 'POST', { body, parent })}
                    onResolve={(id, resolved) => commentsCall(commentUrl(id), 'PATCH', { resolved })}
                    onDelete={(id) => commentsCall(commentUrl(id), 'DELETE')}
                    onFocus={(thread) => (thread.element ? focusIds([thread.element]) : thread.x !== null && showRect({ x: thread.x - 300, y: thread.y - 200, w: 600, h: 400 }))}
                    onClose={() => setPanel(null)}
                />
            ) : null}

            {panel === 'voting' && api ? (
                <VotingPanel api={api} voting={session.voting || null} canEdit={canEdit} onVoting={voting} onFocus={(id) => focusIds([id])} onClose={() => setPanel(null)} />
            ) : null}

            {dialog === 'templates' ? <TemplatesDialog templates={config.templates} onPick={insertTemplate} onClose={() => setDialog(null)} /> : null}
            {dialog === 'mermaid' ? <MermaidDialog onSubmit={insertMermaid} onClose={() => setDialog(null)} /> : null}
            {dialog === 'artboard' ? <ArtboardDialog http={http} url={config.routes.artboards} onPick={insertArtboard} onClose={() => setDialog(null)} /> : null}
            {dialog === 'versions' ? <VersionsDialog http={http} routes={config.routes} canEdit={canEdit} onRestored={() => sync.pollNow()} onClose={() => setDialog(null)} notify={notify} /> : null}
            {dialog === 'timer' ? <TimerDialog onStart={(seconds) => timer('start', seconds)} onClose={() => setDialog(null)} /> : null}

            {toast ? <div className="ward-wb-toast" role="status">{toast}</div> : null}
        </div>
    );
}
