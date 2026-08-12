/**
 * Site Commune RGAA - Gestion de la barre d'outils d'accessibilité
 * Persistance dans localStorage et synchronisation des états ARIA.
 */

document.addEventListener('DOMContentLoaded', () => {
  const STORAGE_PREFIX = 'commune_a11y_';
  const body = document.body;

  // 1. Gestion de la taille de texte
  const fontLevels = ['', 'a11y-font-size-md', 'a11y-font-size-lg', 'a11y-font-size-xl'];
  let currentFontIndex = parseInt(localStorage.getItem(STORAGE_PREFIX + 'fontsize') || '0', 10);

  function applyFontSize(index) {
    currentFontIndex = Math.max(0, Math.min(index, fontLevels.length - 1));
    fontLevels.forEach(cls => {
      if (cls) body.classList.remove(cls);
    });
    if (fontLevels[currentFontIndex]) {
      body.classList.add(fontLevels[currentFontIndex]);
    }
    localStorage.setItem(STORAGE_PREFIX + 'fontsize', currentFontIndex.toString());
  }

  const btnFontDecrease = document.getElementById('a11y-font-decrease');
  const btnFontReset = document.getElementById('a11y-font-reset');
  const btnFontIncrease = document.getElementById('a11y-font-increase');

  if (btnFontDecrease) {
    btnFontDecrease.addEventListener('click', () => applyFontSize(currentFontIndex - 1));
  }
  if (btnFontReset) {
    btnFontReset.addEventListener('click', () => applyFontSize(0));
  }
  if (btnFontIncrease) {
    btnFontIncrease.addEventListener('click', () => applyFontSize(currentFontIndex + 1));
  }

  // 2. Gestion du mode Contraste Renforcé
  const btnContrast = document.getElementById('a11y-toggle-contrast');
  let isContrastActive = localStorage.getItem(STORAGE_PREFIX + 'contrast') === 'true';

  function applyContrast(active) {
    isContrastActive = active;
    if (isContrastActive) {
      body.classList.add('a11y-high-contrast');
    } else {
      body.classList.remove('a11y-high-contrast');
    }
    if (btnContrast) {
      btnContrast.setAttribute('aria-pressed', isContrastActive.toString());
    }
    localStorage.setItem(STORAGE_PREFIX + 'contrast', isContrastActive.toString());
  }

  if (btnContrast) {
    btnContrast.addEventListener('click', () => applyContrast(!isContrastActive));
  }

  // 3. Gestion de la police pour dyslexie
  const btnDyslexic = document.getElementById('a11y-toggle-dyslexic');
  let isDyslexicActive = localStorage.getItem(STORAGE_PREFIX + 'dyslexic') === 'true';

  function applyDyslexic(active) {
    isDyslexicActive = active;
    if (isDyslexicActive) {
      body.classList.add('a11y-dyslexic');
    } else {
      body.classList.remove('a11y-dyslexic');
    }
    if (btnDyslexic) {
      btnDyslexic.setAttribute('aria-pressed', isDyslexicActive.toString());
    }
    localStorage.setItem(STORAGE_PREFIX + 'dyslexic', isDyslexicActive.toString());
  }

  if (btnDyslexic) {
    btnDyslexic.addEventListener('click', () => applyDyslexic(!isDyslexicActive));
  }

  // Initialisation à partir des préférences enregistrées
  applyFontSize(currentFontIndex);
  applyContrast(isContrastActive);
  applyDyslexic(isDyslexicActive);
});
