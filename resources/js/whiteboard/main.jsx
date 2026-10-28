// Ward Whiteboard: página do quadro (artifact type=whiteboard). Lê a configuração de
// <script id="ward-whiteboard-config"> (ver App\Whiteboards\WhiteboardState::config)
// e monta o Excalidraw com o ao vivo do Ward. O MCP App (resources/views/mcp/whiteboard-app.blade.php)
// carrega este mesmo pacote e monta o quadro com window.WardWhiteboard.mount.
import { createRoot } from 'react-dom/client';
import '@excalidraw/excalidraw/index.css';
import './whiteboard.css';
import WhiteboardApp from './App.jsx';

/**
 * options.app: dentro do chat (só leitura, sem a barra do Ward).
 * options.fallbackPoll(since, presence): ao vivo pela conexão do app quando o fetch direto falha.
 */
export function mount(root, config, options = {}) {
    window.EXCALIDRAW_ASSET_PATH ??= config.assetPath;
    root.replaceChildren();
    const reactRoot = createRoot(root);
    reactRoot.render(<WhiteboardApp config={config} options={options} />);
    return () => reactRoot.unmount();
}

window.WardWhiteboard = { mount };

const root = document.querySelector('[data-whiteboard]');
const configNode = document.getElementById('ward-whiteboard-config');

if (root && configNode) {
    mount(root, JSON.parse(configNode.textContent));
}
