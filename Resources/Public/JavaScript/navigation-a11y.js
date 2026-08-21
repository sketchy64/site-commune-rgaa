/**
 * Site Commune RGAA - Accessibilité de la navigation et gestion clavier (RGAA 12.8 / 12.9)
 * Gestion des touches Escape, Tab et focus trap sur les menus déroulants et volets mobiles.
 */

document.addEventListener('DOMContentLoaded', () => {
  const navElement = document.getElementById('main-navigation');
  if (!navElement) return;

  // Fermeture des sous-menus au clavier avec la touche Échap
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' || event.key === 'Esc') {
      const openDropdowns = document.querySelectorAll('#main-navigation .dropdown-menu.show');
      openDropdowns.forEach(dropdown => {
        const toggle = dropdown.parentElement.querySelector('[data-bs-toggle="dropdown"]');
        if (toggle && window.bootstrap && window.bootstrap.Dropdown) {
          const bsDropdown = window.bootstrap.Dropdown.getInstance(toggle);
          if (bsDropdown) {
            bsDropdown.hide();
            toggle.focus();
          }
        }
      });

      // Fermeture du menu mobile si ouvert
      const mobileNav = document.getElementById('navbarMainContent');
      if (mobileNav && mobileNav.classList.contains('show')) {
        const toggler = document.querySelector('.navbar-toggler');
        if (toggler && window.bootstrap && window.bootstrap.Collapse) {
          const bsCollapse = window.bootstrap.Collapse.getInstance(mobileNav);
          if (bsCollapse) {
            bsCollapse.hide();
            toggler.focus();
          }
        }
      }
      // Fermeture de la zone de recherche du menu si ouverte
      const menuSearchCollapse = document.getElementById('menu-search-collapse');
      if (menuSearchCollapse && menuSearchCollapse.classList.contains('show')) {
        const searchBtn = document.querySelector('.btn-menu-search');
        if (window.bootstrap && window.bootstrap.Collapse) {
          const bsCollapse = window.bootstrap.Collapse.getInstance(menuSearchCollapse);
          if (bsCollapse) {
            bsCollapse.hide();
            if (searchBtn) searchBtn.focus();
          }
        }
      }
    }
  });

  // Focus automatique sur le champ de recherche lors de l'ouverture du volet
  const menuSearchCollapseEl = document.getElementById('menu-search-collapse');
  if (menuSearchCollapseEl) {
    menuSearchCollapseEl.addEventListener('shown.bs.collapse', () => {
      const searchInput = document.getElementById('menu-search-input');
      if (searchInput) searchInput.focus();
    });
  }

  // Synchronisation des états aria-expanded et de l'accessibilité sur les Megamenus
  const megamenuToggles = navElement.querySelectorAll('[data-bs-toggle="dropdown"]');
  megamenuToggles.forEach(toggle => {
    const dropdownParent = toggle.parentElement;
    
    dropdownParent.addEventListener('show.bs.dropdown', () => {
      toggle.setAttribute('aria-expanded', 'true');
    });

    dropdownParent.addEventListener('hide.bs.dropdown', () => {
      toggle.setAttribute('aria-expanded', 'false');
    });
  });

  // Synchronisation des états aria-expanded sur les accordéons et boutons
  const collapseButtons = document.querySelectorAll('[data-bs-toggle="collapse"]');
  collapseButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const isExpanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', (!isExpanded).toString());
    });
  });

  // Bouton Retour en haut de page avec défilement fluide
  const backToTopBtn = document.getElementById('commune-back-to-top');
  if (backToTopBtn) {
    window.addEventListener('scroll', () => {
      if (window.scrollY > 300) {
        backToTopBtn.classList.add('visible');
      } else {
        backToTopBtn.classList.remove('visible');
      }
    });

    backToTopBtn.addEventListener('click', () => {
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
      // Replacer le focus en haut de page pour les lecteurs d'écran
      const skipLink = document.querySelector('.skip-links a') || document.body;
      if (skipLink) {
        skipLink.focus();
      }
    });
  }
});
