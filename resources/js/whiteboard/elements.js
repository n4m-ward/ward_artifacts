// Ward Whiteboard: elementos que o Excalidraw não traz prontos (post-it, artboard
// do Ward Design embutido) e a biblioteca Ward de formas.
import { FONT_FAMILY, convertToExcalidrawElements, restoreElements } from '@excalidraw/excalidraw';

export const STICKY_COLORS = [
    ['#ffec99', 'Amarelo'], ['#ffd8a8', 'Laranja'], ['#fcc2d7', 'Rosa'], ['#ffc9c9', 'Vermelho'],
    ['#b2f2bb', 'Verde'], ['#96f2d7', 'Verde-água'], ['#a5d8ff', 'Azul'], ['#d0bfff', 'Roxo'], ['#e9ecef', 'Cinza'],
];

export const STICKY_SIZE = 200;

export const isSticky = (element) => element?.customData?.wardKind === 'sticky';

/** Post-it: retângulo colorido sem borda, com texto alinhado em cima à esquerda. */
export function stickyElements(x, y, color = STICKY_COLORS[0][0], text = '') {
    const elements = convertToExcalidrawElements([{
        type: 'rectangle',
        x,
        y,
        width: STICKY_SIZE,
        height: STICKY_SIZE,
        backgroundColor: color,
        strokeColor: 'transparent',
        fillStyle: 'solid',
        strokeWidth: 1,
        roughness: 0,
        roundness: { type: 3 },
        ...(text ? { label: { text, fontSize: 20, fontFamily: FONT_FAMILY.Nunito, textAlign: 'left', verticalAlign: 'top' } } : {}),
    }], { regenerateIds: true });

    return elements.map((element) => (element.type === 'rectangle'
        ? { ...element, customData: { ...(element.customData || {}), wardKind: 'sticky' } }
        : element));
}

/** Artboard do Ward Design dentro do quadro (iframe com o link estável do app). */
export function embeddableElement(x, y, width, height, link) {
    return restoreElements([{
        id: randomId(),
        type: 'embeddable',
        x,
        y,
        width,
        height,
        angle: 0,
        strokeColor: '#1e1e1e',
        backgroundColor: 'transparent',
        fillStyle: 'solid',
        strokeWidth: 1,
        strokeStyle: 'solid',
        roughness: 0,
        opacity: 100,
        groupIds: [],
        frameId: null,
        roundness: null,
        seed: Math.floor(Math.random() * 2 ** 31),
        version: 1,
        versionNonce: Math.floor(Math.random() * 2 ** 31),
        isDeleted: false,
        boundElements: null,
        updated: Date.now(),
        link,
        locked: false,
        customData: { wardKind: 'artboard' },
    }], null)[0];
}

export function randomId() {
    const bytes = new Uint8Array(15);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'[byte % 62]).join('').slice(0, 20);
}

/** Desloca elementos recém-criados para que o canto superior esquerdo caia em (x, y). */
export function placeAt(elements, x, y) {
    const shapes = elements.filter((element) => !element.containerId);
    const minX = Math.min(...shapes.map((element) => element.x));
    const minY = Math.min(...shapes.map((element) => element.y));
    return elements.map((element) => ({ ...element, x: element.x - minX + x, y: element.y - minY + y }));
}

const shape = (props) => ({ roughness: 0, strokeWidth: 2, fillStyle: 'solid', ...props });
const label = (text) => ({ text, fontSize: 20, fontFamily: FONT_FAMILY.Nunito });

/** Biblioteca Ward: post-its, formas de fluxograma e um mini fluxo pronto. */
export function wardLibrary() {
    const item = (name, skeleton) => ({
        id: `ward-${name}`,
        status: 'published',
        name,
        created: 1,
        elements: convertToExcalidrawElements(skeleton, { regenerateIds: true }).map((element) => (
            name.startsWith('Post-it') && element.type === 'rectangle'
                ? { ...element, customData: { wardKind: 'sticky' } }
                : element
        )),
    });

    return [
        ...STICKY_COLORS.slice(0, 5).map(([color, colorName]) => item(`Post-it ${colorName.toLowerCase()}`, [
            shape({ type: 'rectangle', x: 0, y: 0, width: STICKY_SIZE, height: STICKY_SIZE, backgroundColor: color, strokeColor: 'transparent', strokeWidth: 1, roundness: { type: 3 } }),
        ])),
        item('Início / fim', [shape({ type: 'ellipse', x: 0, y: 0, width: 180, height: 80, backgroundColor: '#b2f2bb', label: label('Início') })]),
        item('Processo', [shape({ type: 'rectangle', x: 0, y: 0, width: 180, height: 80, backgroundColor: '#a5d8ff', roundness: { type: 3 }, label: label('Processo') })]),
        item('Decisão', [shape({ type: 'diamond', x: 0, y: 0, width: 200, height: 120, backgroundColor: '#ffec99', label: label('Decisão?') })]),
        item('Dados', [shape({ type: 'rectangle', x: 0, y: 0, width: 180, height: 80, backgroundColor: '#d0bfff', strokeStyle: 'dashed', label: label('Dados') })]),
        item('Fluxo simples', [
            shape({ type: 'rectangle', id: 'a', x: 0, y: 0, width: 180, height: 80, backgroundColor: '#a5d8ff', roundness: { type: 3 }, label: label('Etapa 1') }),
            shape({ type: 'rectangle', id: 'b', x: 280, y: 0, width: 180, height: 80, backgroundColor: '#a5d8ff', roundness: { type: 3 }, label: label('Etapa 2') }),
            { type: 'arrow', x: 188, y: 40, width: 84, height: 0, strokeWidth: 2, roughness: 0, start: { id: 'a' }, end: { id: 'b' } },
        ]),
    ];
}

/** Frames do quadro na ordem de leitura (linhas de cima para baixo, da esquerda para a direita). */
export function framesInOrder(elements) {
    const frames = elements.filter((element) => element.type === 'frame' && !element.isDeleted);
    return frames.sort((a, b) => (Math.abs(a.y - b.y) < 200 ? a.x - b.x : a.y - b.y));
}

/** Pode aparecer embutido: artboards do próprio app e os domínios liberados. */
export function embeddableValidator(config) {
    const appHost = new URL(config.appOrigin).host;
    return (link) => {
        try {
            const url = new URL(link);
            if (url.host === appHost) return true;
            return config.embedHosts.some((host) => url.hostname === host || url.hostname.endsWith(`.${host}`));
        } catch {
            return false;
        }
    };
}
