# Guide & Checklist d'Accessibilité Numérique RGAA 4.1.2 pour Communes & Collectivités

Ce document détaille les règles et critères du **RGAA (Référentiel Général d'Amélioration de l'Accessibilité version 4.1.2)** implémentés dans cette extension et les consignes pour les contributeurs de contenu TYPO3.

---

## 1. Images (Critères 1.1 à 1.9)
- **Images informatives** : Doivent posséder un texte alternatif concis et pertinent (`alternative` dans TYPO3).
- **Images décoratives** : Doivent avoir un attribut `alt=""` vide (géré automatiquement par le ViewHelper `rgaa:a11y.decorativeImage`).
- **Images-textes** : À proscrire, sauf logos officiels (qui doivent avoir pour `alt` le texte exact du logo).

## 2. Couleurs & Contrastes (Critères 3.1 à 3.3)
- **Ratio de contraste normal** : 4.5:1 minimum entre le texte et son arrière-plan.
- **Ratio de contraste grand texte (>= 18.5px gras ou >= 24px normal)** : 3:1 minimum.
- **Composants d'interface (boutons, champs, focus)** : 3:1 minimum.
- **Information par la couleur** : L'information ne doit jamais être véhiculée uniquement par la couleur (ex: ajouter un pictogramme ou du texte explicite en plus d'une couleur rouge/verte).

## 3. Multimédia & Carrousels (Critères 4.1 à 4.13 & 13.8)
- **Carrousels automatiques** : Doivent obligatoirement comporter un bouton Pause/Lecture (intégré nativement dans `Carousel.html` via `carousel-a11y.js`).
- **Vidéos** : Doivent comporter des sous-titres synchronisés et/ou une transcription textuelle complète.

## 4. Liens & Intitulés (Critères 6.1 et 6.2)
- **Intitulés explicites** : Éviter les libellés vagues comme "Cliquez ici" ou "En savoir plus" isolés.
- **Contexte** : Utiliser le ViewHelper `rgaa:a11y.ariaLabel` pour générer par exemple `aria-label="En savoir plus sur : Séance du conseil municipal du 15 juin"`.
- **Ouverture dans un nouvel onglet (`target="_blank"`)** : Doit être signalée explicitement via le ViewHelper `rgaa:a11y.externalLinkAria` ("ouvre une nouvelle fenêtre").

## 5. Scripts, Clavier & Focus (Critères 7.1, 10.7, 12.8, 12.9)
- **Accessibilité totale au clavier** : Tout élément interactif doit pouvoir recevoir le focus (`Tab` / `Shift+Tab`) et être activé (`Entrée` ou `Espace`).
- **Touche Échap** : Ferme les menus déroulants et volets mobiles tout en restituant le focus au bouton déclencheur.
- **Focus visible** : Un contour contrasté de 3px avec décalage de 3px est garanti via `accessibility.css`.

## 6. Structure & Titres (Critères 9.1 à 9.4)
- **Unicité du H1** : Chaque page possède un seul titre de niveau 1 (`<h1>`).
- **Hiérarchie sans saut** : Ne pas sauter de niveau de titre (ex: passer de H2 à H4 sans H3). Le fichier `page.tsconfig` oriente les rédacteurs dans le choix du niveau dans le RTE et `tt_content`.

## 7. Navigation & Accès Rapide (Critères 12.1 à 12.11)
- **Liens d'évitement (Skip links)** : Présents tout en haut du document (`#main-content`, `#main-navigation`, `#main-search`, `#main-footer`).
- **Fil d'Ariane** : Balisé avec `aria-label="Vous êtes ici :"` et microdonnées `BreadcrumbList`.
- **Repères ARIA (Landmarks)** : `role="banner"`, `role="navigation"`, `role="main"`, `role="contentinfo"`, `role="complementary"`.

## 8. Formulaires (Critères 11.1 à 11.14)
- Chaque champ de saisie (`<input>`, `<select>`, `<textarea>`) possède une étiquette visible (`<label for="...">`).
- Les champs obligatoires sont signalés visuellement et via `aria-required="true"`.
- Les messages d'erreur sont associés au champ via `aria-describedby` et `aria-invalid="true"`.
