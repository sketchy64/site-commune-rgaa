# Extension TYPO3 : Site Commune RGAA

[![TYPO3](https://img.shields.io/badge/TYPO3-v12%20%7C%20v13-orange.svg)](https://typo3.org/)
[![Bootstrap Package](https://img.shields.io/badge/Bootstrap%20Package-%5E14.0%20%7C%20%5E15.0-blue.svg)](https://github.com/benjaminkott/bootstrap_package)
[![RGAA](https://img.shields.io/badge/RGAA-4.1.2%20(Niveau%20AA)-green.svg)](https://accessibilite.numerique.gouv.fr/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)

**`site_commune_rgaa`** est une extension TYPO3 (sitepackage) servant de surcouche optimisée et accessible à `bk2k/bootstrap-package`. 
Elle a été spécialement conçue pour les **collectivités territoriales, mairies et intercommunalités** françaises soumises aux obligations légales de l'article 47 de la loi n° 2005-102 du 11 février 2005 pour l'égalité des droits et des chances.

---

## 🏛️ Points forts & Spécificités Collectivités

- **En-tête républicain officiel** : Identité visuelle institutionnelle, bloc Marianne / Armoiries communales, devise, numéros d'urgence et coordonnées complètes de la Mairie.
- **Bandeau d'alerte citoyenne** : Diffusion d'informations urgentes (alerte météo, arrêté préfectoral, travaux majeurs) avec notification vocale immédiate (`role="alert"`, `aria-live="assertive"`).
- **Gabarits de page adaptés** :
  - **Accueil citoyen** : Bannière à la une, 4 tuiles de démarches en ligne, actualités et agenda.
  - **Page standard** : Navigation latérale de rubrique avec sous-menus et zone de contact rapide.
  - **Pleine largeur** : Pour formulaires complexes et tableaux de délibérations.
- **Pied de page légal complet** : Horaires de la mairie, numéros d'urgence (15, 17, 18, 112, 114), mentions légales, données personnelles (RGPD) et **mention obligatoire du niveau de conformité RGAA**.

---

## ♿ Conformité RGAA 4.1.2 & Accessibilité Numérique (WCAG 2.1 AA)

1. **Liens d'évitement (Skip links)** : Accès rapide au contenu principal (`#main-content`), à la navigation (`#main-navigation`), au moteur de recherche (`#main-search`) et au pied de page.
2. **Barre d'outils d'accessibilité ergonomique** :
   - Agrandissement / réduction de texte (`A-`, `A`, `A+`) avec persistance `localStorage`.
   - Mode **Contraste Élevé** (fond noir, textes jaunes/blancs, liens cyan).
   - Mode **Police Dyslexie** (espacement et typographie adaptés).
3. **Navigation Clavier & Focus** :
   - Indicateur de focus renforcé (contour contrasté 3px avec décalage).
   - Gestion des touches `Escape`, `Tab` et synchronisation des attributs `aria-expanded`.
4. **Surcharges Bootstrap Package** :
   - **Accordéons** : Transformation en boutons natifs `<button>` avec `aria-controls` et `aria-expanded`.
   - **Carrousels** : Bouton obligatoire de **Pause / Lecture** (Critère 13.8) et arrêt automatique au focus clavier.
   - **Images & Médias** : Gestion des images décoratives (`alt=""`) et légendes structurées (`<figure>` / `<figcaption>`).
   - **Liens contextuels** : Élimination des "En savoir plus" orphelins grâce au ViewHelper d'accessibilité.

---

## 📂 Structure du Projet

```
site_commune_rgaa/
├── Classes/
│   └── ViewHelpers/
│       └── A11y/
│           ├── AriaLabelViewHelper.php
│           ├── DecorativeImageViewHelper.php
│           └── ExternalLinkAriaViewHelper.php
├── Configuration/
│   ├── Sets/SiteCommuneRgaa/       # Site Sets TYPO3 v13
│   ├── TypoScript/                # Setup & Constants (v12 & v13)
│   ├── TSconfig/                  # Backend Layouts & TCEFORM
│   ├── TCA/Overrides/
│   └── Services.yaml
├── Documentation/
│   ├── RGAA_CHECKLIST.md          # Checklist d'audit pour rédacteurs
│   ├── INSTALLATION.md            # Guide d'installation complet
│   └── ARCHITECTURE.md
├── Resources/
│   ├── Private/
│   │   ├── Layouts/Page/
│   │   ├── Partials/Page/
│   │   ├── Templates/Page/
│   │   ├── OverriddenTemplates/BootstrapPackage/ContentElements/
│   │   └── Language/
│   └── Public/
│       ├── Css/
│       ├── JavaScript/
│       └── Icons/
├── composer.json
├── ext_emconf.php
└── README.md
```

---

## 🚀 Installation & Déploiement

### 1. Via Composer

```bash
composer require commune/site-commune-rgaa
```

### 2. Activation dans TYPO3

- **TYPO3 v13** : Activer le Site Set `commune/site-commune-rgaa` dans la configuration du site (**Site Management > Sites**).
- **TYPO3 v12** : Inclure le Static TypoScript `Site Commune RGAA` dans votre gabarit racine (**Template > Includes**).

Pour plus de détails, consultez [Documentation/INSTALLATION.md](Documentation/INSTALLATION.md).

### 3. Gestionnaire de fichiers créé automatiquement au déploiement

À la fin de l'assistant de déploiement (module **Collectivités RGAA**), après la création des pages, l'extension prépare le gestionnaire de fichiers de la commune (service `CommuneFileStorageService`) :

| Élément | Résultat (ex. identifiant `mouguerre`) |
|---|---|
| Dossier des ressources du thème | `fileadmin/mouguerre/template/` |
| Dossier des contenus | `fileadmin/mouguerre/uploads/` |
| Arborescence | Un dossier par page sélectionnée du **Menu principal**, dans le même ordre et la même hiérarchie (`0_accueil/0_bandeau`, `0_accueil/1_actualites`, `0_accueil/2_agenda`, `1_la-mairie/…`), suivi du dossier `FORMATION`. Les pages techniques de détail (actus, agenda) n'ont pas de sous-dossier superflu. |
| Stockage FAL (`sys_file_storage`) | Optionnel via l'étape 5 de l'assistant (« Créer un point de stockage dédié », décochée par défaut). Si activé : point de stockage local dédié sur `fileadmin/[id]/uploads/`. Si non activé : mutualisé proprement sous le stockage principal `fileadmin`. |
| Point de montage (`sys_filemounts`) | Créé systématiquement à la racine du dossier `uploads/` de la commune (sur `[storage_uid]:/` si stockage dédié, ou sur `1:/[id]/uploads/` par défaut), prêt à être affecté aux groupes de rédacteurs de la collectivité. |

Nommage des dossiers : index de 0 à N, puis `_`, puis le titre en minuscules sans accents ; espaces, apostrophes et caractères spéciaux deviennent des tirets (« L'histoire » → `l-histoire`).

**Ce que le service ne fait jamais :** supprimer, renommer ou déplacer un dossier. Un stockage ou un point de montage existant pour le même chemin est réutilisé. Si `uploads/` contient déjà des éléments, l'arborescence n'est pas générée. Un échec n'annule pas le déploiement du site : un message (avertissement ou erreur) s'affiche et le détail est journalisé dans les logs TYPO3.

---

## 🌐 Publication sur GitHub

Pour initialiser le dépôt Git et le publier sur votre compte GitHub :

```bash
cd C:\Users\jonathan.auribault\.gemini\antigravity\scratch\site_commune_rgaa
git init
git add .
git commit -m "feat: initial commit - TYPO3 site_commune_rgaa extension"
git branch -M main
git remote add origin https://github.com/VOTRE_UTILISATEUR/site-commune-rgaa.git
git push -u origin main
```

---

## 📄 Licence

Ce projet est distribué sous licence GNU General Public License v2 ou supérieure (GPL-2.0-or-later).
