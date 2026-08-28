/**
 * PWA & Push Notifications Manager - Site Commune RGAA
 * Permet l'installation de l'application sur smartphone, l'en-tête supérieur PWA et la gestion synchronisée (1:1) des alertes
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

  // Helper pour savoir si l'application est installée sur l'appareil
  function isAppInstalled() {
    if (localStorage.getItem('pwa_app_installed') === 'true') {
      return true;
    }
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true || document.referrer.startsWith('android-app://')) {
      localStorage.setItem('pwa_app_installed', 'true');
      return true;
    }
    return false;
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

  // 2. Événement d'installation de l'application
  window.addEventListener('appinstalled', function () {
    localStorage.setItem('pwa_app_installed', 'true');
    if (window.CommuneApp && window.CommuneApp.updatePwaAndNotificationUI) {
      window.CommuneApp.updatePwaAndNotificationUI();
    }
  });

  // 3. Gestion de l'invite d'installation PWA (Android / Chrome / Edge / iOS)
  let deferredPrompt;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;

    if (window.CommuneApp && window.CommuneApp.updatePwaAndNotificationUI) {
      window.CommuneApp.updatePwaAndNotificationUI();
    }
  });

  // 4. Gestion de la fermeture du bandeau supérieur PWA
  function initPwaTopBanner() {
    const banner = document.getElementById('pwa-top-banner');
    const closeBtn = document.getElementById('pwa-banner-close-btn');

    if (banner) {
      if (sessionStorage.getItem('pwa_banner_dismissed') === 'true') {
        banner.classList.add('d-none');
      }

      if (closeBtn) {
        closeBtn.addEventListener('click', function () {
          banner.classList.add('d-none');
          sessionStorage.setItem('pwa_banner_dismissed', 'true');
        });
      }
    }
  }

  // 5. Modale d'explication pour débloquer les notifications dans le navigateur
  function showNotificationHelpModal() {
    let helpModal = document.getElementById('pwa-notification-help-modal');
    if (!helpModal) {
      helpModal = document.createElement('div');
      helpModal.id = 'pwa-notification-help-modal';
      helpModal.className = 'modal fade';
      helpModal.setAttribute('tabindex', '-1');
      helpModal.setAttribute('role', 'dialog');
      helpModal.setAttribute('aria-label', 'Activer les notifications dans votre navigateur');
      helpModal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title d-flex align-items-center gap-2">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                Débloquer les notifications
              </h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body py-4">
              <p>Les notifications sont actuellement <strong>désactivées ou bloquées</strong> dans les paramètres de votre navigateur.</p>
              <div class="card bg-light border-0 p-3 mb-3">
                <h6 class="fw-bold mb-2">Comment les activer sur Chrome / Edge / Firefox ?</h6>
                <ol class="mb-0 ps-3">
                  <li class="mb-2">Cliquez sur l'icône <strong>Cadenas 🔒 / Réglages</strong> située tout en haut à gauche dans la barre d'adresse de votre navigateur.</li>
                  <li class="mb-2">Basculez l'interrupteur <strong>Notifications</strong> sur <strong>Autorisé</strong>.</li>
                  <li>Revenez sur cette page : l'activation sera immédiatement prise en compte !</li>
                </ol>
              </div>
            </div>
            <div class="modal-footer border-0">
              <button type="button" class="btn btn-primary" data-bs-dismiss="modal">J'ai compris</button>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(helpModal);
    }

    if (window.bootstrap && window.bootstrap.Modal) {
      const modalObj = new window.bootstrap.Modal(helpModal);
      modalObj.show();
    } else {
      alert('Les notifications sont bloquées dans votre navigateur.\n\nCliquez sur l\'icône Cadenas 🔒 dans la barre d\'adresse en haut à gauche pour autoriser les notifications sur ce site.');
    }
  }

  // 6. Gestion de l'abonnement & désabonnement WebPush aux actualités
  window.CommuneApp = {
    getVapidPublicKey: function () {
      const meta = document.querySelector('meta[name="pwa-vapid-public-key"]');
      const val = meta ? (meta.getAttribute('content') || '').trim() : '';
      if (val && val.length > 20) {
        return val;
      }
      return 'BLQcfzIu2XgG6fT4vVsRY6BYy1LgVkyKH8XYYJajIUts74MKtdOlZOZt2ZCs62LmUIUnaunEZPevfxIxIHzn_iY';
    },

    /**
     * Met à jour TOUS les boutons de notification du site en fonction du statut effectif de la permission
     */
    updateButtonUI: function (isSubscribed) {
      const notifyBtns = document.querySelectorAll('#pwa-notify-btn, #pwa-notify-btn-banner, .pwa-notify-trigger');
      notifyBtns.forEach(function (btn) {
        if (isSubscribed) {
          btn.setAttribute('data-subscribed', 'true');
          btn.classList.remove('btn-outline-secondary', 'btn-outline-light', 'btn-warning', 'text-dark');
          btn.classList.add('btn-success', 'text-white', 'fw-bold');
          btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="#ffffff" stroke="#ffffff" stroke-width="1.5" class="me-1 flex-shrink-0" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> <span class="text-white fw-bold">Désactiver les notifications</span>';
          btn.setAttribute('title', 'Cliquer pour désactiver les notifications push');
        } else {
          btn.setAttribute('data-subscribed', 'false');
          btn.classList.remove('btn-success', 'text-white');
          if (btn.id === 'pwa-notify-btn-banner') {
            btn.classList.add('btn-warning', 'text-dark', 'fw-bold');
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#212529" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="stroke: #212529 !important; fill: none !important; color: #212529 !important;" class="me-1 flex-shrink-0" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> <span class="text-dark fw-bold">Activer les notifications</span>';
          } else if (btn.id === 'pwa-notify-btn') {
            btn.classList.add('btn-outline-secondary', 'fw-bold');
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1 flex-shrink-0" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> <span>Activer les notifications</span>';
          } else {
            btn.classList.add('btn-outline-light', 'fw-bold');
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1 flex-shrink-0" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> <span>Activer les notifications</span>';
          }
          btn.setAttribute('title', 'Cliquer pour activer les notifications push');
        }
      });
    },

    subscribeUserToPush: function (registration) {
      var self = this;
      var publicKey = self.getVapidPublicKey();
      var applicationServerKey = urlBase64ToUint8Array(publicKey);

      localStorage.removeItem('pwa_notifications_disabled_by_user');

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
      localStorage.setItem('pwa_notifications_disabled_by_user', 'true');

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
      const perm = Notification.permission;

      // Si l'utilisateur a bloqué les notifications dans le navigateur
      if (perm === 'denied') {
        showNotificationHelpModal();
        return;
      }

      navigator.serviceWorker.ready.then(function (registration) {
        registration.pushManager.getSubscription().then(function (subscription) {
          if (subscription && perm === 'granted') {
            // Déjà abonné -> Désactiver (Résiliation + Suppression BDD)
            self.unsubscribeUserFromPush(registration);
          } else {
            // Non abonné -> Demander l'autorisation + S'abonner
            localStorage.removeItem('pwa_notifications_disabled_by_user');
            Notification.requestPermission().then(function (permission) {
              if (permission === 'granted') {
                self.subscribeUserToPush(registration);
                registration.showNotification('Mairie - Alertes activées', {
                  body: 'Vous recevrez directement sur votre écran les nouvelles actualités de la commune.',
                  icon: '/_assets/site_commune_rgaa/Icons/pwa-192x192.png'
                });
              } else if (permission === 'denied') {
                showNotificationHelpModal();
                self.updateButtonUI(false);
              }
            });
          }
        });
      });
    },

    /**
     * Calcule l'état d'installation PWA et l'état des notifications pour appliquer les règles d'affichage
     */
    updatePwaAndNotificationUI: function () {
      var self = this;
      const banner = document.getElementById('pwa-top-banner');
      const installBtn = document.getElementById('pwa-install-btn-banner');
      const notifyBtn = document.getElementById('pwa-notify-btn-banner');

      // Détection si l'utilisateur navigue actuellement DEPUIS l'application (PWA Standalone)
      const isRunningInApp = window.matchMedia('(display-mode: standalone)').matches ||
                            window.navigator.standalone === true ||
                            document.referrer.startsWith('android-app://');

      const isInstalled = isRunningInApp || isAppInstalled();
      const perm = ('Notification' in window) ? Notification.permission : 'default';

      if (!('serviceWorker' in navigator) || !('Notification' in window)) {
        self.updateButtonUI(false);
        return;
      }

      navigator.serviceWorker.ready.then(function (registration) {
        registration.pushManager.getSubscription().then(function (subscription) {
          // AUTO-SOUSCRIPTION : Si les notifications sont autorisées dans le cadenas,
          // mais qu'aucun abonnement n'existe ET que l'utilisateur N'A PAS explicitement désactivé sur le site
          if (perm === 'granted' && !subscription) {
            if (localStorage.getItem('pwa_notifications_disabled_by_user') === 'true') {
              // L'utilisateur a cliqué sur "Désactiver les notifications" -> Conserver l'état désactivé
              self.updateButtonUI(false);
            } else {
              // L'utilisateur n'a jamais désactivé sur le site -> Souscrire automatiquement
              self.subscribeUserToPush(registration);
              self.updateButtonUI(true);
            }
            return;
          }

          const isNotificationActive = (perm === 'granted') && (subscription !== null);

          // CAS 1 : Utilisateur navigue DANS L'APPLICATION PWA (Standalone)
          if (isRunningInApp) {
            if (isNotificationActive) {
              // Quand il lance l'app et que les notifications sont actives -> Ne pas afficher le bandeau
              if (banner) banner.classList.add('d-none');
            } else {
              // Si les notifications ne sont pas encore activées -> Afficher le bandeau avec uniquement le bouton d'activation
              if (banner) banner.classList.remove('d-none');
              if (installBtn) {
                installBtn.classList.remove('d-inline-flex');
                installBtn.classList.add('d-none');
              }
              if (notifyBtn) {
                notifyBtn.classList.remove('d-none');
                notifyBtn.classList.add('d-inline-flex');
              }
              self.updateButtonUI(false);
            }
            return;
          }

          // CAS 2 : Utilisateur navigue DANS UN NAVIGATEUR CLASSIQUE (Onglet web)
          if (banner && sessionStorage.getItem('pwa_banner_dismissed') !== 'true') {
            banner.classList.remove('d-none');
          }

          // Gestion du bouton Installer / Lancer l'application
          if (installBtn) {
            if (isInstalled) {
              // L'application est installée sur l'appareil -> Proposer "Lancer l'application"
              installBtn.classList.remove('d-none');
              installBtn.classList.add('d-inline-flex');
              installBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#212529" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="stroke: #212529 !important; fill: none !important; color: #212529 !important;" class="me-1 flex-shrink-0" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg> <span class="text-dark fw-bold">Lancer l\'application</span>';
              installBtn.setAttribute('title', 'Ouvrir l\'application installée sur votre appareil');
              installBtn.onclick = function (e) {
                e.preventDefault();
                window.open('/', '_blank');
              };
            } else if (deferredPrompt) {
              // L'application n'est pas installée mais installable -> Proposer "Installer l'App"
              installBtn.classList.remove('d-none');
              installBtn.classList.add('d-inline-flex');
              installBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#212529" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="stroke: #212529 !important; fill: none !important; color: #212529 !important;" class="me-1 flex-shrink-0" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg> <span class="text-dark fw-bold">Installer l\'App</span>';
              installBtn.setAttribute('title', 'Installer l\'application sur votre écran d\'accueil');
              installBtn.onclick = function (e) {
                e.preventDefault();
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function (choiceResult) {
                  if (choiceResult.outcome === 'accepted') {
                    localStorage.setItem('pwa_app_installed', 'true');
                  }
                  deferredPrompt = null;
                  self.updatePwaAndNotificationUI();
                });
              };
            } else {
              installBtn.classList.remove('d-inline-flex');
              installBtn.classList.add('d-none');
            }
          }

          // Gestion du bouton de notification
          if (notifyBtn) {
            notifyBtn.classList.remove('d-none');
            notifyBtn.classList.add('d-inline-flex');
          }

          self.updateButtonUI(isNotificationActive);
        });
      });
    },

    checkSubscriptionStatus: function () {
      this.updatePwaAndNotificationUI();
    }
  };

  // Initialisation au chargement du DOM
  document.addEventListener('DOMContentLoaded', function () {
    initPwaTopBanner();

    const notifyBtns = document.querySelectorAll('#pwa-notify-btn, #pwa-notify-btn-banner, .pwa-notify-trigger');
    if ('Notification' in window && 'serviceWorker' in navigator) {
      window.CommuneApp.updatePwaAndNotificationUI();

      // Ré-interroger le statut lors du retour de focus sur l'onglet (si modifié dans le cadenas)
      window.addEventListener('focus', function () {
        window.CommuneApp.updatePwaAndNotificationUI();
      });

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
