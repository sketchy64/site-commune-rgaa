/**
 * Lightbox Accessible RGAA 4.1.2 pour TYPO3 Site Commune RGAA
 * Permet d'agrandir les images (Actualites, Textmedia, etc.) au clic
 * Gestion complete du clavier (Escape, Tab trap), du focus et de l'accessibilite
 */
(function() {
    'use strict';

    function initCommuneLightbox() {
        var lightboxLinks = document.querySelectorAll('a.lightbox, a.news-detail-lightbox-link, [data-lightbox]');
        if (!lightboxLinks.length) return;

        var activeTrigger = null;
        var overlay = document.getElementById('commune-lightbox-overlay');

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'commune-lightbox-overlay';
            overlay.className = 'commune-lightbox-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', "Visionneuse d'image agrandie");
            overlay.setAttribute('tabindex', '-1');
            overlay.style.display = 'none';

            overlay.innerHTML = '<div class="commune-lightbox-backdrop" aria-hidden="true"></div>' +
                '<div class="commune-lightbox-container">' +
                    '<button type="button" class="commune-lightbox-close" aria-label="Fermer la visionneuse d\\'image (Touche Echap)">' +
                        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                            '<line x1="18" y1="6" x2="6" y2="18"></line>' +
                            '<line x1="6" y1="6" x2="18" y2="18"></line>' +
                        '</svg>' +
                        '<span class="visually-hidden">Fermer</span>' +
                    '</button>' +
                    '<div class="commune-lightbox-figure-wrapper">' +
                        '<figure class="commune-lightbox-figure">' +
                            '<img src="" alt="" class="commune-lightbox-img" />' +
                            '<figcaption class="commune-lightbox-caption"></figcaption>' +
                        '</figure>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(overlay);

            var closeBtn = overlay.querySelector('.commune-lightbox-close');
            var backdrop = overlay.querySelector('.commune-lightbox-backdrop');

            function closeLightbox() {
                overlay.classList.remove('active');
                document.body.classList.remove('commune-lightbox-open');
                setTimeout(function() {
                    overlay.style.display = 'none';
                    var img = overlay.querySelector('.commune-lightbox-img');
                    if (img) img.src = '';
                    if (activeTrigger) {
                        activeTrigger.focus();
                        activeTrigger = null;
                    }
                }, 200);
            }

            closeBtn.addEventListener('click', closeLightbox);
            backdrop.addEventListener('click', closeLightbox);
        }

        var closeBtn = overlay.querySelector('.commune-lightbox-close');
        var imgEl = overlay.querySelector('.commune-lightbox-img');
        var captionEl = overlay.querySelector('.commune-lightbox-caption');

        lightboxLinks.forEach(function(link) {
            if (link.dataset.lightboxInitialized) return;
            link.dataset.lightboxInitialized = 'true';

            link.addEventListener('click', function(e) {
                e.preventDefault();
                activeTrigger = this;

                var targetSrc = this.getAttribute('href');
                var title = this.getAttribute('data-title') || this.getAttribute('title') || '';
                var desc = this.getAttribute('data-description') || '';
                var innerImg = this.querySelector('img');
                var alt = innerImg ? innerImg.getAttribute('alt') : (title || 'Image agrandie');

                imgEl.src = targetSrc;
                imgEl.alt = alt;

                var captionText = '';
                if (title && desc && title !== desc) {
                    captionText = '<strong>' + title + '</strong> — ' + desc;
                } else if (title) {
                    captionText = '<strong>' + title + '</strong>';
                } else if (desc) {
                    captionText = desc;
                }

                if (captionText) {
                    captionEl.innerHTML = captionText;
                    captionEl.style.display = 'block';
                } else {
                    captionEl.innerHTML = '';
                    captionEl.style.display = 'none';
                }

                overlay.style.display = 'flex';
                overlay.offsetHeight;
                overlay.classList.add('active');
                document.body.classList.add('commune-lightbox-open');

                closeBtn.focus();

                function onKeyDown(evt) {
                    if (evt.key === 'Escape' || evt.keyCode === 27) {
                        evt.preventDefault();
                        overlay.querySelector('.commune-lightbox-close').click();
                        document.removeEventListener('keydown', onKeyDown);
                    } else if (evt.key === 'Tab' || evt.keyCode === 9) {
                        evt.preventDefault();
                        closeBtn.focus();
                    }
                }
                document.addEventListener('keydown', onKeyDown);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCommuneLightbox);
    } else {
        initCommuneLightbox();
    }
})();
