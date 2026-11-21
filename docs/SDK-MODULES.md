# Guide Développeur de Modules & Catalogue des Hooks UI (SDK-MODULES.md)

**Version :** V2.0 (Core `2.0.0`, PHP `>=8.5`)  
**Audience :** Développeurs d'extensions tierces Kanso-ya

---

## 1. Structure Normalisée d'un Module (`{NomModule}Api` / `{NomModule}Ui`)

Tout module Kanso-ya vit dans `/modules/` et sépare strictement la logique backend de l'interface :

```text
modules/
├── MonModuleApi/                    # Obligatoire — Namespace Modules\MonModuleApi
│   ├── manifest.json                # Métadonnées, compatibilité Core/PHP, assets
│   ├── Module.php                   # Classe implémentant Core\Api\Sdk\ModuleInterface
│   ├── Endpoints/                   # Contrôleurs API REST (JSend HTTP 200)
│   ├── Services/                    # Services métier
│   └── Migrations/                  # Tables SQL préfixées mod_{slug}_*
│
└── MonModuleUi/                     # Optionnel — Interface utilisateur du module
    ├── Pages/                       # Vues PHP (protégées par defined('KANSO_ENTRY'))
    ├── Components/                  # Blocs HTML injectables via ui_slot_*
    └── dist/                        # Assets statiques compilés (.js, .css, .svg, .png, .webp)
```

> [!CAUTION]
> Aucun fichier `.php` d'un module ne doit jamais être placé dans `/public/`. Les pages sont déclarées via `ui_routes` et les assets statiques de `dist/` sont publiés automatiquement par `ModuleManager` vers `public/assets/modules/{public_slug}/` lors de l'activation (et purgés à la désactivation/désinstallation).

---

## 2. Manifeste v2 (`manifest.json`)

```json
{
    "schema_version": 2,
    "id": "boutique",
    "name": "Boutique Officielle du Club",
    "version": "2.0.0",
    "author": "Kanso-ya Team",
    "description": "Gestion des équipements sportifs et commandes adhérents.",
    "namespace": "Modules\\BoutiqueApi",
    "entry_class": "Modules\\BoutiqueApi\\Module",
    "ui_folder": "BoutiqueUi",
    "compatibility": {
        "core_min": "2.0.0",
        "php_min": "8.5.0"
    },
    "tables_prefix": "mod_boutique_",
    "assets": {
        "publish_dir": "BoutiqueUi/dist",
        "public_slug": "boutique"
    },
    "permissions_required": ["finances.manage"]
}
```

---

## 3. Cycle de Vie (`Core\Api\Sdk\ModuleInterface`)

```php
<?php

declare(strict_types=1);

namespace Modules\MonModuleApi;

use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;

final class Module implements ModuleInterface
{
    public function install(): void
    {
        // Crée exclusivement des tables préfixées mod_monmodule_*
    }

    public function uninstall(): void
    {
        // Purge uniquement DROP TABLE IF EXISTS mod_monmodule_*
    }

    public function boot(EventManager $em): void
    {
        // Enregistre ses routes, menus, actions et filtres UI
    }
}
```

---

## 4. Catalogue Complet des Hooks (`EventManager` & `UiHelpers`)

### 4.1. Actions Métier du Core
| Action | Payload | Déclencheur |
|---|---|---|
| `on_member_created` | `['member_id' => int, ...]` | Création d'une fiche adhérent |
| `on_member_deleted` | `['member_id' => int]` | Suppression définitive d'un adhérent |
| `on_registration_validated` | `['registration_id' => int, ...]` | Validation d'un dossier d'adhésion |
| `on_article_published` | `['article_id' => int, ...]` | Publication d'un article CMS |

### 4.2. Filtres de Routage, Navigation & Surcharge UI
| Filtre | Valeur initiale | Contexte | Usage |
|---|---|---|---|
| `ui_routes` | `array<string, array>` | `['uri' => string]` | Déclare une route UI pointant vers `modules/{Nom}Ui/Pages/*.php` (`page`, `auth`, `perms`, `superadmin_only`). |
| `api_routes` | `array<int, array>` | `[]` | Déclare un endpoint REST confiné sous `/api/v1/modules/{slug}/*` (`method`, `pattern`, `handler`, `perms`, `auth`). |
| `admin_sidebar_menu` | `array<int, array>` | `['role' => string]` | Injecte un lien dans `#module-hooks-zone` de la Sidebar (`id`, `label`, `url`, `icon`, `permission`). |
| `ui_text` | `string` | `['key', 'page', 'role', 'params']` | Surcharge un libellé Core (ex. `home.hero.title`, `home.hero.subtitle`, `home.hero.badge`, `home.hero.cta_register`). |
| `ui_tabs` | `array<int, array>` | `['page', 'role', 'user_id']` | Ajoute, renomme ou réordonne (`order`) un onglet sur `/stats`, `/sys-smtp`, etc. |
| `stats_tabs` | `array<int, array>` | `['role', 'user_id']` | Alias rétrocompatible de `ui_tabs` pour `page === 'stats'`. |
| `ui_slot_{zone}` | `string` (`''`) | `['zone', 'page', 'role', 'data']` | Injecte un bloc HTML dans une zone dédiée : `home_hero`, `home_after_articles`, `dashboard_top`, `dashboard_bottom`, `footer_links`. |
| `ui_page_override` | `string` (chemin vue Core) | `['route', 'page_id', 'role', 'params']` | Remplace intégralement une page Core par une vue dans `modules/*Ui/Pages/*.php` (validé par `realpath()` ; tout chemin hors périmètre est rejeté et journalisé `UI_PAGE_OVERRIDE_BLOCKED`). |
| `ui_assets` | `['scripts' => [], 'styles' => []]` | `['page', 'role']` | Charge des assets locaux `/assets/modules/{slug}/*.{css,js}` dans `<head>` (les URLs externes et `..` sont filtrés). |

---

## 5. Safe Mode Automatique (Boot & Filtres)

- Pendant `ModuleManager::bootActiveModules()`, `EventManager::setCurrentModule($folderName)` étiquette chaque action et filtre enregistré par le module.
- Si une exception (`Throwable`) survient **au `boot()`** ou **durant l'exécution d'un filtre/action UI** :
  1. Le CMS reste disponible (HTTP 200 garanti, valeur précédente du filtre préservée).
  2. `ModuleManager::quarantineModule()` bascule immédiatement le module en `status = 'crashed'`, `is_active = 0`.
  3. Ses assets publiés dans `public/assets/modules/{slug}/` sont purgés.
  4. Un événement indélébile `MODULE_SAFE_MODE_TRIGGERED` est consigné dans `sys_logs`.
