/**
 * PWA & Push Notifications Manager - Site Commune RGAA
 * Permet l'installation de l'application sur smartphone et l'abonnement aux alertes
 */

(function () {
  'use strict';

  // 1. Enregistrement du Service Worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker
        .register('/sw.js', { scope: '/' })
        .then(function (reg) {
          // Service worker enregistré
        })
        .catch(function (err) {
          // Erreur silencieuse
        });
    });
  }

  // 2. Gestion de l'invite d'installation PWA (Android / Chrome / Edge)
  let deferredPrompt;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;

    // Afficher le bouton d'installation s'il existe dans la toolbar ou footer
    const installBtn = document.getElementById('pwa-install-btn');
    if (installBtn) {
      installBtn.classList.remove('d-none');
      installBtn.addEventListener('click', () => {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then((choiceResult) => {
          if (choiceResult.outcome === 'accepted') {
            installBtn.classList.add('d-none');
          }
          deferredPrompt = null;
        });
      });
    }
  });

  // 3. Gestion de l'abonnement aux notifications d'actualités
  window.CommuneApp = {
    requestNotificationPermission: function () {
      if (!('Notification' in window)) {
        alert('Votre navigateur ne supporte pas les notifications push.');
        return;
      }

      Notification.requestPermission().then(function (permission) {
        if (permission === 'granted') {
          if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready.then(function (registration) {
              registration.showNotification('Mairie - Notifications activées', {
                body: 'Vous recevrez les alertes et actualités importantes de la commune.',
                icon: '/favicon.ico'
              });
            });
          }
          const notifyBtn = document.getElementById('pwa-notify-btn');
          if (notifyBtn) {
            notifyBtn.innerHTML = '<i class="bi bi-bell-fill me-1" aria-hidden="true"></i> Notifications activées';
            notifyBtn.classList.replace('btn-outline-secondary', 'btn-success');
          }
        }
      });
    }
  };

  // Initialisation du bouton de notification s'il est présent sur la page
  document.addEventListener('DOMContentLoaded', function () {
    const notifyBtn = document.getElementById('pwa-notify-btn');
    if (notifyBtn && 'Notification' in window) {
      if (Notification.permission === 'granted') {
        notifyBtn.innerHTML = '<i class="bi bi-bell-fill me-1" aria-hidden="true"></i> Notifications activées';
        notifyBtn.classList.replace('btn-outline-secondary', 'btn-success');
      }
      notifyBtn.addEventListener('click', function () {
        window.CommuneApp.requestNotificationPermission();
      });
    }
  });
})();
