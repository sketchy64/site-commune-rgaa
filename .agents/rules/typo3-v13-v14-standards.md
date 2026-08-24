# Directives de Développement TYPO3 v13/v14 & Normes Code

Les consignes suivantes régissent la qualité et la compatibilité du code PHP, TypoScript et Fluid dans `site_commune_rgaa`.

---

## 1. Standards PHP & Extbase
* En-tête systématique : `declare(strict_types=1);` dans tous les fichiers PHP.
* Respect de la norme **PSR-12** et des recommandations **TYPO3 Coding Standards**.
* Injections de dépendances : Utilisez l'injection par constructeur et l'auto-configuration via `Services.yaml` (`#[Autoconfigure]`).
* Pour la gestion des événements : Utilisez exclusivement la spécification **PSR-14** et les attributs `#[AsEventListener]`. N'utilisez pas de hooks obsolètes.

## 2. Compatibilité TYPO3 v12 / v13 / v14
* Évitez l'utilisation d'API dépréciées (`$GLOBALS['TSFE']`, anciennes méthodes de `ExtensionManagementUtility`).
* Utilisez la déclaration moderne des Site Sets dans `Configuration/Sets/` pour TYPO3 v13+.

## 3. TypoScript & Configuration
* Tous les fichiers TypoScript doivent résider dans `Configuration/TypoScript/`.
* Utilisez la syntaxe moderne sans crochets dépréciés ni anciennes conventions TS.
* Structurez les constantes dans `constants.typoscript` et la configuration dans `setup.typoscript`.

## 4. Templates & ViewHelpers Fluid
* Organisez les gabarits strictement dans `Resources/Private/Templates/`, `Resources/Private/Partials/` et `Resources/Private/Layouts/`.
* Évitez d'écrire des scripts inline dans les gabarits Fluid ; passez par des fichiers JavaScript externes modulaires.
* Assurez-vous que tout ViewHelper personnalisé gère correctement l'échappement des données (sécurité XSS).
