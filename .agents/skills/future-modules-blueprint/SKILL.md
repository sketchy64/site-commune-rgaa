---
name: future-modules-blueprint
description: >-
  Plan d'architecture et trame de conception pour les modules futurs (Annuaire des commerces/associations, Co-marquage service-public.gouv.fr, Publication des comptes-rendus et actes réglementaires PDF).
---

# Skill : Blueprint des Modules Futurs de l'Extension

Ce skill fournit les feuilles de route techniques et l'architecture préconisée pour le développement des fonctionnalités de la seconde phase de l'extension `site_commune_rgaa`.

---

## 🏬 Module 1 : Annuaire des Commerces & Associations

### Objectif
Permettre à la commune de proposer un annuaire administrable et filtrable des commerces, artisans, services médicaux et associations locales.

### Architecture Technique Proposée
* **Domain Models PHP** :
  * `Commune\SiteCommuneRgaa\Domain\Model\DirectoryItem` (Nom, Adresse, Horaires, Téléphone, Site Web, Géolocalisation, Catégorie, Accessibilité PMR).
  * `Commune\SiteCommuneRgaa\Domain\Model\Category` (Commerce, Association, Santé, Artisanat).
* **TCA & Backend TYPO3** :
  * Table `tx_sitecommunergaa_domain_model_directoryitem`.
  * Filtre par catégorie et recherche par mot-clé dans le backend et frontend.
* **Accessibilité RGAA** :
  * Liste structurée sous forme de balises `<ul class="directory-list">` / `<li>`.
  * Carte interactive (si intégrée) avec alternative sous forme de liste texte accessible (très important pour le RGAA).

---

## 🏛️ Module 2 : Co-marquage avec `service-public.gouv.fr`

### Objectif
Intégrer les démarches administratives officielles (Cartes d'identité, Passeports, État civil, Urbanisme) fournies par l'API ou le flux XML du site [https://www.service-public.gouv.fr/](https://www.service-public.gouv.fr/).

### Architecture Technique Proposée
* **Service d'Import / Synchronisation** :
  * Commande Symfony Console (`Commune\SiteCommuneRgaa\Command\ImportServicePublicCommand`) pour télécharger et parser le flux v3/v4 de Service-Public.gouv.fr.
  * Tâche planifiée TYPO3 Scheduler pour mettre à jour les fiches de démarches.
* **Rendu Frontend** :
  * Cache TYPO3 optimisé pour éviter le ralentissement lors de l'affichage des fiches administratives.
  * Templates Fluid épurés garantissant l'accessibilité des accordéons et arborescences de démarches.

---

## 📄 Module 3 : Actes Réglementaires & Comptes-Rendus (Documents PDF)

### Objectif actuels & évolutions
* **Étape initiale (Actuelle)** : Création d'une page structurée listant les fichiers PDF avec filtres de tri.
* **Évolution future** : Module de gestion documentaire des actes administratifs locaux (Comptes-rendus de conseil municipal, Arrêtés municipaux, Décisions du Maire, Délibérations).

### Architecture Technique Proposée
* **Domain Model PHP** :
  * `Commune\SiteCommuneRgaa\Domain\Model\Document` (Titre, Date de séance / publication, Type d'acte, Fichier FAL `sys_file_reference`, Thématique).
* **Filtres de Tri Frontend** :
  * Formulaire de filtre par Année, par Type d'acte (PV, Arrêté, Délibération) et recherche textuelle.
* **Accessibilité RGAA des PDF & Téléchargements** :
  * Les liens de téléchargement doivent comporter l'indication explicite du format, de la taille et de la langue :
    ```xml
    <a href="{document.file.originalResource.publicUrl}" target="_blank" rel="noopener">
        {document.title}
        <span class="visually-hidden"> (Document PDF - {document.file.originalResource.size -> f:format.bytes()} - Ouvre dans une nouvelle fenêtre)</span>
        <span aria-hidden="true" class="badge-pdf">PDF</span>
    </a>
    ```
  * Rappeler la consigne RGAA : Les fichiers PDF mis en ligne doivent eux-mêmes être balisés et accessibles (PDF/UA).
