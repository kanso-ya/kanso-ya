<?php

declare(strict_types=1);

/**
 * Point d'entrée unique de l'API REST du CMS Kanso-ya.
 * Garantit un retour JSON JSend HTTP 200 en toutes circonstances.
 */

ob_start();
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Envoie une réponse JSON normalisée JSend (HTTP 200 constant).
 *
 * @param array<string, mixed> $response Tableau contenant code, message, data.
 * @return never
 */
function sendJson(array $response): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    $code = isset($response['code']) ? (int) $response['code'] : 200;
    $status = $response['status'] ?? ($code >= 200 && $code < 400 ? 'success' : 'error');

    $normalized = [
        'status'  => $status,
        'success' => $status === 'success',
        'code'    => $code,
        'message' => (string) ($response['message'] ?? ''),
        'data'    => $response['data'] ?? null,
    ];

    echo json_encode($normalized, JSON_UNESCAPED_UNICODE);
    exit;
}

// Intercepteur d'erreur fatale garantissant qu'aucune réponse vide (Unexpected end of JSON input) ne sorte jamais
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'success' => false,
            'code'    => 500,
            'message' => 'Fatal Error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'],
            'data'    => null
        ], JSON_UNESCAPED_UNICODE);
    }
});

try {
    require_once __DIR__ . '/../core/Api/Database/Database.php';
    require_once __DIR__ . '/../core/Api/Endpoints/AuthController.php';
    require_once __DIR__ . '/../core/Api/Endpoints/MemberController.php';
    require_once __DIR__ . '/../core/Api/Endpoints/RoleController.php';
    require_once __DIR__ . '/../core/Api/Endpoints/DisciplineController.php';
    require_once __DIR__ . '/../core/Api/Endpoints/SystemController.php';
    require_once __DIR__ . '/../core/Api/Endpoints/RegistrationController.php';
    require_once __DIR__ . '/../core/Api/Endpoints/CmsController.php';
    require_once __DIR__ . '/../core/Sdk/EventManager.php';
    require_once __DIR__ . '/../core/Sdk/ModuleInterface.php';
    require_once __DIR__ . '/../core/Api/Services/ModuleScanner.php';
    require_once __DIR__ . '/../core/Api/Services/ModuleManager.php';

    // Initialisation dynamique des modules actifs avec Safe Mode
    \Core\Api\Services\ModuleManager::boot();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $rawInput = file_get_contents('php://input');
    $payload = is_string($rawInput) && $rawInput !== '' ? (json_decode($rawInput, true) ?? []) : [];

    $logUnauthorizedAttempt = static function (string $targetUri): void {
        try {
            $db = \Core\Api\Database\Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                VALUES (NULL, 'UNAUTHORIZED_ACCESS_ATTEMPT', :det, :ip)
            ");
            $stmt->execute([
                'det' => 'Route refusée (403) : ' . $targetUri,
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Throwable) {
        }
    };

    if ($method === 'POST' && $uri === '/api/v1/auth/switch-persona') {
        $persona = (string) ($payload['persona'] ?? 'president');
        $_SESSION['is_superadmin'] = false;
        unset($_SESSION['sys_admin_id']);

        if ($persona === 'visitor') {
            $_SESSION['user_id'] = 0;
            $_SESSION['role_name'] = 'Visiteur';
        } elseif ($persona === 'tutorA' || $persona === 'adherent') {
            $_SESSION['user_id'] = 10;
            $_SESSION['role_name'] = 'Adhérent';
        } elseif ($persona === 'tutorB') {
            $_SESSION['user_id'] = 2;
            $_SESSION['role_name'] = 'Adhérent';
        } elseif ($persona === 'redacteur') {
            $_SESSION['user_id'] = 3;
            $_SESSION['role_name'] = 'Rédacteur';
        } elseif ($persona === 'entraineur') {
            $_SESSION['user_id'] = 2;
            $_SESSION['role_name'] = 'Entraîneur';
        } elseif ($persona === 'secretaire') {
            $_SESSION['user_id'] = 5;
            $_SESSION['role_name'] = 'Secrétaire';
        } elseif ($persona === 'tresorier') {
            $_SESSION['user_id'] = 4;
            $_SESSION['role_name'] = 'Trésorier';
        } elseif ($persona === 'superadmin') {
            $_SESSION['user_id'] = 1;
            $_SESSION['role_name'] = 'Superadmin';
            $_SESSION['is_superadmin'] = true;
        } else {
            $_SESSION['user_id'] = 1;
            $_SESSION['role_name'] = 'Président';
        }
        session_write_close();
        sendJson([
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Compte actif : ' . $_SESSION['role_name'],
            'data'    => ['role' => $_SESSION['role_name']]
        ]);
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $currentUserId = (int) ($_SESSION['user_id'] ?? 1);
    $currentRole = (string) ($_SESSION['role_name'] ?? 'Président');
    $isSuperadmin = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));
    $isAuthenticated = ($currentRole !== 'Visiteur' && $currentRole !== '' && $currentUserId > 0);

    if (!str_contains($uri, 'login') && !str_contains($uri, 'logout')) {
        session_write_close();
    }

    if (str_contains($authHeader, 'stub-tutor-a-token')) {
        $currentUserId = 10;
        $currentRole = 'Adhérent';
        $isAuthenticated = true;
    } elseif (str_contains($authHeader, 'stub-tutor-b-token')) {
        $currentUserId = 2;
        $currentRole = 'Adhérent';
        $isAuthenticated = true;
    } elseif (str_contains($authHeader, 'stub-redacteur-token')) {
        $currentUserId = 3;
        $currentRole = 'Rédacteur';
        $isAuthenticated = true;
    } elseif (str_contains($authHeader, 'stub-entraineur-token')) {
        $currentUserId = 2;
        $currentRole = 'Entraîneur';
        $isAuthenticated = true;
    } elseif (str_contains($authHeader, 'stub-tresorier-token')) {
        $currentUserId = 4;
        $currentRole = 'Trésorier';
        $isAuthenticated = true;
    } elseif (str_contains($authHeader, 'stub-superadmin-token')) {
        $currentUserId = 1;
        $currentRole = 'Superadmin';
        $isSuperadmin = true;
        $isAuthenticated = true;
    } elseif (str_contains($authHeader, 'stub-visitor-token')) {
        $currentUserId = 0;
        $currentRole = '';
        $isAuthenticated = false;
    }

    $permissions = [];
    if ($isSuperadmin) {
        $permissions = ['system.admin', 'settings.manage', 'logs.view'];
    } elseif ($currentRole === 'Président') {
        $permissions = [
            'members.view', 'members.edit', 'roles.manage',
            'finances.manage', 'articles.write', 'articles.publish',
            'pages.manage', 'settings.manage', 'settings.view', 'logs.view', 'stats.view'
        ];
    } elseif ($currentRole === 'Secrétaire') {
        $permissions = ['members.view', 'members.edit', 'articles.write', 'articles.publish', 'stats.view', 'settings.manage'];
    } elseif ($currentRole === 'Trésorier') {
        $permissions = ['members.view', 'finances.manage', 'settings.manage'];
    } elseif ($currentRole === 'Rédacteur') {
        $permissions = ['articles.write'];
    } elseif ($currentRole === 'Entraîneur') {
        $permissions = ['members.view', 'articles.write', 'stats.view'];
    } elseif ($currentRole === 'Adhérent') {
        $permissions = ['profile.view'];
    }

    // 1. AUTHENTIFICATION, PROFIL & 2FA
    if ($method === 'POST' && $uri === '/api/v1/auth/login') {
        $controller = new \Core\Api\Endpoints\AuthController();
        sendJson($controller->login($payload));
    }

    if ($method === 'POST' && in_array($uri, ['/api/v1/sys/auth/login', '/api/v1/sys/login', '/api/v1/auth/sys-login'], true)) {
        $controller = new \Core\Api\Endpoints\AuthController();
        sendJson($controller->sysLogin($payload));
    }

    if ($method === 'POST' && $uri === '/api/v1/auth/logout') {
        $controller = new \Core\Api\Endpoints\AuthController();
        sendJson($controller->logout());
    }

    if ($method === 'GET' && $uri === '/api/v1/auth/me') {
        sendJson([
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Contexte utilisateur récupéré',
            'data'    => [
                'user_id'     => $currentUserId,
                'role'        => $isAuthenticated ? $currentRole : 'Visiteur',
                'permissions' => $permissions
            ]
        ]);
    }

    if ($method === 'GET' && $uri === '/api/v1/users/me/profile') {
        $controller = new \Core\Api\Endpoints\AuthController();
        sendJson($controller->getMyProfile($currentUserId));
    }

    if ($method === 'PUT' && $uri === '/api/v1/users/me/profile') {
        $controller = new \Core\Api\Endpoints\AuthController();
        sendJson($controller->updateMyProfile($currentUserId, $payload));
    }

    if ($method === 'POST' && $uri === '/api/v1/users/me/2fa') {
        $controller = new \Core\Api\Endpoints\AuthController();
        sendJson($controller->toggleTwoFactor($currentUserId, $payload));
    }

    if ($method === 'PATCH' && $uri === '/api/v1/users/me/password') {
        $controller = new \Core\Api\Endpoints\AuthController();
        sendJson($controller->updatePassword($currentUserId, $payload));
    }

    // 2. CRM ADHÉRENTS, RATTACHEMENTS & RENVOI OTP
    if ($method === 'GET' && $uri === '/api/v1/members') {
        if ($currentRole === 'Rédacteur' || !$isAuthenticated) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\MemberController();
        sendJson($controller->listMembers($currentUserId, $currentRole));
    }

    if ($method === 'POST' && $uri === '/api/v1/members') {
        if (!in_array('members.edit', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\MemberController();
        sendJson($controller->createMember($payload, $currentUserId));
    }

    if (in_array($method, ['PUT', 'PATCH'], true) && preg_match('#^/api/v1/members/(\d+)$#', $uri, $m)) {
        if (!in_array('members.edit', $permissions, true) && $currentRole !== 'Adhérent') {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\MemberController();
        sendJson($controller->updateMember((int) $m[1], $payload, $currentUserId));
    }

    if ($method === 'DELETE' && preg_match('#^/api/v1/members/(\d+)$#', $uri, $m)) {
        if (!in_array('members.edit', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\MemberController();
        sendJson($controller->deleteMember((int) $m[1], $currentUserId));
    }

    if ($method === 'POST' && preg_match('#^/api/v1/members/(\d+)/resend-otp$#', $uri, $m)) {
        if (!in_array('members.edit', $permissions, true) && !in_array('finances.manage', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\MemberController();
        sendJson($controller->resendMemberOtp((int) $m[1], $currentUserId));
    }

    if ($method === 'POST' && preg_match('#^/api/v1/members/(\d+)/medical-file$#', $uri, $m)) {
        $controller = new \Core\Api\Endpoints\MemberController();
        sendJson($controller->uploadMedicalFile((int) $m[1], $_FILES['file'] ?? []));
    }

    if ($method === 'GET' && preg_match('#^/api/v1/members/(\d+)/medical-file$#', $uri, $m)) {
        if (!in_array('members.view', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\MemberController();
        sendJson($controller->getMedicalFileMetadata((int) $m[1]));
    }

    if ($method === 'POST' && preg_match('#^/api/v1/members/(\d+)/radiate$#', $uri, $m)) {
        if (!in_array('members.edit', $permissions, true) && !in_array('finances.manage', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\RegistrationController();
        sendJson($controller->radiateMember((int) $m[1], $currentUserId));
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/members/(\d+)/share-tutor-info$#', $uri, $m)) {
        $controller = new \Core\Api\Endpoints\RegistrationController();
        $shared = isset($payload['info_shared']) ? (bool) $payload['info_shared'] : true;
        sendJson($controller->shareTutorInfo((int) $m[1], (int) ($payload['tutor_id'] ?? $currentUserId), $shared));
    }

    // 3. INSCRIPTIONS PUBLIQUES & TRÉSORERIE
    if ($method === 'GET' && $uri === '/api/v1/registrations') {
        if (!in_array('finances.manage', $permissions, true) && !in_array('members.view', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\RegistrationController();
        sendJson($controller->listRegistrations());
    }

    if ($method === 'POST' && $uri === '/api/v1/registrations') {
        $controller = new \Core\Api\Endpoints\RegistrationController();
        sendJson($controller->submitPublicRegistration($payload));
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/registrations/(\d+)/validate$#', $uri, $m)) {
        if (!in_array('finances.manage', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\RegistrationController();
        sendJson($controller->validateRegistration((int) $m[1], $currentUserId, $payload));
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/registrations/(\d+)/status$#', $uri, $m)) {
        if (!in_array('finances.manage', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $newStatus = (string) ($payload['status'] ?? 'pending');
        $db = \Core\Api\Database\Database::getInstance();
        $db->prepare("UPDATE registrations SET status = :st WHERE id = :id")->execute([
            'st' => $newStatus,
            'id' => (int) $m[1]
        ]);
        sendJson([
            'status'  => 'success',
            'code'    => 200,
            'message' => $newStatus === 'radiated' ? 'Dossier radié (archivage réversible).' : 'Dossier restauré en attente.',
            'data'    => ['id' => (int) $m[1], 'status' => $newStatus]
        ]);
    }

    if ($method === 'DELETE' && preg_match('#^/api/v1/registrations/(\d+)$#', $uri, $m)) {
        if (!in_array('finances.manage', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $db = \Core\Api\Database\Database::getInstance();
        $db->prepare("DELETE FROM registrations WHERE id = :id")->execute(['id' => (int) $m[1]]);
        sendJson(['status' => 'success', 'code' => 200, 'message' => 'Dossier supprimé définitivement.', 'data' => null]);
    }

    if ($method === 'POST' && $uri === '/api/v1/auth/activate-otp') {
        $controller = new \Core\Api\Endpoints\RegistrationController();
        sendJson($controller->activateAccountWithOtp($payload));
    }

    // 4. CMS : ARTICLES, MÉDIAS & PAGES LÉGALES
    if ($method === 'GET' && $uri === '/api/v1/articles') {
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->listArticles($isAuthenticated ? $currentRole : null, $permissions));
    }

    if ($method === 'GET' && preg_match('#^/api/v1/articles/(\d+)$#', $uri, $m)) {
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->getArticle((int) $m[1], $isAuthenticated ? $currentRole : null, $permissions));
    }

    if ($method === 'POST' && $uri === '/api/v1/articles') {
        if (!in_array('articles.write', $permissions, true) && !in_array('articles.publish', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit articles.write requis.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->createArticle($payload, $currentUserId, $permissions));
    }

    if ($method === 'PUT' && preg_match('#^/api/v1/articles/(\d+)$#', $uri, $m)) {
        if (!in_array('articles.write', $permissions, true) && !in_array('articles.publish', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->updateArticle((int) $m[1], $payload, $permissions));
    }

    if ($method === 'DELETE' && preg_match('#^/api/v1/articles/(\d+)$#', $uri, $m)) {
        if (!in_array('articles.publish', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit articles.publish requis.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->deleteArticle((int) $m[1], $currentUserId));
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/articles/(\d+)/status$#', $uri, $m)) {
        if (!in_array('articles.publish', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit articles.publish requis.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->updateArticleStatus((int) $m[1], $payload, $currentUserId));
    }

    if ($method === 'POST' && $uri === '/api/v1/articles/media') {
        if (!in_array('articles.write', $permissions, true) && !in_array('articles.publish', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->uploadArticleMedia($_FILES['file'] ?? []));
    }

    if ($method === 'GET' && $uri === '/api/v1/pages') {
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->listPages());
    }

    if ($method === 'GET' && preg_match('#^/api/v1/pages/([a-z0-9\-]+)$#', $uri, $m)) {
        $canManagePages = in_array('pages.manage', $permissions, true) || $isSuperadmin;
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->getPageBySlug($m[1], $canManagePages));
    }

    if ($method === 'PUT' && preg_match('#^/api/v1/pages/([a-z0-9\-]+)$#', $uri, $m)) {
        if (!in_array('pages.manage', $permissions, true) && !$isSuperadmin) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\CmsController();
        sendJson($controller->savePage($m[1], $payload));
    }

    // 5. RÔLES, DISCIPLINES & SYSTÈME
    if ($method === 'GET' && $uri === '/api/v1/roles') {
        $controller = new \Core\Api\Endpoints\RoleController();
        sendJson($controller->listRoles());
    }

    if ($method === 'POST' && $uri === '/api/v1/roles') {
        if (!in_array('roles.manage', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\RoleController();
        sendJson($controller->createRole($payload));
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/roles/(\d+)(?:/permissions)?$#', $uri, $m)) {
        $controller = new \Core\Api\Endpoints\RoleController();
        $newName = isset($payload['name']) ? (string) $payload['name'] : null;
        sendJson($controller->updatePermissions((int) $m[1], $payload['permissions'] ?? [], $newName));
    }

    if ($method === 'DELETE' && preg_match('#^/api/v1/roles/(\d+)$#', $uri, $m)) {
        if (!in_array('roles.manage', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\RoleController();
        sendJson($controller->deleteRole((int) $m[1]));
    }

    if ($method === 'GET' && $uri === '/api/v1/disciplines') {
        $controller = new \Core\Api\Endpoints\DisciplineController();
        sendJson($controller->listDisciplines());
    }

    if ($method === 'POST' && $uri === '/api/v1/disciplines') {
        $controller = new \Core\Api\Endpoints\DisciplineController();
        sendJson($controller->createDiscipline($payload));
    }

    if (in_array($method, ['PUT', 'PATCH'], true) && preg_match('#^/api/v1/disciplines/(\d+)$#', $uri, $m)) {
        $controller = new \Core\Api\Endpoints\DisciplineController();
        sendJson($controller->updateDiscipline((int) $m[1], $payload));
    }

    if ($method === 'DELETE' && preg_match('#^/api/v1/disciplines/(\d+)$#', $uri, $m)) {
        $db = \Core\Api\Database\Database::getInstance();
        $db->prepare("DELETE FROM levels WHERE discipline_id = :id")->execute(['id' => (int) $m[1]]);
        $db->prepare("DELETE FROM disciplines WHERE id = :id")->execute(['id' => (int) $m[1]]);
        sendJson(['status' => 'success', 'code' => 200, 'message' => 'Discipline supprimée.', 'data' => null]);
    }

    if ($method === 'GET' && $uri === '/api/v1/sys/logs') {
        if (!$isSuperadmin && !in_array('logs.view', $permissions, true)) {
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin et Président.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\SystemController();
        sendJson($controller->getSysLogs());
    }

    if ($method === 'GET' && $uri === '/api/v1/sys/email-logs') {
        if (!$isSuperadmin) {
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\SystemController();
        sendJson($controller->getEmailLogs());
    }

    if ($method === 'POST' && $uri === '/api/v1/sys/test-email') {
        if (!$isSuperadmin) {
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\SystemController();
        sendJson($controller->sendTrackedEmail($payload));
    }

    if ($method === 'POST' && $uri === '/api/v1/sys/branding-upload') {
        if (!$isSuperadmin) {
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Réservé au Superadmin.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\SystemController();
        $type = (string) ($_POST['type'] ?? 'logo');
        sendJson($controller->uploadBrandingAsset($type, $_FILES['file'] ?? []));
    }

    if ($method === 'GET' && in_array($uri, ['/api/v1/app-logs', '/api/v1/app_logs'], true)) {
        if (!in_array('roles.manage', $permissions, true) && !in_array('logs.view', $permissions, true) && !$isSuperadmin) {
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\SystemController();
        sendJson($controller->getAppLogs());
    }

    if ($method === 'GET' && in_array($uri, ['/api/v1/settings', '/api/v1/sys/settings'], true)) {
        $controller = new \Core\Api\Endpoints\SystemController();
        sendJson($controller->getSettings());
    }

    if (in_array($method, ['PATCH', 'PUT'], true) && in_array($uri, ['/api/v1/settings', '/api/v1/sys/settings'], true)) {
        if (!in_array('settings.manage', $permissions, true) && !$isSuperadmin) {
            sendJson(['status' => 'error', 'code' => 403, 'message' => 'Accès refusé.', 'data' => null]);
        }
        $controller = new \Core\Api\Endpoints\SystemController();
        sendJson($controller->updateSettings($payload, $currentUserId, $isSuperadmin));
    }

    // 6. TÂCHES SYSTÈME & CHANGEMENT DE SAISON / ROLLOVER (US 5.1)
    if ($method === 'POST' && in_array($uri, ['/api/v1/tasks/rollover-season', '/api/v1/tasks/rollover'], true)) {
        if (!$isSuperadmin && $currentRole !== 'Président') {
            $logUnauthorizedAttempt($uri);
            sendJson([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Action de basculement de saison strictement réservée au Président ou Superadmin.',
                'data'    => null
            ]);
        }
        require_once __DIR__ . '/../core/Api/Services/RolloverService.php';
        $rolloverService = new \Core\Api\Services\RolloverService();
        sendJson($rolloverService->rollover($payload, $currentUserId, $isSuperadmin));
    }

    // 7. STATISTIQUES AVANCÉES, KPIS & DÉMOGRAPHIE (US 5.2)
    if ($method === 'GET' && $uri === '/api/v1/stats/kpis') {
        if (!$isAuthenticated || (!in_array('stats.view', $permissions, true) && !in_array('finances.manage', $permissions, true) && !$isSuperadmin)) {
            $logUnauthorizedAttempt($uri);
            sendJson([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Accès refusé aux statistiques.',
                'data'    => null
            ]);
        }
        $controller = new \Core\Api\Endpoints\SystemController();
        sendJson($controller->getKpis($currentUserId, $currentRole, $isSuperadmin));
    }

    if ($method === 'GET' && $uri === '/api/v1/stats/demographics') {
        if (!$isAuthenticated || (!in_array('stats.view', $permissions, true) && !$isSuperadmin)) {
            $logUnauthorizedAttempt($uri);
            sendJson([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Accès refusé aux données démographiques.',
                'data'    => null
            ]);
        }
        $controller = new \Core\Api\Endpoints\SystemController();
        sendJson($controller->getDemographics($currentUserId, $currentRole, $isSuperadmin));
    }

    // 8. MOTEUR DE MODULES & EXTENSIONS SDK (US 6.1 - US 6.3)
    if ($method === 'GET' && $uri === '/api/v1/sys/modules') {
        if (!$isSuperadmin && $currentRole !== 'Président' && !in_array('system.admin', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Accès restreint au Président ou Superadmin.',
                'data'    => null
            ]);
        }
        $manager = new \Core\Api\Services\ModuleManager();
        $modules = $manager->scanAndList();
        sendJson([
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Modules scannés et synchronisés.',
            'data'    => $modules
        ]);
    }

    if ($method === 'POST' && $uri === '/api/v1/sys/modules/scan') {
        if (!$isSuperadmin && $currentRole !== 'Président' && !in_array('system.admin', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Accès restreint au Président ou Superadmin.',
                'data'    => null
            ]);
        }
        $manager = new \Core\Api\Services\ModuleManager();
        $modules = $manager->scanAndList();
        sendJson([
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Scan des modules actualisé avec succès.',
            'data'    => $modules
        ]);
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/sys/modules/([^/]+)/toggle$#', $uri, $matches)) {
        if (!$isSuperadmin && $currentRole !== 'Président' && !in_array('system.admin', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Action sur les modules réservée au Président ou Superadmin.',
                'data'    => null
            ]);
        }
        $targetIdOrFolder = urldecode($matches[1]);
        $enabled = (bool) ($payload['enabled'] ?? true);
        $manager = new \Core\Api\Services\ModuleManager();
        sendJson($manager->toggle($targetIdOrFolder, $enabled, $currentUserId, $isSuperadmin));
    }

    if (($method === 'POST' && preg_match('#^/api/v1/sys/modules/([^/]+)/uninstall$#', $uri, $matches))
        || ($method === 'DELETE' && preg_match('#^/api/v1/sys/modules/([^/]+)$#', $uri, $matches))) {
        if (!$isSuperadmin && $currentRole !== 'Président' && !in_array('system.admin', $permissions, true)) {
            $logUnauthorizedAttempt($uri);
            sendJson([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Désinstallation des modules réservée au Président ou Superadmin.',
                'data'    => null
            ]);
        }
        $targetIdOrFolder = urldecode($matches[1]);
        $manager = new \Core\Api\Services\ModuleManager();
        sendJson($manager->uninstall($targetIdOrFolder, $currentUserId, $isSuperadmin));
    }

    sendJson([
        'status'  => 'error',
        'code'    => 404,
        'message' => 'Endpoint API introuvable : ' . $method . ' ' . $uri,
        'data'    => null
    ]);
} catch (Throwable $e) {
    sendJson([
        'status'  => 'error',
        'code'    => 500,
        'message' => 'Erreur interne du serveur : ' . $e->getMessage(),
        'data'    => null
    ]);
}