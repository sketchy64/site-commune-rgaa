<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Innogam\Dto;

/**
 * Représentation typée d'un élu de la collectivité.
 * Prévu pour anticiper le trombinoscope des élus.
 */
class Elu
{
    public function __construct(
        private readonly string $civilite = '',
        private readonly string $nom = '',
        private readonly string $prenom = '',
        private readonly string $photo = '',
        private readonly string $typeMandat = ''
    ) {
    }

    public function getCivilite(): string
    {
        return $this->civilite;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function getPhoto(): string
    {
        return $this->photo;
    }

    public function getTypeMandat(): string
    {
        return $this->typeMandat;
    }

    public function getFullName(): string
    {
        $parts = array_filter([$this->civilite, $this->prenom, $this->nom]);
        return implode(' ', $parts);
    }

    public function isMaireOuPresident(): bool
    {
        $mandat = mb_strtolower($this->typeMandat);
        return str_contains($mandat, 'maire') || str_contains($mandat, 'président') || str_contains($mandat, 'president');
    }

    public function isAdjoint(): bool
    {
        $mandat = mb_strtolower($this->typeMandat);
        return str_contains($mandat, 'adjoint');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'civilite' => $this->civilite,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'full_name' => $this->getFullName(),
            'photo' => $this->photo,
            'type_mandat' => $this->typeMandat,
            'is_maire_president' => $this->isMaireOuPresident(),
            'is_adjoint' => $this->isAdjoint(),
        ];
    }
}
