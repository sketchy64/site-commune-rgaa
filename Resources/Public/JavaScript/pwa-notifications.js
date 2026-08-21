/**
 * PWA & Push Notifications Manager - Site Commune RGAA
 * Permet l'installation de l'application sur smartphone et l'abonnement aux alertes
 */

(function () {
  'use strict';

  // Helper pour convertir la clef VAPID Base64 en Uint8Array
  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  // 1. Enregistrement du Service Worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker
        .register('/sw.js', { scope: '/' })
        .then(function (reg) {
          // Service worker enregistré
        })
        .catch(function (err) {
          console.warn('Erreur enregistrement ServiceWorker PWA:', err);
        });
    });
  }

  // 2. Gestion de l'invite d'installation PWA (Android / Chrome / Edge / iOS)
  let deferredPrompt;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;

    const installBtns = document.querySelectorAll('#pwa-install-btn, .pwa-install-trigger');
    installBtns.forEach(function (btn) {
      btn.classList.remove('d-none');
      btn.addEventListener('click', function () {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choiceResult) {
          if (choiceResult.outcome === 'accepted') {
            installBtns.forEach(b => b.classList.add('d-none'));
          }
          deferredPrompt = null;
        });
      });
    });
  });

  // 3. Gestion de l'abonnement WebPush aux actualités
  window.CommuneApp = {
    getVapidPublicKey: function () {
      const meta = document.querySelector('meta[name="pwa-vapid-public-key"]');
      return meta ? meta.getAttribute('content') : 'BEl62iUYgUivxIkv69yViEuiBIj7EBA70z7_D7j5n_sW4K0r6-0f8K9vP5w0H1X4G5A4L2d6n9Y2K7j0_N2O9I8';
    },

    subscribeUserToPush: function (registration) {
      var publicKey = this.getVapidPublicKey();
      var applicationServerKey = urlBase64ToUint8Array(publicKey);

      return registration.pushManager
        .subscribe({
          userVisibleOnly: true,
          applicationServerKey: applicationServerKey
        })
        .then(function (subscription) {
          // Envoie de l'abonnement au serveur TYPO3
          return fetch('/api/pwa/subscribe', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(subscription)
          });
        })
        .then(function (response) {
          if (response.ok) {
            const notifyBtns = document.querySelectorAll('#pwa-notify-btn, .pwa-notify-trigger');
            notifyBtns.forEach(function (btn) {
              btn.innerHTML = '<i class="bi bi-bell-fill me-1" aria-hidden="true"></i> Notifications activées';
              btn.classList.replace('btn-outline-secondary', 'btn-success');
            });
          }
        })
        .catch(function (err) {
          console.warn('Erreur lors de l\'abonnement push:', err);
        });
    },

    requestNotificationPermission: function () {
      if (!('Notification' in window) || !('serviceWorker' in navigator)) {
        alert('Votre navigateur ne supporte pas les notifications push.');
        return;
      }

      var self = this;
      Notification.requestPermission().then(function (permission) {
        if (permission === 'granted') {
          navigator.serviceWorker.ready.then(function (registration) {
            self.subscribeUserToPush(registration);

            registration.showNotification('Mairie - Alertes activées', {
              body: 'Vous recevrez directement sur votre écran les nouvelles actualités de la commune.',
              icon: '/favicon.ico'
            });
          });
        }
      });
    }
  };

  // Initialisation au chargement du DOM
  document.addEventListener('DOMContentLoaded', function () {
    const notifyBtns = document.querySelectorAll('#pwa-notify-btn, .pwa-notify-trigger');
    if (notifyBtns.length > 0 && 'Notification' in window) {
      if (Notification.permission === 'granted' && 'serviceWorker' in navigator) {
        navigator.serviceWorker.ready.then(function (registration) {
          registration.pushManager.getSubscription().then(function (subscription) {
            if (subscription) {
              notifyBtns.forEach(function (btn) {
                btn.innerHTML = '<i class="bi bi-bell-fill me-1" aria-hidden="true"></i> Notifications activées';
                btn.classList.replace('btn-outline-secondary', 'btn-success');
              });
            }
          });
        });
      }

      notifyBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          window.CommuneApp.requestNotificationPermission();
        });
      });
    }
  });
})();
