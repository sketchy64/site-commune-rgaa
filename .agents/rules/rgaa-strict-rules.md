# Directives Strictes d'Accessibilité RGAA 4.1 & WCAG 2.1

Les consignes suivantes sont **obligatoires et systématiques** pour tout fichier modifié ou créé dans l'extension `site_commune_rgaa`.

---

## 1. Structure HTML5 & Balisage Sémantique
* N'utilisez **jamais** de balises neutres (`<div>`, `<span>`) à la place d'éléments sémantiques.
* Les régions majeures de la page doivent comporter leurs rôles ou éléments HTML5 natifs :
  * En-tête : `<header>` / `role="banner"`
  * Navigation principale : `<nav aria-label="Navigation principale">` / `role="navigation"`
  * Contenu principal : `<main>` / `role="main"`
  * Pied de page : `<footer>` / `role="contentinfo"`
  * Zones de recherche : `<search>` ou `<form role="search">`

## 2. Hiérarchie des Titres (`<h1>` à `<h6>`)
* Le titre de niveau `<h1>` doit être **unique** sur chaque page.
* Interdiction absolue de sauter des niveaux de titre (ex: passer directement de `<h2>` à `<h4>`).
* Tout composant surchargé (`bootstrap_package` ou `georgringer/news`) doit permettre au webmaster d'ajuster le niveau de titre ou imposer un niveau cohérent.

## 3. Alternatives d'Images (`alt`)
* `<f:image>` ou `<img>` : l'attribut `alt` doit **toujours** être présent.
* Si l'image est informative : `alt="Description précise de l'image"`.
* Si l'image est purement décorative : `alt=""` (chaine vide) ET `aria-hidden="true"`.

## 4. Liens et Intitulés Explicites
* Interdiction de créer des liens ayant pour seul texte "Lire la suite", "Cliquez ici" ou "En savoir plus".
* Pour les listes d'actualités `georgringer/news` : l'intitulé du lien "Lire la suite" doit être contextualisé pour les lecteurs d'écran via un texte masqué accessible (`<span class="visually-hidden"> sur : {newsItem.title}</span>`).

## 5. Navigation Clavier & Visibilité du Focus
* Tous les éléments interactifs (`<a>`, `<button>`, `<input>`, `<select>`, `<textarea>`) doivent être atteignables et activables via la touche `Tab` / `Entrée` / `Espace`.
* Interdiction d'éteindre le contour de focus (`outline: none` ou `outline: 0`) sans fournir immédiatement un style de remplacement hautement visible (`:focus-visible`).

## 6. Formulaires & Messages d'Erreur
* Tout champ de formulaire doit posséder un `<label>` associé via `for="id_du_champ"`.
* Les champs requis doivent comporter `required` et `aria-required="true"`.
* Les messages d'erreur doivent être associés aux champs via `aria-describedby="id_message_erreur"`.
