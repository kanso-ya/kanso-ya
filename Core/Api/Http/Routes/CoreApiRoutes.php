<?php

declare(strict_types=1);

namespace Core\Api\Http\Routes;

use Core\Api\Database\Database;
use Core\Api\Endpoints\AuthController;
use Core\Api\Endpoints\CmsController;
use Core\Api\Endpoints\DisciplineController;
use Core\Api\Endpoints\MemberController;
use Core\Api\Endpoints\RegistrationController;
use Core\Api\Endpoints\RoleController;
use Core\Api\Endpoints\SystemController;
use Core\Api\Http\ApiKernel;
use Core\Api\Http\ApiRouter;
use Core\Api\Services\ModuleManager;
use Core\Api\Services\RolloverService;
use PDO;
use Throwable;

/**
 * Déclaration centralisée de l'ensemble des routes /api/v1/* du Core Kanso-ya.
 * Préserve strictement les contrats JSend HTTP 200 et contrôles RBAC existants.
 */
final class CoreApiRoutes
{
    public static function register(ApiRouter $router): void
    {
        self::registerAuthRoutes($router);
        self::registerMemberRoutes($router);
        self::registerRegistrationRoutes($router);
        self::registerCmsRoutes($router);
        self::registerRoleAndDisciplineRoutes($router);
        self::registerSystemRoutes($router);
        self::registerTaskAndStatsRoutes($router);
        self::registerModuleRoutes($router);
    }

    /**
     * 1. AUTHENTIFICATION, PROFIL & 2FA
     */
    private static function registerAuthRoutes(ApiRouter $router): void
    {
        $router->addRoute('POST', '/api/v1/auth/login', static function (array $ctx): array {
            return (new AuthController())->login($ctx['payload']);
        });

        $router->addRoute('POST', '/api/v1/auth/forgot-password', static function (array $ctx): array {
            return (new AuthController())->forgotPassword($ctx['payload']);
        });

        foreach (['/api/v1/sys/auth/login', '/api/v1/sys/login', '/api/v1/auth/sys-login'] as $sysLoginPath) {
            $router->addRoute('POST', $sysLoginPath, static function (array $ctx): array {
                return (new AuthController())->sysLogin($ctx['payload']);
            });
        }

        $router->addRoute('POST', '/api/v1/auth/logout', static function (): array {
            return (new AuthController())->logout();
        });

        $router->addRoute('GET', '/api/v1/auth/me', static function (array $ctx): array {
            $isAuthenticated = (bool) $ctx['is_authenticated'];
            $isSuperadmin = (bool) $ctx['is_superadmin'];
            $currentUserId = (int) $ctx['user_id'];
            $currentRole = (string) $ctx['role'];
            $permissions = $ctx['permissions'];

            $userName = '';
            if ($isAuthenticated) {
                if ($isSuperadmin) {
                    $userName = 'Superadmin';
                } else {
                    try {
                        $db = Database::getInstance();
                        $stmtU = $db->prepare('SELECT first_name, last_name, email FROM users WHERE id = :id');
                        $stmtU->execute(['id' => $currentUserId]);
                        $u = $stmtU->fetch(PDO::FETCH_ASSOC);
                        if ($u) {
                            $fn = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                            $userName = $fn !== '' ? $fn : (string) ($u['email'] ?? '');
                        }
                    } catch (Throwable) {
                    }
                }
            }

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Contexte utilisateur récupéré',
                'data'    => [
                    'user_id'     => $currentUserId,
                    'name'        => $userName,
                    'role'        => $isAuthenticated ? $currentRole : 'Visiteur',
                    'permissions' => $permissions,
                ],
            ];
        });

        $router->addRoute('GET', '/api/v1/users/me/profile', static function (array $ctx): array {
            return (new AuthController())->getMyProfile((int) $ctx['user_id']);
        });

        $router->addRoute('PUT', '/api/v1/users/me/profile', static function (array $ctx): array {
            return (new AuthController())->updateMyProfile((int) $ctx['user_id'], $ctx['payload']);
        });

