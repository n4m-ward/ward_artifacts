(() => {
  // Runtime do Ward Design, injetado ao servir um artboard (não fica salvo).
  // - Links "board:Nome" pedem à página do canvas para trocar de artboard.
  // - Erros de script e de carregamento vão para o canvas (que marca o artboard).
  // - O canvas pergunta "que elemento está neste ponto?" (comentários e seleção).
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

  const describe = (element) => {
    const rect = element.getBoundingClientRect();
    const text = (element.innerText || element.textContent || '').replace(/\s+/g, ' ').trim();
    return {
      path: pathOf(element),
      tag: element.tagName.toLowerCase(),
      id: element.id || null,
      classes: typeof element.className === 'string' ? element.className.slice(0, 120) : null,
      text: text.slice(0, 120),
      html: element.outerHTML.slice(0, 800),
      rect: { x: Math.round(rect.left), y: Math.round(rect.top), w: Math.round(rect.width), h: Math.round(rect.height) },
    };
  };

  window.addEventListener('message', (event) => {
    if (event.source !== window.parent) return;
    const data = event.data;
    if (!data || data.ward !== 'pick') return;
    let element = document.elementFromPoint(data.x, data.y);
    if (element === document.documentElement) element = null;
    post({ ward: 'picked', request: data.request, element: element ? describe(element) : null });
  });
})();
