# Architecture Technique de l'Extension `site_commune_rgaa`

Cette extension applique le modèle de **Sitepackage** propre à TYPO3 sans altérer le code source de l'extension tierce `bootstrap_package`.

---

## 1. Mécanisme de surcouche des gabarits Fluid

TypoScript permet d'empiler plusieurs répertoires de templates via `templateRootPaths`, `partialRootPaths` et `layoutRootPaths`.
L'extension définit des priorités plus élevées (indice 20 ou 100) pour intercepter le rendu avant le `bootstrap_package` :

```typoscript
lib.contentElement {
  templateRootPaths {
    100 = EXT:site_commune_rgaa/Resources/Private/OverriddenTemplates/BootstrapPackage/ContentElements/
  }
}
```

Si un template spécifique n'est pas redéfini dans `site_commune_rgaa`, TYPO3 se rabat automatiquement et sans erreur sur le gabarit d'origine de `bootstrap_package`.

---

## 2. Arborescence des surcharges

| Élément | Fichier d'origine (bootstrap_package) | Fichier surchargé (site_commune_rgaa) | Amélioration RGAA |
|---|---|---|---|
| **Accordéon** | `ContentElements/Accordion.html` | `OverriddenTemplates/.../Accordion.html` | Vrais `<button>`, `aria-expanded`, gestion clavier |
| **Onglets** | `ContentElements/Tab.html` | `OverriddenTemplates/.../Tab.html` | `role="tablist"`, `role="tab"`, `aria-selected` |
| **Carrousel** | `ContentElements/Carousel.html` | `OverriddenTemplates/.../Carousel.html` | Bouton Pause/Lecture (Critère 13.8), labels de slides |
| **Cartes** | `ContentElements/CardGroup.html` | `OverriddenTemplates/.../CardGroup.html` | Contextualisation des liens "En savoir plus" |
| **Texte & Média** | `ContentElements/Textmedia.html` | `OverriddenTemplates/.../Textmedia.html` | `figure` / `figcaption`, `alt=""` pour images décoratives |

---

## 3. ViewHelpers d'Accessibilité dédiés

Situés dans le namespace `Commune\SiteCommuneRgaa\ViewHelpers\A11y\` :
- `<rgaa:a11y.ariaLabel action="..." context="..." />` : Génère des intitulés de lien intelligibles pour les technologies d'assistance.
- `<rgaa:a11y.decorativeImage alt="..." isDecorative="..." />` : Injecte dynamiquement les attributs de masquage sémantique.
- `<rgaa:a11y.externalLinkAria target="..." />` : Injecte l'avertissement de nouvelle fenêtre lors d'une ouverture externe.
