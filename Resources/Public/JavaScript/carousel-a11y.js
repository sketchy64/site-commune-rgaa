/**
 * Site Commune RGAA - Carrousel accessible (RGAA Critère 13.8)
 * Gestion avancée de la navigation clavier (Flèches), des gestes tactiles (Swipe),
 * de la mise en pause/reprise et des indicateurs dynamiques.
 */

document.addEventListener('DOMContentLoaded', () => {
  const newsCarousels = document.querySelectorAll('.commune-news-carousel');

  newsCarousels.forEach(carouselEl => {
    const carouselId = carouselEl.id;
    const playPauseBtn = carouselEl.querySelector('.carousel-play-pause-btn');
    const counterCurrent = carouselEl.querySelector('.carousel-counter-current');
    const indicatorBullets = carouselEl.querySelectorAll('.carousel-indicator-bullet');
    let isPaused = false;

    // Instance Bootstrap Carousel
    const getBsCarousel = () => {
      if (window.bootstrap && window.bootstrap.Carousel) {
        return window.bootstrap.Carousel.getOrCreateInstance(carouselEl);
      }
      return null;
    };

    // 1. Gestion du bouton Pause / Lecture (Critère RGAA 13.8)
    if (playPauseBtn) {
      playPauseBtn.addEventListener('click', () => {
        isPaused = !isPaused;
        const btnIcon = playPauseBtn.querySelector('.btn-icon');
        const btnText = playPauseBtn.querySelector('.btn-text');
        const bsCarousel = getBsCarousel();

        if (isPaused) {
          if (bsCarousel) bsCarousel.pause();
          playPauseBtn.setAttribute('aria-pressed', 'true');
          playPauseBtn.setAttribute('aria-label', 'Reprendre la lecture automatique du carrousel');
          playPauseBtn.setAttribute('title', 'Reprendre la lecture automatique');
          if (btnIcon) btnIcon.textContent = '▶';
          if (btnText) btnText.textContent = 'Lecture';
        } else {
          if (bsCarousel) bsCarousel.cycle();
          playPauseBtn.setAttribute('aria-pressed', 'false');
          playPauseBtn.setAttribute('aria-label', 'Mettre en pause le défilement automatique du carrousel');
          playPauseBtn.setAttribute('title', 'Mettre en pause le défilement automatique');
          if (btnIcon) btnIcon.textContent = '⏸';
          if (btnText) btnText.textContent = 'Pause';
        }
      });
    }

    // 2. Événement au changement de diapositive (`slid.bs.carousel`)
    carouselEl.addEventListener('slid.bs.carousel', (event) => {
      const activeIndex = event.to;

      // Mise à jour du compteur dynamique
      if (counterCurrent) {
        counterCurrent.textContent = (activeIndex + 1).toString();
      }

      // Mise à jour des attributs ARIA sur les puces indicateurs
      indicatorBullets.forEach((bullet, index) => {
        const isActive = index === activeIndex;
        bullet.classList.toggle('active', isActive);
        bullet.setAttribute('aria-selected', isActive ? 'true' : 'false');
        bullet.setAttribute('aria-current', isActive ? 'true' : 'false');
      });
    });

    // 3. Navigation au clavier (Flèche Gauche / Flèche Droite) lorsque le focus est dans le carrousel
    carouselEl.addEventListener('keydown', (event) => {
      const bsCarousel = getBsCarousel();
      if (!bsCarousel) return;

      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        bsCarousel.prev();
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        bsCarousel.next();
      }
    });

    // 4. Pause automatique à la prise de focus clavier (RGAA 13.8)
    carouselEl.addEventListener('focusin', () => {
      const bsCarousel = getBsCarousel();
      if (bsCarousel) bsCarousel.pause();
    });

    carouselEl.addEventListener('focusout', (event) => {
      // Reprendre seulement si le focus sort totalement du carrousel et qu'il n'est pas mis en pause manuellement
      if (!isPaused && !carouselEl.contains(event.relatedTarget)) {
        const bsCarousel = getBsCarousel();
        if (bsCarousel) bsCarousel.cycle();
      }
    });

    // 5. Prise en charge des gestes tactiles (Swipe sur smartphones/tablettes)
    let touchStartX = 0;
    let touchEndX = 0;

    carouselEl.addEventListener('touchstart', (event) => {
      touchStartX = event.changedTouches[0].screenX;
    }, { passive: true });

    carouselEl.addEventListener('touchend', (event) => {
      touchEndX = event.changedTouches[0].screenX;
      const swipeDistance = touchEndX - touchStartX;
      const minSwipeDistance = 50; // Seuil minimum en pixels

      const bsCarousel = getBsCarousel();
      if (!bsCarousel) return;

      if (swipeDistance > minSwipeDistance) {
        // Swipe vers la droite -> Diapositive précédente
        bsCarousel.prev();
      } else if (swipeDistance < -minSwipeDistance) {
        // Swipe vers la gauche -> Diapositive suivante
        bsCarousel.next();
      }
    }, { passive: true });
  });
});

