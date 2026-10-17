(() => {
  // Runtime do Ward Design, injetado ao servir um artboard (não fica salvo).
  // Links "board:Nome" pedem à página do canvas para trocar de artboard.
  if (window.parent === window) return;
  document.addEventListener('click', (event) => {
    const link = event.target.closest && event.target.closest('a[href^="board:"]');
    if (!link) return;
    event.preventDefault();
    const board = decodeURIComponent(link.getAttribute('href').slice(6));
    window.parent.postMessage({ ward: 'navigate', board }, '*');
  }, true);
})();
