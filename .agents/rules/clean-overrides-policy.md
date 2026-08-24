# Directives de Surcharge Propre (`bootstrap_package` & `georgringer/news`)

Cette règle garantit que les surcharges appliquées aux extensions tierces restent durables et faciles à maintenir lors des mises à jour majeures de `bk2k/bootstrap-package` ou `georgringer/news`.

---

## 1. Principe d'Isolation Strict
* **Ne modifiez jamais** directement le dossier `vendor/` ou les dossiers des extensions d'origine.
* Toutes les surcharges doivent résider exclusivement au sein de `EXT:site_commune_rgaa`.

## 2. Surcharge des Templates Fluid
* Configurez les chemins de surcharges via TypoScript `templateRootPaths`, `partialRootPaths` et `layoutRootPaths` avec des index élevés (ex: `100 = EXT:site_commune_rgaa/Resources/Private/Templates/Overrides/BootstrapPackage/`).
* N'overridez que les Partials/Templates **strictement nécessaires** pour l'accessibilité RGAA ou le design. Laissez le reste hérité de l'extension d'origine.
* Conservez les variables de contexte d'origine pour éviter tout dysfonctionnement lors des mises à jour.

## 3. Surcharge des Styles & Asset Collector
* Utilisez l'AssetCollector TYPO3 ou l'injection de fichiers SCSS/CSS pour surcharger les variables de `bootstrap_package` (ex: `$primary`, `$font-family-base`, `$enable-rgaa-focus`).
* Évitez l'utilisation abusive de `!important` dans les feuilles de style CSS.

## 4. Documentation des Surcharges
* Tout Partial ou Template surchargé doit comporter en en-tête un commentaire explicatif indiquant :
  * L'extension d'origine et la version cible.
  * La raison de la surcharge (ex: *Correction accessibilité RGAA - ajout du label pour le bouton de recherche*).
