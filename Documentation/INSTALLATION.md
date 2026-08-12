# Guide d'Installation de l'Extension `site_commune_rgaa`

Ce guide décrit l'installation et l'activation du sitepackage sur une instance TYPO3 (v12 ou v13).

---

## 1. Prérequis
- Instance TYPO3 v12.4 LTS ou TYPO3 v13.4 LTS
- PHP 8.1+
- Extension `bk2k/bootstrap-package` installée

---

## 2. Installation via Composer

Dans le répertoire racine de votre projet TYPO3 :

```bash
# Ajouter le dépôt local ou pointer vers votre dépôt GitHub
composer require commune/site-commune-rgaa
```

---

## 3. Configuration dans TYPO3

### Pour TYPO3 v13 (Site Sets)
1. Rendez-vous dans le module **Site Management > Sites**.
2. Éditez la configuration de votre site.
3. Dans l'onglet **Sets**, ajoutez le set : `Site Commune RGAA (commune/site-commune-rgaa)`.
4. Sauvegardez la configuration du site.

### Pour TYPO3 v12 (TypoScript classique)
1. Rendez-vous dans le module **Template**.
2. Sélectionnez la page racine de votre site et choisissez **Edit the whole template record**.
3. Dans l'onglet **Includes**, dans la section **Include static (from extensions)** :
   - Assurez-vous d'abord que `Bootstrap Package (bootstrap_package)` est inclus.
   - Ajoutez ensuite `Site Commune RGAA - Surcouche Accessible Bootstrap Package (site_commune_rgaa)`.
4. Sauvegardez.

---

## 4. Personnalisation des constantes de la Commune

Dans le module **Template > Constant Editor**, sélectionnez la catégorie `COMMUNE` et configurez :
- **Nom de la commune** (ex: `Mairie de Chambéry`)
- **Slogan ou Devise** (ex: `Liberté, Égalité, Fraternité`)
- **Coordonnées** : Adresse, Téléphone, Courriel officiel, Horaires d'ouverture
- **Statut RGAA** : Statut d'accessibilité légal et UID de la page de déclaration
- **UIDs des pages système** : Plan du site, Mentions légales, Données personnelles (RGPD)
