/**
 * PWA & Push Notifications Manager - Site Commune RGAA
 * Permet l'installation de l'application sur smartphone et la gestion à 2 états (Activer / Désactiver) des alertes
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

  // 3. Gestion de l'abonnement & désabonnement WebPush aux actualités (2 états)
  window.CommuneApp = {
    getVapidPublicKey: function () {
      const meta = document.querySelector('meta[name="pwa-vapid-public-key"]');
      const val = meta ? (meta.getAttribute('content') || '').trim() : '';
      if (val && val.length > 20) {
        return val;
      }
      return 'BLQcfzIu2XgG6fT4vVsRY6BYy1LgVkyKH8XYYJajIUts74MKtdOlZOZt2ZCs62LmUIUnaunEZPevfxIxIHzn_iY';
    },

    updateButtonUI: function (isSubscribed) {
      const notifyBtns = document.querySelectorAll('#pwa-notify-btn, .pwa-notify-trigger');
      notifyBtns.forEach(function (btn) {
        if (isSubscribed) {
          btn.setAttribute('data-subscribed', 'true');
          btn.classList.remove('btn-outline-secondary', 'btn-outline-light');
          btn.classList.add('btn-success');
          btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" class="me-1" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> <span>Désactiver les notifications</span>';
          btn.setAttribute('title', 'Cliquer pour désactiver les notifications push');
        } else {
          btn.setAttribute('data-subscribed', 'false');
          btn.classList.remove('btn-success');
          if (btn.id === 'pwa-notify-btn') {
            btn.classList.add('btn-outline-secondary');
          } else {
            btn.classList.add('btn-outline-light');
          }
          btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> <span>Activer les notifications</span>';
          btn.setAttribute('title', 'Cliquer pour activer les notifications push');
        }
      });
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
            self.updateButtonUI(true);
          } else {
            console.warn('Erreur réponse enregistrement souscription push:', data);
          }
        })
        .catch(function (err) {
          console.warn('Erreur lors de l\'abonnement push:', err);
        });
    },

    unsubscribeUserFromPush: function (registration) {
      var self = this;
      return registration.pushManager.getSubscription().then(function (subscription) {
        if (subscription) {
          const endpoint = subscription.endpoint;
          return subscription.unsubscribe().then(function (successful) {
            if (successful) {
              return fetch('/api/pwa/subscribe', {
                method: 'DELETE',
                headers: {
                  'Content-Type': 'application/json'
                },
                body: JSON.stringify({ endpoint: endpoint })
              });
            }
          });
        }
      }).then(function () {
        self.updateButtonUI(false);
      }).catch(function (err) {
        console.warn('Erreur lors du désabonnement push:', err);
      });
    },

    toggleNotificationState: function () {
      if (!('Notification' in window) || !('serviceWorker' in navigator)) {
        alert('Votre navigateur ne supporte pas les notifications push.');
        return;
      }

      var self = this;
      navigator.serviceWorker.ready.then(function (registration) {
        registration.pushManager.getSubscription().then(function (subscription) {
          if (subscription) {
            // Déjà abonné -> Désactiver (Unsubscribe + suppression BDD TYPO3)
            self.unsubscribeUserFromPush(registration);
          } else {
            // Non abonné -> Demande / vérification directe de la permission auprès du navigateur
            Notification.requestPermission().then(function (permission) {
              if (permission === 'granted') {
                self.subscribeUserToPush(registration);
                registration.showNotification('Mairie - Alertes activées', {
                  body: 'Vous recevrez directement sur votre écran les nouvelles actualités de la commune.',
                  icon: '/_assets/site_commune_rgaa/Icons/pwa-192x192.png'
                });
              } else if (permission === 'denied') {
                alert('Les notifications sont bloquées dans votre navigateur. Cliquez sur le cadenas dans la barre d\'adresse pour autoriser les notifications sur ce site.');
              }
            });
          }
        });
      });
    },

    checkSubscriptionStatus: function () {
      var self = this;
      if ('Notification' in window && 'serviceWorker' in navigator && Notification.permission === 'granted') {
        navigator.serviceWorker.ready.then(function (registration) {
          registration.pushManager.getSubscription().then(function (subscription) {
            if (subscription) {
              self.updateButtonUI(true);
            } else {
              self.updateButtonUI(false);
            }
          });
        });
      } else {
        self.updateButtonUI(false);
      }
    }
  };

  // Initialisation au chargement du DOM
  document.addEventListener('DOMContentLoaded', function () {
    const notifyBtns = document.querySelectorAll('#pwa-notify-btn, .pwa-notify-trigger');
    if ('Notification' in window && 'serviceWorker' in navigator) {
      window.CommuneApp.checkSubscriptionStatus();

      if (notifyBtns.length > 0) {
        notifyBtns.forEach(function (btn) {
          btn.addEventListener('click', function () {
            window.CommuneApp.toggleNotificationState();
          });
        });
      }
    }
  });
})();
