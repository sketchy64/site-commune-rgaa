<?php

declare(strict_types=1);

namespace Commune\SiteCommuneRgaa\Utility;

use Symfony\Component\String\UnicodeString;

/**
 * Génération de noms de répertoires sûrs pour le gestionnaire de fichiers (fileadmin).
 *
 * Exemples :
 *  - buildFolderName(1, 'La Mairie')    => '1_la-mairie'
 *  - buildFolderName(0, 'Actualités')   => '0_actualites'
 *  - buildFolderName(3, "L'histoire")   => '3_l-histoire'
 */
final class FolderNameSanitizer
{
    /**
     * Séparateur entre le préfixe numérique d'ordre et le slug du titre.
     */
    public const PREFIX_SEPARATOR = '_';

    /**
     * Slugifie un titre : translittération ASCII, minuscules, apostrophes et espaces
     * remplacés par des tirets, suppression de tout autre caractère spécial.
     */
    public static function slugify(string $title): string
    {
        $value = trim($title);
        if ($value === '') {
            return '';
        }

        // Apostrophes (droites, typographiques, accents graves isolés) => tiret
        $value = str_replace(["'", '’', '‘', '`', '´', 'ʼ'], '-', $value);

        // Translittération des caractères accentués / ligatures (é => e, œ => oe, ß => ss...)
        $value = self::toAscii($value);

        $value = strtolower($value);

        // Tout caractère non alphanumérique (espaces, "_", "&", ponctuation...) => tiret.
        // Le "_" est volontairement converti : il est réservé au séparateur du préfixe d'ordre.
        $value = (string)preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }

    /**
     * Translittération ASCII compatible TYPO3 v12 / v13 / v14
     * (CharsetConverter::specCharsToASCII() a été supprimé en v14).
     */
    private static function toAscii(string $value): string
    {
        // Ligatures non décomposables par la normalisation Unicode
        $value = strtr($value, ['œ' => 'oe', 'Œ' => 'OE', 'æ' => 'ae', 'Æ' => 'AE', 'ß' => 'ss']);

        if (class_exists(UnicodeString::class)) {
            try {
                return (new UnicodeString($value))->ascii()->toString();
            } catch (\Throwable) {
            }
        }

        $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;

        return is_string($converted) ? $converted : $value;
    }

    /**
     * Construit le nom de dossier numéroté : "<index>_<slug>".
     */
    public static function buildFolderName(int $index, string $title): string
    {
        $slug = self::slugify($title);
        if ($slug === '') {
            $slug = 'page';
        }

        return max(0, $index) . self::PREFIX_SEPARATOR . $slug;
    }

    /**
     * Normalise l'identifiant unique de la commune pour un usage en tant que nom de dossier.
     * Retourne une chaîne vide si l'identifiant est inexploitable (protection contre la traversée de chemin).
     */
    public static function sanitizeIdentifier(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || str_contains($identifier, '..')) {
            return '';
        }

        $identifier = (string)preg_replace('/[^a-z0-9_-]+/', '-', $identifier);

        return trim($identifier, '-_');
    }
}
