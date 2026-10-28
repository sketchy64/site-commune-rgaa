<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Innogam;

use Commune\SiteCommuneRgaa\Innogam\Dto\CollectiviteDetail;

/**
 * Mappe les données remontées par l'API Innogam vers les paramètres de site TYPO3 (commune.*).
 */
class CollectiviteSettingsMapper
{
    /**
     * Mappe une fiche détaillée vers le dictionnaire des paramètres de configuration du site.
     *
     * @return array<string, string>
     */
    public function mapToSettings(CollectiviteDetail $detail): array
    {
        return [
            // Nom officiel de la commune sans préfixe imposé (choix utilisateur)
            'commune.nom' => $detail->getNom(),
            'commune.adresse' => $detail->getFormattedAdresse(),
            'commune.telephone' => $detail->getTel(),
            'commune.email' => $detail->getEmail(),
            'commune.horaires' => $detail->getHoraires(),
            'commune.innogam_id' => $detail->getId(),
            'commune.innogam_type' => $detail->getType(),
            'commune.innogam_last_sync' => date('d/m/Y H:i'),
        ];
    }

    /**
     * Construit un tableau comparatif détaillé entre les paramètres actuels du site et ceux proposés par Innogam.
     *
     * @param array<string, mixed> $currentSettings Paramètres actuels du site (clés commune.* ou imbriquées)
     * @param CollectiviteDetail $detail Fiche Innogam
     * @return array<int, array{key: string, label: string, current: string, proposed: string, is_different: bool, description: string}>
     */
    public function buildComparison(array $currentSettings, CollectiviteDetail $detail): array
    {
        $proposed = $this->mapToSettings($detail);

        $fields = [
            [
                'key' => 'commune.nom',
                'label' => 'Nom de la collectivité',
                'description' => 'Nom officiel affiché dans l\'en-tête, le titre et le pied de page',
            ],
            [
                'key' => 'commune.adresse',
                'label' => 'Adresse postale',
                'description' => 'Adresse postale complète de la mairie ou du siège',
            ],
            [
                'key' => 'commune.telephone',
                'label' => 'Téléphone officiel',
                'description' => 'Numéro de téléphone d\'accueil',
            ],
            [
                'key' => 'commune.email',
                'label' => 'Adresse courriel (e-mail)',
                'description' => 'Adresse de messagerie publique officielle',
            ],
            [
                'key' => 'commune.horaires',
                'label' => 'Horaires d\'ouverture au public',
                'description' => 'Plages horaires d\'accueil des administrés',
            ],
        ];

        $comparison = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $currentVal = $this->extractCurrentValue($currentSettings, $key);
            $proposedVal = trim((string)($proposed[$key] ?? ''));

            $isDifferent = (trim($currentVal) !== $proposedVal) && ($proposedVal !== '');

            $comparison[] = [
                'key' => $key,
                'label' => $field['label'],
                'description' => $field['description'],
                'current' => $currentVal,
                'proposed' => $proposedVal,
                'is_different' => $isDifferent,
            ];
        }

        return $comparison;
    }

    /**
     * Extrait une valeur courante en supportant à la fois la notation 'commune.nom' et 'commune']['nom'.
     *
     * @param array<string, mixed> $settings
     */
    private function extractCurrentValue(array $settings, string $key): string
    {
        if (isset($settings[$key])) {
            return (string)$settings[$key];
        }

        $parts = explode('.', $key);
        if (count($parts) === 2 && isset($settings[$parts[0]][$parts[1]])) {
            return (string)$settings[$parts[0]][$parts[1]];
        }

        return '';
    }
}
