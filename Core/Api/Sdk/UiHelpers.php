<?php

declare(strict_types=1);

namespace Core\Api\Sdk;

/**
 * Helpers officiels de surcharge UI via EventManager (Sprint 18 — Lot R4).
 * Permet aux vues Core d'exposer proprement les points d'extension :
 * - ui_text (surcharge de libellés / textes)
 * - ui_tabs (+ alias rétrocompatible stats_tabs)
 * - ui_slot_{zone} (injection de blocs HTML dans des zones prédéfinies)
 * - ui_assets (injection sécurisée de scripts ES modules et feuilles CSS).
 */
final class UiHelpers
{
    /**
     * Surcharge un texte ou libellé Core via le filtre `ui_text`.
     * Échappe automatiquement la sortie HTML (sauf si `$context['raw'] === true`).
     *
     * @param string               $key     Clé sémantique (ex: 'home.hero.title').
     * @param string               $default Texte par défaut du Core.
     * @param array<string, mixed> $context Contexte additionnel (page, role, params, raw).
     * @return string
     */
    public static function text(string $key, string $default, array $context = []): string
    {
        $ctx = array_merge([
            'key'    => $key,
            'page'   => '',
            'role'   => (string) ($_SESSION['role_name'] ?? 'Visiteur'),
            'params' => [],
        ], $context);

        $filtered = EventManager::getInstance()->applyFilters('ui_text', $default, $ctx);
        $value = is_string($filtered) ? $filtered : $default;

        if (!empty($ctx['raw'])) {
            return $value;
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Résout la liste des onglets d'une page via `ui_tabs` (et l'alias `stats_tabs` pour `page === 'stats'`).
     * Supporte l'ajout, la modification, le remplacement et le tri par `order`.
     *
     * @param string                                      $page        Identifiant de la page ('stats', 'sys-smtp', 'dashboard', etc.).
     * @param array<int, array<string, mixed>>            $defaultTabs Onglets par défaut du Core.
     * @param array<string, mixed>                        $context     Contexte additionnel (role, user_id).
     * @return array<int, array<string, mixed>>
     */
    public static function tabs(string $page, array $defaultTabs, array $context = []): array
    {
        $ctx = array_merge([
            'page'    => $page,
            'role'    => (string) ($_SESSION['role_name'] ?? 'Visiteur'),
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        ], $context);

        $em = EventManager::getInstance();
        $tabs = $em->applyFilters('ui_tabs', $defaultTabs, $ctx);
        if (!is_array($tabs)) {
            $tabs = $defaultTabs;
        }

        // Alias rétrocompatible officiel pour la page Statistiques
        if ($page === 'stats') {
            $legacyTabs = $em->applyFilters('stats_tabs', $tabs, $ctx);
            if (is_array($legacyTabs)) {
                $tabs = $legacyTabs;
            }
        }

        // Normalisation et tri optionnel par `order` tout en préservant l'ordre déclaratif par défaut
        $normalized = [];
        foreach (array_values($tabs) as $index => $tab) {
            if (!is_array($tab) || empty($tab['id']) || empty($tab['label'])) {
                continue;
            }
            $tab['_idx'] = $index;
            $normalized[] = $tab;
        }

        usort($normalized, static function (array $a, array $b): int {
            $orderA = isset($a['order']) && is_numeric($a['order']) ? (int) $a['order'] : (($a['_idx'] + 1) * 10);
            $orderB = isset($b['order']) && is_numeric($b['order']) ? (int) $b['order'] : (($b['_idx'] + 1) * 10);
            return $orderA <=> $orderB ?: ((int) $a['_idx'] <=> (int) $b['_idx']);
        });

        return array_map(static function (array $tab): array {
            unset($tab['_idx']);
            return $tab;
        }, $normalized);
    }

    /**
     * Injecte le contenu HTML d'une zone d'extension via le filtre `ui_slot_{$zone}`.
     *
     * @param string               $zone    Nom du slot (ex: 'home_hero', 'dashboard_top', 'footer_links').
     * @param array<string, mixed> $context Contexte additionnel (page, role, data).
     * @return string
     */
    public static function slot(string $zone, array $context = []): string
    {
        $cleanZone = preg_replace('/[^a-z0-9_]/i', '', $zone) ?: '';
        if ($cleanZone === '') {
            return '';
        }

        $ctx = array_merge([
            'zone' => $cleanZone,
            'page' => '',
            'role' => (string) ($_SESSION['role_name'] ?? 'Visiteur'),
            'data' => [],
        ], $context);

        $html = EventManager::getInstance()->applyFilters('ui_slot_' . $cleanZone, '', $ctx);
        return is_string($html) ? $html : '';
    }

    /**
     * Résout les scripts et feuilles de style d'une page via le filtre `ui_assets`.
     * N'autorise que des chemins locaux sous `/assets/` (bloque les URLs externes et traversées `..`).
     *
     * @param string             $page    Identifiant de la page (`$activeNav`).
     * @param array<int, string> $scripts Scripts par défaut de la page.
     * @param array<int, string> $styles  Styles par défaut de la page.
     * @return array{scripts: array<int, string>, styles: array<int, string>}
     */
    public static function assets(string $page, array $scripts = [], array $styles = []): array
    {
        $initial = [
            'scripts' => array_values($scripts),
            'styles'  => array_values($styles),
        ];

        $filtered = EventManager::getInstance()->applyFilters('ui_assets', $initial, [
            'page' => $page,
            'role' => (string) ($_SESSION['role_name'] ?? 'Visiteur'),
        ]);

        if (!is_array($filtered)) {
            $filtered = $initial;
        }

        $sanitizeList = static function (mixed $list): array {
            if (!is_array($list)) {
                return [];
            }
            $clean = [];
            foreach ($list as $item) {
                $url = trim((string) $item);
                if ($url === '' || !str_starts_with($url, '/assets/') || str_contains($url, '..')) {
                    continue;
                }
                if (!in_array($url, $clean, true)) {
                    $clean[] = $url;
                }
            }
            return $clean;
        };

        return [
            'scripts' => $sanitizeList($filtered['scripts'] ?? $initial['scripts']),
            'styles'  => $sanitizeList($filtered['styles'] ?? $initial['styles']),
        ];
    }
}
