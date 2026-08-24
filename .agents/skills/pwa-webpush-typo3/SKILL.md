---
name: pwa-webpush-typo3
description: >-
  Guide d'intégration technique d'une Progressive Web App (PWA) et des Web Push Notifications liées à la publication d'actualités EXT:news dans TYPO3.
---

# Skill : Intégration PWA & Notifications Push sur EXT:news

Ce skill détaille les composants et la séquence technique nécessaires pour doter l'extension `site_commune_rgaa` d'une capacité PWA mobile (installation smartphone) et de notifications Push lors de la parution d'actualités d'une commune.

---

## 🛠️ Architecture Technologique PWA & Web Push

### 1. Composants PWA (Frontend)
* **Manifest (`manifest.webmanifest`)** : Déclaré dans l'en-tête HTML via `<link rel="manifest" href="/manifest.webmanifest">`.
* **Service Worker (`sw.js`)** : Fichier JavaScript gérant la mise en cache des actifs (off-line fallback) et la réception de l'événement `push`.

### 2. Dépendance PHP (Backend Web Push)
* Fichier `composer.json` :
  ```json
  "require": {
      "minishlink/web-push": "^8.0"
  }
  ```

---

## 📋 Procédure d'Implémentation Étape par Étape

### Étape 1 : Le Fichier Manifest (`Resources/Public/JavaScript/manifest.webmanifest`)
```json
{
  "name": "Commune de ...",
  "short_name": "Ma Commune",
  "start_url": "/",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "#0055a5",
  "icons": [
    {
      "src": "/typo3conf/ext/site_commune_rgaa/Resources/Public/Icons/pwa-192x192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "/typo3conf/ext/site_commune_rgaa/Resources/Public/Icons/pwa-512x512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ]
}
```

### Étape 2 : Enregistrement du Service Worker & Bouton d'Abonnement Accessible
1. Créer le composant d'inscription aux notifications (Accessible RGAA) dans un Partial `Resources/Private/Partials/Pwa/PushSubscribe.html` :
   ```xml
   <button type="button" id="push-subscribe-btn" class="btn btn-primary" aria-live="polite">
       Activer les notifications d'actualités
   </button>
   ```
2. Script JavaScript frontend (`Resources/Public/JavaScript/pwa-push.js`) :
   * Demander l'autorisation via `Notification.requestPermission()`.
   * Récupérer la souscription `pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidPublicKey })`.
   * Transmettre les clés de souscription à un contrôleur Extbase de TYPO3 (`PushSubscriptionController`).

### Étape 3 : Traitement Backend de la Publication d'Actualité (`georgringer/news`)
1. Déclarer un Événement PSR-14 dans `Configuration/Services.yaml` :
   ```yaml
   Commune\SiteCommuneRgaa\EventListener\NewsPublishedNotificationListener:
     tags:
       - name: event.listener
         identifier: 'site-commune-rgaa/news-published-push'
         event: GeorgRinger\News\Event\NewsPostPublicationEvent
   ```
2. Dans le Listener PHP (`Classes/EventListener/NewsPublishedNotificationListener.php`) :
   * Extraire le titre et l'accroche de la nouvelle actualité (`$event->getNews()`).
   * Récupérer la liste des abonnements actifs depuis la base de données (`PushSubscriptionRepository`).
   * Déclencher l'envoi de la notification avec `Minishlink\WebPush\WebPush`.

### Étape 4 : Gestion de l'Événement Push dans le Service Worker (`sw.js`)
```javascript
self.addEventListener('push', function(event) {
    if (event.data) {
        const data = event.data.json();
        const options = {
            body: data.body,
            icon: data.icon || '/typo3conf/ext/site_commune_rgaa/Resources/Public/Icons/pwa-192x192.png',
            data: { url: data.url }
        };
        event.waitUntil(self.registration.showNotification(data.title, options));
    }
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    if (event.notification.data && event.notification.data.url) {
        event.waitUntil(clients.openWindow(event.notification.data.url));
    }
});
```

---

## 🔍 Validation & Sécurité
- [ ] VAPID keys correctement configurées (Clé publique / Clé privée stockées en sécurité).
- [ ] Inscription aux notifications conforme RGPD (Consentement préalable clair, possibilité de désabonnement).
- [ ] Bouton d'activation totalement utilisable au clavier et lisible par synthèse vocale.
