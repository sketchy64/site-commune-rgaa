---
name: rgaa-fluid-compliance
description: >-
  Guide pas à pas pour auditer, créer ou corriger des gabarits Fluid et éléments de contenu HTML dans TYPO3 afin d'assurer une conformité 100% RGAA 4.1 et WCAG 2.1 AA.
---

# Skill : Conformité RGAA 4.1 & WCAG 2.1 des Templates Fluid

Ce skill détaille la méthodologie pas à pas pour auditer et modifier tout template Fluid (Page, Content Element Bootstrap Package, News Item) afin de le rendre rigoureusement accessible.

---

## 🛠️ Étapes d'Audit et de Correction d'un Template Fluid

### Étape 1 : Inspection du Balisage HTML & Sémantique
1. Vérifier qu'aucun élément interactif n'utilise de balise passive (`<div onclick="...">` -> `<button type="button">`).
2. S'assurer que le composant utilise les balises HTML5 appropriées (`<article>`, `<header>`, `<nav>`, `<footer>`, `<aside>`).

### Étape 2 : Structure des Titres
1. Contrôler que la hiérarchie des balises `<hX>` est continue.
2. Dans un Partial réutilisable, si le niveau de titre doit varier, utiliser une variable dynamique :
   ```xml
   <{headerData.headerType} class="news-title">
       {newsItem.title}
   </{headerData.headerType}>
   ```

### Étape 3 : Surcharges des Éléments d'Actualités (georgringer/news)
1. Modifier le Partial `List/Item.html` pour rendre les liens explicites aux lecteurs d'écran :
   ```xml
   <f:link.action action="detail" newsItem="{newsItem}" pageUid="{settings.detailPid}" title="{newsItem.title}">
       <span aria-hidden="true">Lire la suite</span>
       <span class="visually-hidden"> concernant l'actualité : {newsItem.title}</span>
   </f:link.action>
   ```

### Étape 4 : Validation des Formulaires
1. S'assurer que chaque `<f:form.textfield>` possède un `id` unique et un `<label for="id">` correspondant.
2. Pour les messages d'erreur de validation Extbase, ajouter `aria-invalid="true"` et associer l'ID du message d'erreur via `aria-describedby`.

### Étape 5 : Rôles & Attributs WAI-ARIA
1. Pour les composants dépliables (accordeons, menus burger) :
   * Le bouton déclencheur doit avoir `aria-expanded="true|false"` et `aria-controls="id_zone_contenu"`.
   * La zone masquée doit comporter `id="id_zone_contenu"` et l'attribut `aria-hidden="true|false"`.

### Étape 6 : Contrôle du Visuellement Masqué (`visually-hidden`)
1. Utiliser la classe CSS accessible standard pour le texte réservé aux lecteurs d'écran :
   ```css
   .visually-hidden {
       position: absolute !important;
       width: 1px !important;
       height: 1px !important;
       padding: 0 !important;
       margin: -1px !important;
       overflow: hidden !important;
       clip: rect(0, 0, 0, 0) !important;
       white-space: nowrap !important;
       border: 0 !important;
   }
   ```

---

## 🔍 Grille de Vérification finale (Checklist RGAA)
- [ ] Alternative textuelle pour chaque image (`alt`).
- [ ] Aucun composant utilisable uniquement à la souris.
- [ ] Prise en charge des lecteurs d'écran (intitulés de liens explicites, `visually-hidden`).
- [ ] Ratio de contraste minimal respecté (4.5:1).
- [ ] Indicateur de focus visible et net lors de la navigation au clavier.
