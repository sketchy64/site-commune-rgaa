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
      const val = meta ? (meta.getAttribute('content') || '').trim() : '';
      if (val && val.length > 20) {
        return val;
      }
      return 'BLQcfzIu2XgG6fT4vVsRY6BYy1LgVkyKH8XYYJajIUts74MKtdOlZOZt2ZCs62LmUIUnaunEZPevfxIxIHzn_iY';
    },

    subscribeUserToPush: function (registration) {
      var self = this;
      var publicKey = self.getVapidPublicKey();
      var applicationServerKey = urlBase64ToUint8Array(publicKey);

      return registration.pushManager
        .subscribe({
          userVisibleOnly: true,
          applicationServerKey: applicationServerKey
        })
        .then(function (subscription) {
          var subJson = subscription.toJSON ? subscription.toJSON() : {};
          var p256dhKey = (subJson.keys && subJson.keys.p256dh) ? subJson.keys.p256dh : '';
          var authKey = (subJson.keys && subJson.keys.auth) ? subJson.keys.auth : '';

          if (!p256dhKey && subscription.getKey) {
            var rawP256dh = subscription.getKey('p256dh');
            if (rawP256dh) {
              p256dhKey = btoa(String.fromCharCode.apply(null, new Uint8Array(rawP256dh)))
                .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            }
          }
          if (!authKey && subscription.getKey) {
            var rawAuth = subscription.getKey('auth');
            if (rawAuth) {
              authKey = btoa(String.fromCharCode.apply(null, new Uint8Array(rawAuth)))
                .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            }
          }

          var payload = {
            endpoint: subscription.endpoint || subJson.endpoint || '',
            p256dh: p256dhKey,
            auth: authKey,
            keys: {
              p256dh: p256dhKey,
              auth: authKey
            }
          };

          // Envoi de l'abonnement au serveur TYPO3
          return fetch('/api/pwa/subscribe', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
          });
        })
        .then(function (response) {
          return response.json();
        })
        .then(function (data) {
          if (data && data.success) {
            const notifyBtns = document.querySelectorAll('#pwa-notify-btn, .pwa-notify-trigger');
            notifyBtns.forEach(function (btn) {
              btn.innerHTML = '<i class="bi bi-bell-fill me-1" aria-hidden="true"></i> Notifications activées';
              btn.classList.replace('btn-outline-secondary', 'btn-success');
            });
          } else {
            console.warn('Erreur réponse enregistrement souscription push:', data);
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
              icon: '/_assets/site_commune_rgaa/Icons/pwa-192x192.png'
            });
          });
        } else if (permission === 'denied') {
          alert('Les notifications sont bloquées dans votre navigateur. Cliquez sur le cadenassier dans la barre d\'adresse pour autoriser les notifications sur ce site.');
        }
      });
    }
  };

  // Initialisation au chargement du DOM
  document.addEventListener('DOMContentLoaded', function () {
    const notifyBtns = document.querySelectorAll('#pwa-notify-btn, .pwa-notify-trigger');
    if ('Notification' in window && 'serviceWorker' in navigator) {
      if (Notification.permission === 'granted') {
        navigator.serviceWorker.ready.then(function (registration) {
          window.CommuneApp.subscribeUserToPush(registration);
        });
      }

      if (notifyBtns.length > 0) {
        notifyBtns.forEach(function (btn) {
          btn.addEventListener('click', function () {
            window.CommuneApp.requestNotificationPermission();
          });
        });
      }
    }
  });
})();
