# Architecture Projet — Extension TYPO3 Collectivités & Communes

## 1. Cadre Technique & Environnement
- **Cible** : Extension / Sitepackage TYPO3 v12/v13 basé sur `bootstrap_package` et `news` (ext:news / georgringer).
- **Règle d'or** : Ne jamais altérer les fichiers des extensions tierces (`bootstrap_package`, `news`). Tout passe par des surcharges (Fluid fallbacks, TypoScript constants/setup, PSR-14 Events, DataProcessors).

## 2. PWA & Notifications (Temps 1)
- **Manifest & Service Worker** : Génération dynamique du `manifest.webmanifest` selon les couleurs et le logo du thème sélectionné.
- **Web Push API** : Déclenchement automatique des notifications push lors de la publication / modification d'une actualité (`tx_news_domain_model_news`) via un écouteur d'événements PSR-14 TYPO3 (`AfterNewsCreatedEvent` / DataHandler hook).

## 3. Modules Métier Communaux (Temps 2)
- Modules extensibles (Extbase / TCA) : Annuaire commerces & assos, Trombinoscope des élus, Gestionnaire d'actes réglementaires & bulletins avec filtres (facettes), Co-marquage `service-public.fr` (flux XML / Open Data).

## 4. Conformité & Qualité
- Accessibilité obligatoire : **RGAA 4.1.2 niveau AA**.
- Thémage : Support du multithème via des constantes TypoScript et variables CSS (`:root`).

## 5. Exigences Absolues RGAA (Critère Bloquant)
- **Tout template HTML ou composant produit DOIT être 100 % conforme au RGAA.**
- **Zéro composant inaccessible** :
  - Aucun élément cliquable sans intitulé explicite (boutons d'icône seuls interdits sans `aria-label` ou texte masqué `.visually-hidden`).
  - Aucun changement de contexte non sollicité (les filtres de recherche ou sélecteurs de thèmes ne doivent pas recharger la page ou déplacer le focus sans avertir).
  - Présence obligatoire des liens d'évitement (`#content`, `#navigation`, `#search`) fonctionnels au clavier.
  - Documents municipaux téléchargeables : mention obligatoire du titre, de l'extension et du poids (ex. `[PDF, 2,4 Mo]`).
  - Formulaires : association stricte `<label for="...">` et `<input id="...">`, pas de simple placeholder comme intitulé.