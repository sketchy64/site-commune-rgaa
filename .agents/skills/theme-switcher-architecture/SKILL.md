---
name: theme-switcher-architecture
description: >-
  Guide d'architecture pour le système multi-thèmes et la surcouche graphique sur-mesure des sites de communes dans TYPO3.
---

# Skill : Architecture Multi-Thèmes et Surcouche Sur-Mesure

Ce skill fournit les directives d'implémentation pour proposer plusieurs thèmes pré-configurés aux communes (ex: thème classique, moderne, rural, littoral) tout en permettant des personnalisations sur-mesure.

---

## 🏗️ Structure de l'Architecture Multi-Thèmes

```text
EXT:site_commune_rgaa/
├── Configuration/
│   ├── Sets/                             # TYPO3 v13 Site Sets
│   │   ├── SiteCommuneRgaa/             # Set principal
│   │   ├── ThemeClassic/                # Option Thème Classique
│   │   ├── ThemeModern/                 # Option Thème Moderne
│   │   └── ThemeNature/                 # Option Thème Nature
│   └── TypoScript/
│       ├── Themes/
│       │   ├── Classic/
│       │   ├── Modern/
│       │   └── Nature/
│       └── Custom/                       # Injection du sur-mesure
└── Resources/
    ├── Private/
    │   ├── Scss/
    │   │   ├── Abstract/                # Variables SCSS & Mixins
    │   │   ├── Themes/                  # Feuilles SCSS des thèmes
    │   │   │   ├── _classic.scss
    │   │   │   ├── _modern.scss
    │   │   │   └── _nature.scss
    │   │   └── Custom/                  # Styles sur-mesure
    └── Public/
        └── Css/                         # Fichiers CSS compilés
            ├── theme-classic.css
            ├── theme-modern.css
            ├── theme-nature.css
            └── custom-override.css
```

---

## ⚙️ Mécanisme de Sélection du Thème

### 1. Sélection via le Site Configuration (TYPO3 Site Handling / Constants)
Dans les constantes TypoScript du site de la commune :
```typoscript
# Choix du thème actif : classic, modern, nature, custom
plugin.tx_sitecommunergaa.settings.theme = classic

# Couleurs institutionnelles (Variables CSS dynamiques)
plugin.tx_sitecommunergaa.settings.color.primary = #0055A5
plugin.tx_sitecommunergaa.settings.color.secondary = #E1000F
```

### 2. Injection des Fichiers CSS & Variables CSS
Dans `Resources/Private/Templates/Layouts/Default.html` ou via `AssetCollector` :
```xml
<!-- Injection des variables de couleurs dynamiques -->
<style>
    :root {
        --commune-primary: {settings.color.primary};
        --commune-secondary: {settings.color.secondary};
    }
</style>

<!-- Chargement du thème sélectionné -->
<f:asset.css identifier="commune-theme" href="EXT:site_commune_rgaa/Resources/Public/Css/theme-{settings.theme}.css" />

<!-- Chargement de la surcouche sur-mesure (si elle existe pour la commune) -->
<f:if condition="{settings.theme} == 'custom'">
    <f:asset.css identifier="commune-custom-theme" href="EXT:site_commune_rgaa/Resources/Public/Css/custom-override.css" />
</f:if>
```

---

## 🎨 Principes pour les Projets Sur-Mesure
1. **Règle d'Or** : Les ajustements graphiques sur-mesure ne doivent jamais altérer l'accessibilité RGAA.
2. Pour chaque demande spécifique de commune (ex: un logo ou une bannière spécifique, un footer custom) :
   * Créer une option de constante ou utiliser un Partial de surcouche situé dans `Resources/Private/Partials/Custom/`.
   * En cas de besoin de style spécifique, compiler ou injecter un fichier `custom-override.css`.
