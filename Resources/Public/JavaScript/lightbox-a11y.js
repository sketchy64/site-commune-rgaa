/**
 * Site Commune RGAA - Visionneuse Lightbox Accessible (RGAA 4.1.2 & WCAG 2.1 AA)
 * Support natif zero-dépendance et pont GLightbox pour l'affichage plein écran des images
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    initAccessibleLightbox();
  });

  function initAccessibleLightbox() {
    // Si GLightbox est déjà présent et chargé par bootstrap_package
    if (typeof window.GLightbox === 'function') {
      try {
        window.GLightbox({
          selector: '.glightbox, .lightbox, .news-detail-lightbox-link',
          touchNavigation: true,
          loop: false,
          zoomable: true
        });
        return;
      } catch (e) {
        console.warn('[A11y Lightbox] GLightbox init error, fallbacking to native a11y lightbox', e);
      }
    }

    // Gestionnaire natif accessible et robuste
    const lightboxLinks = document.querySelectorAll('.news-detail-lightbox-link, a.lightbox, a.glightbox, [data-lightbox]');
    if (!lightboxLinks.length) return;

    // Créer la structure de la modale dans le DOM
    const modal = document.createElement('div');
    modal.className = 'commune-a11y-lightbox-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', 'Image agrandie');
    modal.setAttribute('hidden', '');
    modal.innerHTML = `
      <div class="commune-lightbox-backdrop" tabindex="-1"></div>
      <div class="commune-lightbox-container" role="document">
        <button type="button" class="commune-lightbox-close-btn btn btn-light rounded-circle shadow" aria-label="Fermer la vue agrandie (Touche Échap)" title="Fermer (Échap)">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
        </button>
        <div class="commune-lightbox-figure-wrapper">
          <figure class="commune-lightbox-figure">
            <div class="commune-lightbox-image-container">
              <img src="" alt="" class="commune-lightbox-image img-fluid" />
            </div>
            <figcaption class="commune-lightbox-caption"></figcaption>
          </figure>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const backdrop = modal.querySelector('.commune-lightbox-backdrop');
    const closeBtn = modal.querySelector('.commune-lightbox-close-btn');
    const imgEl = modal.querySelector('.commune-lightbox-image');
    const captionEl = modal.querySelector('.commune-lightbox-caption');
    let lastActiveElement = null;

    function openLightbox(link) {
      lastActiveElement = link;
      const imgSrc = link.getAttribute('href');
      const title = link.getAttribute('data-title') || link.getAttribute('title') || '';
      const desc = link.getAttribute('data-description') || '';
      const altText = link.querySelector('img') ? link.querySelector('img').getAttribute('alt') : (title || 'Image agrandie');

      imgEl.setAttribute('src', imgSrc);
      imgEl.setAttribute('alt', altText);

      // Légende
      let captionHtml = '';
      if (title) {
        captionHtml += `<strong class="d-block mb-1 fs-6">${escapeHtml(title)}</strong>`;
      }
      if (desc) {
        captionHtml += `<span class="text-white-50 small">${escapeHtml(desc)}</span>`;
      }
      captionEl.innerHTML = captionHtml;
      captionEl.style.display = captionHtml ? 'block' : 'none';

      modal.removeAttribute('hidden');
      document.body.classList.add('commune-lightbox-open');
      
      // Animation d'ouverture
      requestAnimationFrame(() => {
        modal.classList.add('is-active');
        closeBtn.focus();
      });

      document.addEventListener('keydown', handleKeyDown);
    }

    function closeLightbox() {
      modal.classList.remove('is-active');
      document.body.classList.remove('commune-lightbox-open');
      document.removeEventListener('keydown', handleKeyDown);

      setTimeout(() => {
        modal.setAttribute('hidden', '');
        imgEl.setAttribute('src', '');
        if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
          lastActiveElement.focus();
        }
      }, 250);
    }

    function handleKeyDown(e) {
      if (e.key === 'Escape' || e.key === 'Esc') {
        e.preventDefault();
        closeLightbox();
      } else if (e.key === 'Tab') {
        // Piège de focus dans la modale
        const focusableElements = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (e.shiftKey) {
          if (document.activeElement === firstElement) {
            e.preventDefault();
            lastElement.focus();
          }
        } else {
          if (document.activeElement === lastElement) {
            e.preventDefault();
            firstElement.focus();
          }
        }
      }
    }

    function escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }

    // Écouteurs de fermeture
    closeBtn.addEventListener('click', (e) => {
      e.preventDefault();
      closeLightbox();
    });

    backdrop.addEventListener('click', () => {
      closeLightbox();
    });

    // Écouteurs sur les liens de déclenchement
    lightboxLinks.forEach(link => {
      link.addEventListener('click', (e) => {
        const href = link.getAttribute('href');
        if (href && (href.match(/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i) || link.classList.contains('news-detail-lightbox-link') || link.classList.contains('lightbox') || link.classList.contains('glightbox'))) {
          e.preventDefault();
          openLightbox(link);
        }
      });
    });
  }
})();
