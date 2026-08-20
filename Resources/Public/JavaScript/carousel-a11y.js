/**
 * Site Commune RGAA - Carrousel accessible (RGAA Critère 13.8)
 * Permet la mise en pause / reprise du défilement automatique.
 */

document.addEventListener('DOMContentLoaded', () => {
  const playPauseButtons = document.querySelectorAll('.carousel-play-pause-btn');

  playPauseButtons.forEach(button => {
    const carouselId = button.getAttribute('data-carousel-id');
    const carouselEl = document.getElementById(carouselId);

    if (!carouselEl) return;

    let isPaused = false;

    button.addEventListener('click', () => {
      isPaused = !isPaused;

      const btnIcon = button.querySelector('.btn-icon');
      const btnText = button.querySelector('.btn-text');

      if (isPaused) {
        if (window.bootstrap && window.bootstrap.Carousel) {
          const bsCarousel = window.bootstrap.Carousel.getOrCreateInstance(carouselEl);
          bsCarousel.pause();
        }
        button.setAttribute('aria-pressed', 'true');
        button.setAttribute('aria-label', 'Reprendre la lecture automatique du carrousel');
        if (btnIcon) btnIcon.textContent = '▶';
        if (btnText) btnText.textContent = 'Lecture';
      } else {
        if (window.bootstrap && window.bootstrap.Carousel) {
          const bsCarousel = window.bootstrap.Carousel.getOrCreateInstance(carouselEl);
          bsCarousel.cycle();
        }
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('aria-label', 'Mettre en pause le carrousel');
        if (btnIcon) btnIcon.textContent = '⏸';
        if (btnText) btnText.textContent = 'Pause';
      }
    });

    // Arrêt automatique au focus clavier à l'intérieur du carrousel
    carouselEl.addEventListener('focusin', () => {
      if (window.bootstrap && window.bootstrap.Carousel) {
        const bsCarousel = window.bootstrap.Carousel.getOrCreateInstance(carouselEl);
        bsCarousel.pause();
      }
    });

    carouselEl.addEventListener('focusout', () => {
      if (!isPaused && window.bootstrap && window.bootstrap.Carousel) {
        const bsCarousel = window.bootstrap.Carousel.getOrCreateInstance(carouselEl);
        bsCarousel.cycle();
      }
    });
  });
});
