<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Innogam\Dto;

/**
 * Représentation typée d'un agent de la collectivité (DGS, secrétariat, etc.).
 * Prévu pour anticiper les annuaires internes ou organigrammes.
 */
class Agent
{
    public function __construct(
        private readonly string $nom = '',
        private readonly string $prenom = '',
        private readonly string $type = '',
        private readonly string $fonction = '',
        private readonly string $service = '',
        private readonly string $telProfessionnel = '',
        private readonly string $emailProfessionnel = ''
    ) {
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getFonction(): string
    {
        return $this->fonction;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function getTelProfessionnel(): string
    {
        return $this->telProfessionnel;
    }

    public function getEmailProfessionnel(): string
    {
        return $this->emailProfessionnel;
    }

    public function getFullName(): string
    {
        $parts = array_filter([$this->prenom, $this->nom]);
        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'full_name' => $this->getFullName(),
            'type' => $this->type,
            'fonction' => $this->fonction,
            'service' => $this->service,
            'tel_professionnel' => $this->telProfessionnel,
            'email_professionnel' => $this->emailProfessionnel,
        ];
    }
}
