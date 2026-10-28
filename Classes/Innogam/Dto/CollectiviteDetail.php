<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Innogam\Dto;

/**
 * Fiche détaillée d'une commune ou d'une intercommunalité/EPCI/syndicat.
 */
class CollectiviteDetail
{
    /**
     * @param array<int, Elu> $elus
     * @param array<int, Agent> $agents
     * @param array<int, array{insee: string, nom: string}> $communesMembres
     */
    public function __construct(
        private readonly string $id,
        private readonly string $type,
        private readonly string $nom,
        private readonly string $nomCourt = '',
        private readonly string $ligneAdresse1 = '',
        private readonly string $ligneAdresse2 = '',
        private readonly string $codePostal = '',
        private readonly string $ville = '',
        private readonly string $cedex = '',
        private readonly string $tel = '',
        private readonly string $email = '',
        private readonly string $site = '',
        private readonly string $horaires = '',
        private readonly string $gentille = '',
        private readonly ?int $populationTotale = null,
        private readonly ?float $superficie = null,
        private readonly string $nomIntercommunalite = '',
        private readonly array $communesMembres = [],
        private readonly array $elus = [],
        private readonly array $agents = []
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getNomCourt(): string
    {
        return $this->nomCourt;
    }

    public function getLigneAdresse1(): string
    {
        return $this->ligneAdresse1;
    }

    public function getLigneAdresse2(): string
    {
        return $this->ligneAdresse2;
    }

    public function getCodePostal(): string
    {
        return $this->codePostal;
    }

    public function getVille(): string
    {
        return $this->ville;
    }

    public function getCedex(): string
    {
        return $this->cedex;
    }

    public function getTel(): string
    {
        return $this->tel;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getSite(): string
    {
        return $this->site;
    }

    public function getHoraires(): string
    {
        return $this->horaires;
    }

    public function getGentille(): string
    {
        return $this->gentille;
    }

    public function getPopulationTotale(): ?int
    {
        return $this->populationTotale;
    }

    public function getSuperficie(): ?float
    {
        return $this->superficie;
    }

    public function getNomIntercommunalite(): string
    {
        return $this->nomIntercommunalite;
    }

    /**
     * @return array<int, array{insee: string, nom: string}>
     */
    public function getCommunesMembres(): array
    {
        return $this->communesMembres;
    }

    /**
     * @return array<int, Elu>
     */
    public function getElus(): array
    {
        return $this->elus;
    }

    public function getMaireOuPresident(): ?Elu
    {
        foreach ($this->elus as $elu) {
            if ($elu->isMaireOuPresident()) {
                return $elu;
            }
        }
        return null;
    }

    /**
     * @return array<int, Elu>
     */
    public function getAdjoints(): array
    {
        return array_values(array_filter($this->elus, static fn(Elu $e) => $e->isAdjoint()));
    }

    /**
     * @return array<int, Elu>
     */
    public function getConseillers(): array
    {
        return array_values(array_filter(
            $this->elus,
            static fn(Elu $e) => !$e->isMaireOuPresident() && !$e->isAdjoint()
        ));
    }

    /**
     * @return array<int, Agent>
     */
    public function getAgents(): array
    {
        return $this->agents;
    }

    /**
     * Retourne l'adresse postale complète au format standard français.
     */
    public function getFormattedAdresse(): string
    {
        $parts = [];
        if (!empty($this->ligneAdresse1)) {
            $parts[] = trim($this->ligneAdresse1);
        }
        if (!empty($this->ligneAdresse2)) {
            $parts[] = trim($this->ligneAdresse2);
        }

        $codeVilleParts = [];
        if (!empty($this->codePostal)) {
            $codeVilleParts[] = trim($this->codePostal);
        }
        if (!empty($this->ville)) {
            $codeVilleParts[] = trim($this->ville);
        }
        if (!empty($this->cedex)) {
            $codeVilleParts[] = trim($this->cedex);
        }

        if (!empty($codeVilleParts)) {
            $parts[] = implode(' ', $codeVilleParts);
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'nom' => $this->nom,
            'nom_court' => $this->nomCourt,
            'ligne_adresse_1' => $this->ligneAdresse1,
            'ligne_adresse_2' => $this->ligneAdresse2,
            'code_postal' => $this->codePostal,
            'ville' => $this->ville,
            'cedex' => $this->cedex,
            'adresse_formatee' => $this->getFormattedAdresse(),
            'tel' => $this->tel,
            'email' => $this->email,
            'site' => $this->site,
            'horaires' => $this->horaires,
            'gentille' => $this->gentille,
            'population_totale' => $this->populationTotale,
            'superficie' => $this->superficie,
            'nom_intercommunalite' => $this->nomIntercommunalite,
            'communes_membres' => $this->communesMembres,
            'elus' => array_map(static fn(Elu $e) => $e->toArray(), $this->elus),
            'agents' => array_map(static fn(Agent $a) => $a->toArray(), $this->agents),
        ];
    }
}
