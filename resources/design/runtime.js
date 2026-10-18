(() => {
  // Runtime do Ward Design, injetado ao servir um artboard (não fica salvo).
  // - Links "board:Nome" pedem à página do canvas para trocar de artboard.
  // - Erros de script e de carregamento vão para o canvas (que marca o artboard).
  // - O canvas pergunta "que elemento está neste ponto?" (comentários e seleção).
  // Impressão (PDF): o artboard aberto sozinho com ?print=1 ajusta a página ao tamanho dele e imprime.
  if (/[?&]print=1(&|$)/.test(location.search)) {
    window.addEventListener('load', async () => {
      try { await document.fonts.ready; } catch { /* sem fontes */ }
      const width = Math.max(document.documentElement.scrollWidth, window.innerWidth);
      const height = Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0);
      const style = document.createElement('style');
      style.textContent = `@page { size: ${width}px ${height}px; margin: 0 } html, body { margin: 0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact }`;
      document.head.append(style);
      setTimeout(() => window.print(), 400);
    });
  }

  if (window.parent === window) return;

  const post = (message) => {
    try {
      window.parent.postMessage(message, '*');
    } catch {
      // página fechada
    }
  };

  let errors = 0;
  const reportError = (message) => {
    if (errors++ > 20) return;
    post({ ward: 'error', message: String(message || 'Erro desconhecido').slice(0, 300) });
  };

  window.addEventListener('error', (event) => {
    const target = event.target;
    if (target && target !== window && (target.src || target.href)) {
      reportError(`Não carregou: <${target.tagName.toLowerCase()}> ${target.src || target.href}`);
      return;
    }
    reportError(event.message + (event.lineno ? ` (linha ${event.lineno})` : ''));
  }, true);

  window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason;
    reportError(`Promise rejeitada: ${(reason && reason.message) || reason}`);
  });

  document.addEventListener('click', (event) => {
    const link = event.target.closest && event.target.closest('a[href^="board:"]');
    if (!link) return;
    event.preventDefault();
    const board = decodeURIComponent(link.getAttribute('href').slice(6));
    post({ ward: 'navigate', board });
  }, true);

  // Caminho do elemento: posição entre os filhos, do <body> até ele ("0/2/1").
  const pathOf = (element) => {
    const parts = [];
    let node = element;
    while (node && node !== document.body && node.parentElement) {
      parts.unshift(Array.prototype.indexOf.call(node.parentElement.children, node));
      node = node.parentElement;
    }
    return parts.join('/');
  };

  const byPath = (path) => {
    let node = document.body;
    if (path === '' || path === undefined || path === null) return node;
    for (const index of String(path).split('/')) {
      node = node && node.children[Number(index)];
    }
    return node || null;
  };

  const STYLE_KEYS = [
    'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'color', 'text-align', 'text-transform',
    'background-color', 'background-image', 'padding', 'margin', 'border', 'border-radius', 'box-shadow', 'opacity',
    'display', 'flex-direction', 'flex-wrap', 'gap', 'justify-content', 'align-items', 'width', 'height', 'position',
  ];

  const describe = (element) => {
    const rect = element.getBoundingClientRect();
    const text = (element.innerText || element.textContent || '').replace(/\s+/g, ' ').trim();
    const computed = getComputedStyle(element);
    const styles = {};
    for (const key of STYLE_KEYS) styles[key] = computed.getPropertyValue(key);
    const parent = element.parentElement && element.parentElement !== document.documentElement ? getComputedStyle(element.parentElement) : null;
    return {
      path: pathOf(element),
      tag: element.tagName.toLowerCase(),
      id: element.id || null,
      classes: typeof element.className === 'string' ? element.className.slice(0, 120) : null,
      text: text.slice(0, 120),
      html: element.outerHTML.slice(0, 800),
      rect: { x: Math.round(rect.left), y: Math.round(rect.top), w: Math.round(rect.width), h: Math.round(rect.height) },
      styles,
      inline: element.getAttribute('style') || '',
      // Só texto (sem filhos elementos): dá para editar no lugar.
      textOnly: element.children.length === 0 && text.length > 0,
      attrs: { src: element.getAttribute('src'), alt: element.getAttribute('alt'), href: element.getAttribute('href') },
      parentDisplay: parent ? parent.display : null,
      childCount: element.children.length,
    };
  };

  // Tweaks: <script type="application/json" id="ward-tweaks"> com os controles do artboard.
  // color/range/text viram variáveis CSS (--nome); boolean com "class" liga uma classe no <html>;
  // enum vira data-nome no <html>.
  const applyTweak = (key, def, value) => {
    const root = document.documentElement;
    if (def.type === 'boolean') {
      if (def.class) root.classList.toggle(def.class, Boolean(value));
      else root.toggleAttribute(`data-${key}`, Boolean(value));
    } else if (def.type === 'enum') {
      root.setAttribute(`data-${key}`, String(value));
    } else {
      root.style.setProperty(`--${key}`, `${value}${def.unit || ''}`);
    }
  };
  const readTweaks = () => {
    try {
      const node = document.getElementById('ward-tweaks');
      return node ? JSON.parse(node.textContent) : null;
    } catch {
      reportError('ward-tweaks: JSON inválido');
      return null;
    }
  };
  const tweaksAtLoad = () => {
    const tweaks = readTweaks();
    if (!tweaks) return;
    for (const [key, def] of Object.entries(tweaks)) {
      if (def && typeof def === 'object' && 'value' in def) applyTweak(key, def, def.value);
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tweaksAtLoad);
  else tweaksAtLoad();

  let editing = null;

  window.addEventListener('message', (event) => {
    if (event.source !== window.parent) return;
    const data = event.data;
    if (!data || typeof data.ward !== 'string') return;

    if (data.ward === 'pick') {
      let element = document.elementFromPoint(data.x, data.y);
      if (element === document.documentElement) element = null;
      post({ ward: 'picked', request: data.request, element: element ? describe(element) : null });
    } else if (data.ward === 'describe') {
      const element = byPath(data.path);
      post({ ward: 'picked', request: data.request, element: element && element !== document.body ? describe(element) : null });
    } else if (data.ward === 'apply') {
      // Prévia ao vivo de uma edição (o HTML salvo chega logo depois).
      const element = byPath(data.path);
      if (!element) return;
      for (const [prop, value] of Object.entries(data.style || {})) {
        if (value === null || value === '') element.style.removeProperty(prop);
        else element.style.setProperty(prop, value);
      }
      for (const [name, value] of Object.entries(data.attrs || {})) {
        if (value === null) element.removeAttribute(name);
        else element.setAttribute(name, value);
      }
      if (typeof data.text === 'string') element.textContent = data.text;
    } else if (data.ward === 'rules') {
      // Regras CSS das folhas do artboard que se aplicam ao elemento (as de outra origem não são legíveis).
      const element = byPath(data.path);
      const rules = [];
      const walk = (list, media) => {
        for (const rule of Array.from(list || [])) {
          if (rules.length >= 40) return;
          if (rule.cssRules && !rule.selectorText) {
            walk(rule.cssRules, rule.conditionText || rule.media?.mediaText || media);
          } else if (rule.selectorText) {
            let hit = false;
            try { hit = element.matches(rule.selectorText); } catch { hit = false; }
            if (hit) rules.push({ selector: rule.selectorText, css: rule.style.cssText, media: media || null });
          }
        }
      };
      if (element) {
        for (const sheet of Array.from(document.styleSheets)) {
          let list = null;
          try { list = sheet.cssRules; } catch { list = null; }
          if (list && (!sheet.ownerNode || sheet.ownerNode.tagName === 'STYLE')) walk(list, null);
        }
      }
      post({ ward: 'answer', request: data.request, value: rules });
    } else if (data.ward === 'rule-preview') {
      // Prévia ao vivo de uma regra editada.
      const visit = (list) => {
        for (const rule of Array.from(list || [])) {
          if (rule.cssRules && !rule.selectorText) visit(rule.cssRules);
          else if (rule.selectorText === data.selector) rule.style.cssText = data.css;
        }
      };
      for (const sheet of Array.from(document.styleSheets)) {
        try { visit(sheet.cssRules); } catch { /* folha de outra origem */ }
      }
    } else if (data.ward === 'export-png') {
      // PNG do artboard, desenhado aqui dentro (fontes e imagens da própria página).
      const done = (value) => post({ ward: 'answer', request: data.request, value });
      // Fontes de outra origem (Google Fonts): o exportador não lê a folha delas, então
      // baixamos o CSS e embutimos os arquivos dos alfabetos latinos.
      const fontCss = async () => {
        const links = Array.from(document.querySelectorAll('link[rel~="stylesheet"]'))
          .filter((link) => /fonts\.(googleapis|bunny)\.(com|net)/.test(link.href));
        let css = '';
        for (const link of links) {
          try {
            const text = await (await fetch(link.href)).text();
            const blocks = text.split(/(?=\/\*\s*[\w-]+\s*\*\/)/);
            const latin = blocks.filter((block) => /^\/\*\s*latin(-ext)?\s*\*\//.test(block.trim()));
            css += (latin.length ? latin : blocks).join('\n');
          } catch { /* sem a fonte: usa a de reserva */ }
        }
        const urls = Array.from(new Set(Array.from(css.matchAll(/url\((["']?)(https:[^)"']+)\1\)/g), (match) => match[2])));
        for (const url of urls) {
          try {
            const blob = await (await fetch(url)).blob();
            const dataUrl = await new Promise((resolve, reject) => {
              const reader = new FileReader();
              reader.onload = () => resolve(reader.result);
              reader.onerror = reject;
              reader.readAsDataURL(blob);
            });
            css = css.split(url).join(dataUrl);
          } catch { /* fica a URL */ }
        }
        return css;
      };
      const render = async () => {
        try {
          try { await document.fonts.ready; } catch { /* segue */ }
          const embedded = await fontCss();
          const url = await window.htmlToImage.toPng(document.documentElement, {
            width: window.innerWidth, height: window.innerHeight, pixelRatio: data.scale || 1, cacheBust: true,
            ...(embedded ? { fontEmbedCSS: embedded } : {}),
          });
          done({ url });
        } catch (error) {
          done({ error: String((error && error.message) || error || 'falhou') });
        }
      };
      if (window.htmlToImage) return render();
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.js';
      script.onload = render;
      script.onerror = () => done({ error: 'Não consegui carregar o exportador de imagem.' });
      document.head.append(script);
    } else if (data.ward === 'tweak') {
      const tweaks = readTweaks();
      if (tweaks && tweaks[data.key]) applyTweak(data.key, tweaks[data.key], data.value);
    } else if (data.ward === 'tweaks') {
      post({ ward: 'tweaks-read', request: data.request, tweaks: readTweaks() });
    } else if (data.ward === 'edit-text') {
      const element = byPath(data.path);
      if (!element || editing) return;
      editing = element;
      const before = element.textContent;
      element.setAttribute('contenteditable', 'plaintext-only');
      if (element.contentEditable !== 'plaintext-only') element.setAttribute('contenteditable', 'true');
      element.focus();
      const range = document.createRange();
      range.selectNodeContents(element);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      let cancelled = false;
      const keydown = (e) => {
        if (e.key === 'Escape') {
          cancelled = true;
          element.textContent = before;
          element.blur();
        } else if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          element.blur();
        }
      };
      element.addEventListener('keydown', keydown);
      element.addEventListener('blur', () => {
        element.removeEventListener('keydown', keydown);
        element.removeAttribute('contenteditable');
        editing = null;
        post({ ward: 'text-edited', path: data.path, text: cancelled ? null : element.textContent, before });
      }, { once: true });
    }
  });
})();
