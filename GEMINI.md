# EXT:site_commune_rgaa - Directives & Architecture du Projet

Bienvenue dans le projet **EXT:site_commune_rgaa** (`commune/site-commune-rgaa`), une extension TYPO3 spécialisée pour le déploiement rapide et accessible de sites web de collectivités territoriales et communes.

---

## 🎯 Objectifs Stratégiques du Projet

1. **Accessibilité RGAA 4.1 / WCAG 2.1 AA (Priorité n°1)** :
   * Chaque composant, gabarit Fluid, formulaire et rendu HTML généré DOIT être rigoureusement conforme aux normes RGAA 4.1.
   * Aucune concession ne sera faite sur la sémantique HTML5, la navigation clavier, les alternatives textuelles, la hiérarchie des titres et les attributs ARIA.

2. **Surcouche propre & Maintien des Dépendances** :
   * Surcharge élégante et isolée des extensions fondatrices : **`bk2k/bootstrap-package`** et **`georgringer/news`**.
   * Préservation de l'évolutivité et des mises à jour majeures/mineures de ces extensions sans casser le code sur mesure.

3. **Système Multi-Thèmes & Surcouche Graphique Sur-Mesure** :
   * Prise en charge de thèmes pré-configurés pour collectivités (prêts à l'emploi).
   * Flexibilité d'une surcouche CSS/SCSS/Fluid pour des demandes d'intégration sur-mesure.

4. **PWA & Notifications Push** :
   * Transformation du site en Application Web Progressive (PWA installable sur smartphone).
   * Web Push Notifications lors de la publication d'actualités via `georgringer/news`.

5. **Évolution Modulaire (Phases Ultérieures)** :
   * **Annuaire** : Commerces et associations de la commune.
   * **Co-marquage** : Démarches administratives avec `service-public.gouv.fr`.
   * **Actes & Délibérations** : Publication et archivage filtrable de documents PDF (Conseils municipaux, actes réglementaires).

---

## 📐 Règles et Principes Directeurs de Développement

* **PHP & Extbase** : PHP 8.1+, TYPO3 v12.4 / v13.4 / v14.x compatibility, respect strict de PSR-12, typage strict (`declare(strict_types=1);`).
* **TypoScript & TSconfig** : Syntaxe moderne, organisation modulaire dans `Configuration/TypoScript/` et `Configuration/Sets/` (TYPO3 v13 Site Sets).
* **Templates Fluid** : Isolation dans `Resources/Private/Templates/`, `Partials/` et `Layouts/`. Utilisation préférentielle des ViewHelpers sémantiques et accessibles.
* **Sécurité & Qualité** : Analyse avec PHPStan et TYPO3 Coding Standards.

---

## 🤖 Agents Spécialisés du Projet

Le projet s'appuie sur 4 agents IA spécialisés situés dans `.agents/agents/` :
1. **`rgaa-auditor`** : Auditeur strict RGAA 4.1 & WCAG 2.1.
2. **`typo3-architect`** : Architecte TYPO3 v13/v14 & Extbase Core.
3. **`fluid-integrator`** : Intégrateur Fluid HTML5 & Surcouche de Thèmes.
4. **`pwa-push-expert`** : Spécialiste PWA, Service Worker & Web Push Notifications.