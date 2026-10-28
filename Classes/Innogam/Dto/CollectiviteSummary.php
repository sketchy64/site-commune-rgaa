<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Innogam\Dto;

/**
 * Résumé d'une collectivité retourné par les listes /communes et /intercos.
 */
class CollectiviteSummary
{
    public function __construct(
        private readonly string $id,
        private readonly string $type,
        private readonly string $nom,
        private readonly string $nomCourt = ''
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

    public function isCommune(): bool
    {
        return $this->type === 'commune';
    }

    public function isInterco(): bool
    {
        return $this->type === 'interco';
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'commune' => 'Commune',
            'interco' => 'Intercommunalité / EPCI / Syndicat',
            default => ucfirst($this->type),
        };
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
            'type_label' => $this->getTypeLabel(),
            'is_commune' => $this->isCommune(),
        ];
    }
}