        $router->addRoute('POST', '/api/v1/users/me/2fa', static function (array $ctx): array {
            return (new AuthController())->toggleTwoFactor((int) $ctx['user_id'], $ctx['payload']);
        });

        $router->addRoute('POST', '/api/v1/users/me/2fa/send-email-code', static function (array $ctx): array {
            if (!$ctx['is_authenticated']) {
                return [
                    'status'  => 'error',
                    'code'    => 401,
                    'message' => 'Veuillez vous connecter pour demander un code de vérification.',
                    'data'    => null,
                ];
            }
            return (new AuthController())->sendTwoFactorEmailCode((int) $ctx['user_id']);
        });

        $router->addRoute('PATCH', '/api/v1/users/me/password', static function (array $ctx): array {
            return (new AuthController())->updatePassword((int) $ctx['user_id'], $ctx['payload']);
        });
    }

    /**
     * 2. CRM ADHÉRENTS, RATTACHEMENTS & RENVOI OTP
     */
    private static function registerMemberRoutes(ApiRouter $router): void
    {
        $router->addRoute('GET', '/api/v1/members/export', static function (array $ctx): array {
            if ($ctx['role'] === 'Rédacteur' || !$ctx['is_authenticated']) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé : consultation des adhérents interdite.',
                    'data'    => null,
                ];
            }
            $query = $ctx['query'];
            $filters = [
                'status'        => (string) ($query['status'] ?? 'all'),
                'age_group'     => (string) ($query['age_group'] ?? ''),
                'health_status' => (string) ($query['health_status'] ?? ''),
            ];
            return (new MemberController())->exportMembers(
                (int) $ctx['user_id'],
                (string) $ctx['role'],
                $ctx['permissions'],
                $filters
            );
        });

        $router->addRoute('POST', '/api/v1/members/bulk-resend-otp', static function (array $ctx): array {
            if (!in_array('members.edit', $ctx['permissions'], true) && !in_array('finances.manage', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé : permissions insuffisantes.',
                    'data'    => null,
                ];
            }
            $memberIds = (array) ($ctx['payload']['member_ids'] ?? []);
            return (new MemberController())->bulkResendOtp($memberIds, (int) $ctx['user_id']);
        });

        $router->addRoute('GET', '/api/v1/members', static function (array $ctx): array {
            if ($ctx['role'] === 'Rédacteur' || !$ctx['is_authenticated']) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new MemberController())->listMembers((int) $ctx['user_id'], (string) $ctx['role']);
        });

        $router->addRoute('POST', '/api/v1/members', static function (array $ctx): array {
            if (!in_array('members.edit', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new MemberController())->createMember($ctx['payload'], (int) $ctx['user_id']);
        });

        $router->addRoute(['PUT', 'PATCH'], '#^/api/v1/members/(\d+)$#', static function (array $ctx, array $m): array {
            if (!in_array('members.edit', $ctx['permissions'], true) && $ctx['role'] !== 'Adhérent') {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new MemberController())->updateMember((int) $m[1], $ctx['payload'], (int) $ctx['user_id']);
        });

        $router->addRoute('DELETE', '#^/api/v1/members/(\d+)$#', static function (array $ctx, array $m): array {
            if (!in_array('members.edit', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new MemberController())->deleteMember((int) $m[1], (int) $ctx['user_id']);
        });

        $router->addRoute('POST', '#^/api/v1/members/(\d+)/resend-otp$#', static function (array $ctx, array $m): array {
            if (!in_array('members.edit', $ctx['permissions'], true) && !in_array('finances.manage', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new MemberController())->resendMemberOtp((int) $m[1], (int) $ctx['user_id']);
        });

        $router->addRoute('POST', '#^/api/v1/members/(\d+)/medical-file$#', static function (array $ctx, array $m): array {
            return (new MemberController())->uploadMedicalFile((int) $m[1], $ctx['files']['file'] ?? []);
        });

        $router->addRoute('GET', '#^/api/v1/members/(\d+)/medical-file$#', static function (array $ctx, array $m): array {
            if (!in_array('members.view', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new MemberController())->getMedicalFileMetadata((int) $m[1]);
        });

        $router->addRoute('POST', '#^/api/v1/members/(\d+)/radiate$#', static function (array $ctx, array $m): array {
            if (!in_array('members.edit', $ctx['permissions'], true) && !in_array('finances.manage', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new RegistrationController())->radiateMember((int) $m[1], (int) $ctx['user_id']);
        });

        $router->addRoute('PATCH', '#^/api/v1/members/(\d+)/share-tutor-info$#', static function (array $ctx, array $m): array {
            $payload = $ctx['payload'];
            $shared = isset($payload['info_shared']) ? (bool) $payload['info_shared'] : true;
            return (new RegistrationController())->shareTutorInfo(
                (int) $m[1],
                (int) ($payload['tutor_id'] ?? $ctx['user_id']),
                $shared
            );
        });
    }

    /**
     * 3. INSCRIPTIONS PUBLIQUES & TRÉSORERIE
     */
    private static function registerRegistrationRoutes(ApiRouter $router): void
    {
        $router->addRoute('GET', '/api/v1/registrations', static function (array $ctx): array {
            if (!in_array('finances.manage', $ctx['permissions'], true) && !in_array('members.view', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new RegistrationController())->listRegistrations();
        });

        $router->addRoute('POST', '/api/v1/registrations', static function (array $ctx): array {
            return (new RegistrationController())->submitPublicRegistration($ctx['payload']);
        });

        $router->addRoute('PATCH', '#^/api/v1/registrations/(\d+)/validate$#', static function (array $ctx, array $m): array {
            if (!in_array('finances.manage', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé : vous ne disposez pas des droits de gestion des cotisations.',
                    'data'    => null,
                ];
            }
            return (new RegistrationController())->validateRegistration((int) $m[1], (int) $ctx['user_id'], $ctx['payload']);
        });

        $router->addRoute('PATCH', '#^/api/v1/registrations/(\d+)/status$#', static function (array $ctx, array $m): array {
            if (!in_array('finances.manage', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            $newStatus = (string) ($ctx['payload']['status'] ?? 'pending');
            $db = Database::getInstance();
            $db->prepare('UPDATE registrations SET status = :st WHERE id = :id')->execute([
                'st' => $newStatus,
                'id' => (int) $m[1],
            ]);
            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => $newStatus === 'radiated' ? 'Dossier radié (archivage réversible).' : 'Dossier restauré en attente.',
                'data'    => ['id' => (int) $m[1], 'status' => $newStatus],
            ];
        });

        $router->addRoute('DELETE', '#^/api/v1/registrations/(\d+)$#', static function (array $ctx, array $m): array {
            if (!in_array('finances.manage', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            $db = Database::getInstance();
            $db->prepare('DELETE FROM registrations WHERE id = :id')->execute(['id' => (int) $m[1]]);
            return ['status' => 'success', 'code' => 200, 'message' => 'Dossier supprimé définitivement.', 'data' => null];
        });

        $router->addRoute('GET', '#^/api/v1/registrations/(\d+)/receipt$#', static function (array $ctx, array $m): array {
            return (new RegistrationController())->getReceipt((int) $m[1], (int) $ctx['user_id'], $ctx['permissions']);
        });

        $router->addRoute('GET', '#^/api/v1/registrations/(\d+)/cse-attestation$#', static function (array $ctx, array $m): array {
            return (new RegistrationController())->getCseAttestation((int) $m[1], (int) $ctx['user_id'], $ctx['permissions']);
        });

        $router->addRoute('POST', '/api/v1/auth/activate-otp', static function (array $ctx): array {
            return (new RegistrationController())->activateAccountWithOtp($ctx['payload']);
        });
    }

    /**
     * 4. CMS : ARTICLES, MÉDIAS & PAGES LÉGALES
     */
    private static function registerCmsRoutes(ApiRouter $router): void
    {
        $router->addRoute('GET', '/api/v1/articles', static function (array $ctx): array {
            return (new CmsController())->listArticles(
                $ctx['is_authenticated'] ? (string) $ctx['role'] : null,
                $ctx['permissions']
            );
        });

        $router->addRoute('GET', '#^/api/v1/articles/(\d+)$#', static function (array $ctx, array $m): array {
            return (new CmsController())->getArticle(
                (int) $m[1],
                $ctx['is_authenticated'] ? (string) $ctx['role'] : null,
                $ctx['permissions']
            );
        });

        $router->addRoute('POST', '/api/v1/articles', static function (array $ctx): array {
            if (!in_array('articles.write', $ctx['permissions'], true) && !in_array('articles.publish', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé : vous ne disposez pas des droits de rédaction d\'articles.',
                    'data'    => null,
                ];
            }
            return (new CmsController())->createArticle($ctx['payload'], (int) $ctx['user_id'], $ctx['permissions']);
        });

        $router->addRoute('PUT', '#^/api/v1/articles/(\d+)$#', static function (array $ctx, array $m): array {
            if (!in_array('articles.write', $ctx['permissions'], true) && !in_array('articles.publish', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new CmsController())->updateArticle((int) $m[1], $ctx['payload'], $ctx['permissions'], (int) $ctx['user_id']);
        });

        $router->addRoute('DELETE', '#^/api/v1/articles/(\d+)$#', static function (array $ctx, array $m): array {
            if (!in_array('articles.publish', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé : vous ne disposez pas des droits de publication d\'articles.',
                    'data'    => null,
                ];
            }
            return (new CmsController())->deleteArticle((int) $m[1], (int) $ctx['user_id']);
        });

        $router->addRoute('PATCH', '#^/api/v1/articles/(\d+)/status$#', static function (array $ctx, array $m): array {
            if (!in_array('articles.publish', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé : vous ne disposez pas des droits de publication d\'articles.',
                    'data'    => null,
                ];
            }
            return (new CmsController())->updateArticleStatus((int) $m[1], $ctx['payload'], (int) $ctx['user_id']);
        });

        $router->addRoute('POST', '/api/v1/articles/media', static function (array $ctx): array {
            if (!in_array('articles.write', $ctx['permissions'], true) && !in_array('articles.publish', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new CmsController())->uploadArticleMedia($ctx['files']['file'] ?? []);
        });

        $router->addRoute('POST', '/api/v1/articles/media/cleanup', static function (array $ctx): array {
            if (!in_array('articles.publish', $ctx['permissions'], true) && !$ctx['is_superadmin']) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé : vous ne disposez pas des droits de gestion de la médiathèque.',
                    'data'    => null,
                ];
            }
            return (new CmsController())->cleanupOrphanMedia();
        });

        $router->addRoute('GET', '/api/v1/pages', static function (): array {
            return (new CmsController())->listPages();
        });

        $router->addRoute('GET', '#^/api/v1/pages/([a-z0-9\-]+)$#', static function (array $ctx, array $m): array {
            $canManagePages = in_array('pages.manage', $ctx['permissions'], true) || (bool) $ctx['is_superadmin'];
            return (new CmsController())->getPageBySlug($m[1], $canManagePages);
        });

        $router->addRoute('PUT', '#^/api/v1/pages/([a-z0-9\-]+)$#', static function (array $ctx, array $m): array {
            if (!in_array('pages.manage', $ctx['permissions'], true) && !$ctx['is_superadmin']) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new CmsController())->savePage($m[1], $ctx['payload']);
        });
    }

    /**
     * 5. RÔLES & DISCIPLINES
     */
    private static function registerRoleAndDisciplineRoutes(ApiRouter $router): void
    {
        $router->addRoute('GET', '/api/v1/roles', static function (): array {
            return (new RoleController())->listRoles();
        });

        $router->addRoute('POST', '/api/v1/roles', static function (array $ctx): array {
            if (!in_array('roles.manage', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new RoleController())->createRole($ctx['payload']);
        });

        $router->addRoute('PATCH', '#^/api/v1/roles/(\d+)(?:/permissions)?$#', static function (array $ctx, array $m): array {
            $payload = $ctx['payload'];
            $newName = isset($payload['name']) ? (string) $payload['name'] : null;
            return (new RoleController())->updatePermissions((int) $m[1], $payload['permissions'] ?? [], $newName);
        });

        $router->addRoute('DELETE', '#^/api/v1/roles/(\d+)$#', static function (array $ctx, array $m): array {
            if (!in_array('roles.manage', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
            }
            return (new RoleController())->deleteRole((int) $m[1]);
        });

        $router->addRoute('GET', '/api/v1/disciplines', static function (): array {
            return (new DisciplineController())->listDisciplines();
        });

        $router->addRoute('POST', '/api/v1/disciplines', static function (array $ctx): array {
            return (new DisciplineController())->createDiscipline($ctx['payload']);
        });

        $router->addRoute(['PUT', 'PATCH'], '#^/api/v1/disciplines/(\d+)$#', static function (array $ctx, array $m): array {
            return (new DisciplineController())->updateDiscipline((int) $m[1], $ctx['payload']);
        });

        $router->addRoute('DELETE', '#^/api/v1/disciplines/(\d+)$#', static function (array $ctx, array $m): array {
            $db = Database::getInstance();
            $db->prepare('DELETE FROM levels WHERE discipline_id = :id')->execute(['id' => (int) $m[1]]);
            $db->prepare('DELETE FROM disciplines WHERE id = :id')->execute(['id' => (int) $m[1]]);
            return ['status' => 'success', 'code' => 200, 'message' => 'Discipline supprimée.', 'data' => null];
        });
    }

    /**
     * 5b. SYSTÈME, LOGS, PARAMÈTRES, SAUVEGARDES & TEMPLATES EMAIL
     */
    private static function registerSystemRoutes(ApiRouter $router): void
    {
        $router->addRoute('GET', '/api/v1/sys/logs', static function (array $ctx): array {
            if (!$ctx['is_superadmin'] && !in_array('logs.view', $ctx['permissions'], true)) {
                return ['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin et Président.', 'data' => null];
            }
            return (new SystemController())->getSysLogs();
        });

        $router->addRoute('GET', '/api/v1/sys/email-logs', static function (array $ctx): array {
            if (!$ctx['is_superadmin']) {
                return ['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null];
            }
            return (new SystemController())->getEmailLogs();
        });

        $router->addRoute('POST', '/api/v1/sys/test-email', static function (array $ctx): array {
            if (!$ctx['is_superadmin']) {
                return ['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null];
            }
            return (new SystemController())->sendTrackedEmail($ctx['payload']);
        });

        $router->addRoute('POST', '/api/v1/sys/branding-upload', static function (array $ctx): array {
            if (!$ctx['is_superadmin']) {
                return ['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null];
            }
            $type = (string) ($ctx['post']['type'] ?? 'logo');
            return (new SystemController())->uploadBrandingAsset($type, $ctx['files']['file'] ?? []);
        });

        $router->addRoute('GET', '/api/v1/sys/superadmins', static function (array $ctx): array {
            if (!$ctx['is_superadmin']) {
                return ['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null];
            }
            return (new SystemController())->getSuperadmins();
        });

        $router->addRoute('POST', '/api/v1/sys/superadmins', static function (array $ctx): array {
            if (!$ctx['is_superadmin']) {
                return ['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null];
            }
            return (new SystemController())->createSuperadmin($ctx['payload'], (int) $ctx['user_id']);
        });

        $router->addRoute('PATCH', '/api/v1/sys/password', static function (array $ctx): array {
            if (!$ctx['is_superadmin']) {
                return ['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null];
            }
            $uid = (int) $ctx['user_id'];
            return (new SystemController())->updateSuperadminPassword($ctx['payload'], $uid > 0 ? $uid : 1);
        });

        foreach (['/api/v1/app-logs', '/api/v1/app_logs'] as $appLogsPath) {
            $router->addRoute('GET', $appLogsPath, static function (array $ctx): array {
                if (!in_array('roles.manage', $ctx['permissions'], true) && !in_array('logs.view', $ctx['permissions'], true) && !$ctx['is_superadmin']) {
                    return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
                }
                return (new SystemController())->getAppLogs();
            });
        }

        foreach (['/api/v1/settings', '/api/v1/sys/settings'] as $settingsPath) {
            $router->addRoute('GET', $settingsPath, static function (): array {
                return (new SystemController())->getSettings();
            });

            $router->addRoute(['PATCH', 'PUT'], $settingsPath, static function (array $ctx): array {
                if (!in_array('settings.manage', $ctx['permissions'], true) && !$ctx['is_superadmin']) {
                    return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null];
                }
                return (new SystemController())->updateSettings($ctx['payload'], (int) $ctx['user_id'], (bool) $ctx['is_superadmin']);
            });
        }

        foreach (['/api/v1/sys/metrics', '/api/v1/sys/stats'] as $metricsPath) {
            $router->addRoute('GET', $metricsPath, static function (array $ctx): array {
                if (!in_array('system.admin', $ctx['permissions'], true) && !$ctx['is_superadmin']) {
                    return ['status' => 'error', 'code' => 403, 'message' => 'Accès restreint au Superadmin.', 'data' => null];
                }
                return (new SystemController())->getSystemMetrics();
            });
        }

        foreach (['/api/v1/sys/backup', '/api/v1/sys/database/backup'] as $backupPath) {
            $router->addRoute('POST', $backupPath, static function (array $ctx): array {
                if (!in_array('system.admin', $ctx['permissions'], true) && !$ctx['is_superadmin']) {
                    return ['status' => 'error', 'code' => 403, 'message' => 'Accès restreint au Superadmin.', 'data' => null];
                }
                return (new SystemController())->backupDatabase((int) $ctx['user_id']);
            });
        }

        foreach (['/api/v1/sys/email-templates', '/api/v1/email-templates'] as $tplPath) {
            $router->addRoute('GET', $tplPath, static function (array $ctx): array {
                if (!in_array('system.admin', $ctx['permissions'], true) && !in_array('settings.manage', $ctx['permissions'], true) && !$ctx['is_superadmin']) {
                    return ['status' => 'error', 'code' => 403, 'message' => 'Accès restreint aux paramètres système.', 'data' => null];
                }
                return (new SystemController())->getEmailTemplates();
            });
        }

        $router->addRoute(['PUT', 'PATCH'], '#^/api/v1/sys/email-templates/([^/]+)$#', static function (array $ctx, array $matches): array {
            if (!in_array('system.admin', $ctx['permissions'], true) && !in_array('settings.manage', $ctx['permissions'], true) && !$ctx['is_superadmin']) {
                return ['status' => 'error', 'code' => 403, 'message' => 'Accès restreint aux paramètres système.', 'data' => null];
            }
            $key = urldecode($matches[1]);
            return (new SystemController())->updateEmailTemplate($key, $ctx['payload'], (int) $ctx['user_id']);
        });
    }

    /**
     * 6. TÂCHES SYSTÈME (ROLLOVER) & 7. STATISTIQUES AVANCÉES
     */
    private static function registerTaskAndStatsRoutes(ApiRouter $router): void
    {
        foreach (['/api/v1/tasks/rollover-season', '/api/v1/tasks/rollover', '/api/v1/rollover'] as $rolloverPath) {
            $router->addRoute('POST', $rolloverPath, static function (array $ctx): array {
                if (!$ctx['is_superadmin'] && $ctx['role'] !== 'Président') {
                    ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                    return [
                        'status'  => 'error',
                        'code'    => 403,
                        'message' => 'Action de basculement de saison strictement réservée au Président ou Superadmin.',
                        'data'    => null,
                    ];
                }
                return (new RolloverService())->rollover($ctx['payload'], (int) $ctx['user_id'], (bool) $ctx['is_superadmin']);
            });
        }

        $router->addRoute('GET', '/api/v1/stats/kpis', static function (array $ctx): array {
            if (!$ctx['is_authenticated'] || (!in_array('stats.view', $ctx['permissions'], true) && !in_array('finances.manage', $ctx['permissions'], true) && !$ctx['is_superadmin'])) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé aux statistiques.',
                    'data'    => null,
                ];
            }
            return (new SystemController())->getKpis((int) $ctx['user_id'], (string) $ctx['role'], (bool) $ctx['is_superadmin']);
        });

        $router->addRoute('GET', '/api/v1/stats/demographics', static function (array $ctx): array {
            if (!$ctx['is_authenticated'] || (!in_array('stats.view', $ctx['permissions'], true) && !$ctx['is_superadmin'])) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès refusé aux données démographiques.',
                    'data'    => null,
                ];
            }
            return (new SystemController())->getDemographics((int) $ctx['user_id'], (string) $ctx['role'], (bool) $ctx['is_superadmin']);
        });
    }

    /**
     * 8. MOTEUR DE MODULES & EXTENSIONS SDK (US 6.1 - US 6.3)
     */
    private static function registerModuleRoutes(ApiRouter $router): void
    {
        $router->addRoute('GET', '/api/v1/sys/modules', static function (array $ctx): array {
            if (!$ctx['is_superadmin'] && $ctx['role'] !== 'Président' && !in_array('system.admin', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès restreint au Président ou Superadmin.',
                    'data'    => null,
                ];
            }
            $modules = (new ModuleManager())->scanAndList();
            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Modules scannés et synchronisés.',
                'data'    => $modules,
            ];
        });

        $router->addRoute('POST', '/api/v1/sys/modules/scan', static function (array $ctx): array {
            if (!$ctx['is_superadmin'] && $ctx['role'] !== 'Président' && !in_array('system.admin', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Accès restreint au Président ou Superadmin.',
                    'data'    => null,
                ];
            }
            $modules = (new ModuleManager())->scanAndList();
            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Scan des modules actualisé avec succès.',
                'data'    => $modules,
            ];
        });

        $router->addRoute('PATCH', '#^/api/v1/sys/modules/([^/]+)/toggle$#', static function (array $ctx, array $matches): array {
            if (!$ctx['is_superadmin'] && $ctx['role'] !== 'Président' && !in_array('system.admin', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Action sur les modules réservée au Président ou Superadmin.',
                    'data'    => null,
                ];
            }
            $targetIdOrFolder = urldecode($matches[1]);
            $enabled = (bool) ($ctx['payload']['enabled'] ?? true);
            return (new ModuleManager())->toggle($targetIdOrFolder, $enabled, (int) $ctx['user_id'], (bool) $ctx['is_superadmin']);
        });

        $uninstallHandler = static function (array $ctx, array $matches): array {
            if (!$ctx['is_superadmin'] && $ctx['role'] !== 'Président' && !in_array('system.admin', $ctx['permissions'], true)) {
                ApiKernel::logUnauthorizedAttempt((string) $ctx['uri']);
                return [
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Désinstallation des modules réservée au Président ou Superadmin.',
                    'data'    => null,
                ];
            }
            $targetIdOrFolder = urldecode($matches[1]);
            return (new ModuleManager())->uninstall($targetIdOrFolder, (int) $ctx['user_id'], (bool) $ctx['is_superadmin']);
        };

        $router->addRoute('POST', '#^/api/v1/sys/modules/([^/]+)/uninstall$#', $uninstallHandler);
        $router->addRoute('DELETE', '#^/api/v1/sys/modules/([^/]+)$#', $uninstallHandler);
    }
}
