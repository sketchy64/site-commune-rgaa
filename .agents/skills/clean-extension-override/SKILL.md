---
name: clean-extension-override
description: >-
  Méthodologie pour surcharger les templates, partials, layouts et TypoScript de bootstrap_package et georgringer/news de manière maintenable lors des mises à jour.
---

# Skill : Surcharge Propre et Maintenable d'Extensions Tierces

Ce skill détaille les procédures techniques permettant d'étendre `bk2k/bootstrap-package` et `georgringer/news` sans altérer les sources d'origine et en minimisant la dette technique lors des montées de version TYPO3 et extensions.

---

## 🛠️ Étapes d'Implémentation d'une Surcharge

### Étape 1 : Déclaration des chemins de Surcharge TypoScript
Dans `Configuration/TypoScript/setup.typoscript` de `EXT:site_commune_rgaa` :

```typoscript
# Surcharge pour Bootstrap Package
plugin.tx_bootstrappackage {
    view {
        templateRootPaths {
            100 = EXT:site_commune_rgaa/Resources/Private/Templates/Overrides/BootstrapPackage/Templates/
        }
        partialRootPaths {
            100 = EXT:site_commune_rgaa/Resources/Private/Partials/Overrides/BootstrapPackage/Partials/
        }
        layoutRootPaths {
            100 = EXT:site_commune_rgaa/Resources/Private/Layouts/Overrides/BootstrapPackage/Layouts/
        }
    }
}

# Surcharge pour News System (georgringer/news)
plugin.tx_news {
    view {
        templateRootPaths {
            100 = EXT:site_commune_rgaa/Resources/Private/Templates/Overrides/News/Templates/
        }
        partialRootPaths {
            100 = EXT:site_commune_rgaa/Resources/Private/Partials/Overrides/News/Partials/
        }
        layoutRootPaths {
            100 = EXT:site_commune_rgaa/Resources/Private/Layouts/Overrides/News/Layouts/
        }
    }
}
```

### Étape 2 : Copie Ciblée & Commentaire de Surcharge
1. Ne **copier que le Partial ou Template exact** nécessitant une correction.
2. Ajouter l'en-tête de traçabilité dans le fichier surchargé :
   ```xml
   <!--
       OVERRIDE: EXT:news/Resources/Private/Partials/List/Item.html
       REASON: RGAA 4.1 Compliance & Accessible Link Labels
       TARGET EXT VERSION: news ^11.0 || ^12.0
   -->
   ```

### Étape 3 : Extension du TCA & Modèles si nécessaire
Pour ajouter un champ spécifique aux actualités sans altérer EXT:news :
1. Créer `Configuration/TCA/Overrides/tx_news_domain_model_news.php`.
2. Utiliser `ExtensionManagementUtility::addTCAcolumns()` et `addToAllTCAtypes()`.
3. Déclarer le modèle d'extension PHP héritant ou étendant le modèle `GeorgRinger\News\Domain\Model\News`.

### Étape 4 : Gestion des Mises à jour Dépendances
Lors d'une mise à jour de `bootstrap_package` ou `news` :
1. Effectuer un `git diff` entre le Partial d'origine de la nouvelle version et le Partial surchargé dans `site_commune_rgaa`.
2. Valider que les nouvelles fonctionnalités de l'extension d'origine sont conservées tout en maintenant les correctifs RGAA.
