<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use finfo;
use PDO;
use Throwable;

/**
 * Contrôleur gérant le moteur éditorial complet (CRUD Articles, Catégories, Pages statiques/légales et Médias).
 * Applique REQ-CMS-WF-03 : 404 Not Found pour les visiteurs anonymes sur toute ressource restreinte.
 */
final class CmsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Liste les articles visibles selon le niveau d'accréditation du visiteur ou membre.
     *
     * @param string|null   $userRole    Rôle de l'utilisateur courant (null si visiteur).
     * @param array<string> $permissions Permissions détenues.
     * @return array<string, mixed> Réponse JSend.
     */
    public function listArticles(?string $userRole, array $permissions): array
    {
        $allowedVisibilities = ['public'];

        if ($userRole !== null && $userRole !== '') {
            $allowedVisibilities[] = 'members';
            if (
                in_array('articles.publish', $permissions, true)
                || in_array('finances.manage', $permissions, true)
                || in_array('roles.manage', $permissions, true)
            ) {
                $allowedVisibilities[] = 'board';
            }
        }

        $placeholders = implode(',', array_fill(0, count($allowedVisibilities), '?'));

        $canSeeDrafts = in_array('articles.write', $permissions, true)
            || in_array('articles.publish', $permissions, true);

        $statusClause = $canSeeDrafts ? "" : " AND a.status = 'published'";

        $sql = "
            SELECT a.*, c.name AS category_name, c.badge_color,
                   u1.first_name AS author_first_name, u1.last_name AS author_last_name,
                   u2.first_name AS editor_first_name, u2.last_name AS editor_last_name
            FROM articles a
            INNER JOIN article_categories c ON a.category_id = c.id
            LEFT JOIN users u1 ON COALESCE(a.created_by, a.author_id) = u1.id
            LEFT JOIN users u2 ON a.updated_by = u2.id
            WHERE a.visibility IN ($placeholders) $statusClause
            ORDER BY a.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($allowedVisibilities);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Articles récupérés.',
            'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /**
     * Récupère un article spécifique en contrôlant sa visibilité (REQ-CMS-WF-03 & TC-CMS-N03).
     *
     * @param int           $articleId   Identifiant de l'article.
     * @param string|null   $userRole    Rôle de l'utilisateur.
     * @param array<string> $permissions Permissions de l'utilisateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getArticle(int $articleId, ?string $userRole, array $permissions): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, c.name AS category_name, c.badge_color,
                   u1.first_name AS author_first_name, u1.last_name AS author_last_name,
                   u2.first_name AS editor_first_name, u2.last_name AS editor_last_name
            FROM articles a
            INNER JOIN article_categories c ON a.category_id = c.id
            LEFT JOIN users u1 ON COALESCE(a.created_by, a.author_id) = u1.id
            LEFT JOIN users u2 ON a.updated_by = u2.id
            WHERE a.id = :id
        ");
        $stmt->execute(['id' => $articleId]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$article) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Article introuvable.',
                'data'    => null
            ];
        }

        $isAnonymous = ($userRole === null || $userRole === '');
        $isBoard = in_array('articles.publish', $permissions, true)
            || in_array('finances.manage', $permissions, true)
            || in_array('roles.manage', $permissions, true);
        $canWrite = in_array('articles.write', $permissions, true) || $isBoard;

        if ($isAnonymous && ($article['visibility'] !== 'public' || $article['status'] !== 'published')) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Ressource introuvable.',
                'data'    => null
            ];
        }

        if ($article['visibility'] === 'board' && !$isBoard) {
            return [
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Accès refusé : contenu réservé aux membres du bureau.',
                'data'    => null
            ];
        }

        if ($article['status'] === 'draft' && !$canWrite) {
            return [
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Accès refusé : brouillon non publié.',
                'data'    => null
            ];
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Article récupéré.',
            'data'    => $article
        ];
    }

    /**
     * Crée un nouvel article (TC-CMS-P01, TC-CMS-N01, TC-CMS-N02).
     *
     * @param array<string, mixed> $payload     Données soumises.
     * @param int                  $authorId    Identifiant de l'auteur.
     * @param array<string>        $permissions Permissions de l'auteur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function createArticle(array $payload, int $authorId, array $permissions): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $rawContent = trim((string) ($payload['content'] ?? ''));
        $categoryId = (int) ($payload['category_id'] ?? 1);
        $visibility = (string) ($payload['visibility'] ?? 'public');

        if (mb_strlen($title) < 3 || mb_strlen($title) > 150) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le titre doit contenir entre 3 et 150 caractères.',
                'data'    => null
            ];
        }

        if ($rawContent === '' || mb_strlen($rawContent) > 50000) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le contenu de l\'article est invalide ou dépasse la taille maximale autorisée.',
                'data'    => null
            ];
        }

        if (!in_array($visibility, ['public', 'members', 'board'], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Visibilité invalide (Public, Adhérents ou Bureau uniquement).',
                'data'    => null
            ];
        }

        $requestedStatus = (string) ($payload['status'] ?? 'draft');
        $canPublish = in_array('articles.publish', $permissions, true);
        $finalStatus = ($canPublish && $requestedStatus === 'published') ? 'published' : 'draft';

        $cleanTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $cleanContent = $this->sanitizeHtml($rawContent);

        $articleId = Database::transaction(function () use ($cleanTitle, $cleanContent, $categoryId, $authorId, $visibility, $finalStatus): int {
            $stmt = $this->db->prepare("
                INSERT INTO articles (title, content, category_id, author_id, created_by, updated_by, visibility, status)
                VALUES (:title, :content, :cat, :author, :created_by, :updated_by, :vis, :status)
            ");
            $stmt->execute([
                'title'      => $cleanTitle,
                'content'    => $cleanContent,
                'cat'        => $categoryId,
                'author'     => $authorId,
                'created_by' => $authorId,
                'updated_by' => $authorId,
                'vis'        => $visibility,
                'status'     => $finalStatus
            ]);

            return (int) $this->db->lastInsertId();
        });

        if ($finalStatus === 'published') {
            try {
                if (class_exists('\\Core\\Sdk\\EventManager')) {
                    \Core\Api\Sdk\EventManager::getInstance()->dispatch('on_article_published', [
                        'article_id' => $articleId,
                        'title'      => $cleanTitle,
                        'author_id'  => $authorId
                    ]);
                }
            } catch (Throwable) {
            }
        }

        return [
            'status'  => 'success',
            'code'    => 201,
            'message' => 'Article enregistré avec succès.',
            'data'    => [
                'id'         => $articleId,
                'title'      => $cleanTitle,
                'content'    => $cleanContent,
                'status'     => $finalStatus,
                'visibility' => $visibility
            ]
        ];
    }

    /**
     * Modifie un article existant.
     *
     * @param int                  $articleId   Identifiant de l'article.
     * @param array<string, mixed> $payload     Nouvelles données.
     * @param array<string>        $permissions Permissions de l'utilisateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateArticle(int $articleId, array $payload, array $permissions, int $userId = 1): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $rawContent = trim((string) ($payload['content'] ?? ''));
        $categoryId = (int) ($payload['category_id'] ?? 1);
        $visibility = (string) ($payload['visibility'] ?? 'public');

        if (mb_strlen($title) < 3 || $rawContent === '') {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Titre (min 3 car.) et contenu obligatoires.',
                'data'    => null
            ];
        }

        $cleanTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $cleanContent = $this->sanitizeHtml($rawContent);

        $canPublish = in_array('articles.publish', $permissions, true);
        $statusClause = "";
        $params = [
            'title'   => $cleanTitle,
            'content' => $cleanContent,
            'cat'     => $categoryId,
            'vis'     => $visibility,
            'uid'     => $userId,
            'id'      => $articleId
        ];

        if ($canPublish && isset($payload['status']) && in_array($payload['status'], ['draft', 'published'], true)) {
            $statusClause = ", status = :status";
            $params['status'] = (string) $payload['status'];
        }

        Database::transaction(function () use ($params, $statusClause) {
            $stmt = $this->db->prepare("
                UPDATE articles
                SET title = :title, content = :content, category_id = :cat, visibility = :vis, updated_by = :uid $statusClause, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute($params);
        });

        if (($params['status'] ?? '') === 'published') {
            try {
                if (class_exists('\\Core\\Sdk\\EventManager')) {
                    \Core\Api\Sdk\EventManager::getInstance()->dispatch('on_article_published', [
                        'article_id' => $articleId,
                        'title'      => $cleanTitle
                    ]);
                }
            } catch (Throwable) {
            }
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Article modifié avec succès.',
            'data'    => ['id' => $articleId, 'title' => $cleanTitle]
        ];
    }

    /**
     * Supprime définitivement un article.
     *
     * @param int $articleId Identifiant de l'article.
     * @param int $userId    Identifiant de l'utilisateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function deleteArticle(int $articleId, int $userId): array
    {
        $deleted = Database::transaction(function () use ($articleId, $userId): bool {
            $stmt = $this->db->prepare("DELETE FROM articles WHERE id = :id");
            $stmt->execute(['id' => $articleId]);

            if ($stmt->rowCount() === 0) {
                return false;
            }

            $this->db->prepare("
                INSERT INTO app_logs (user_id, action, target_type, target_id)
                VALUES (:uid, 'ARTICLE_DELETED', 'article', :tid)
            ")->execute(['uid' => $userId, 'tid' => $articleId]);

            return true;
        });

        if (!$deleted) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Article introuvable.',
                'data'    => null
            ];
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Article supprimé avec succès.',
            'data'    => ['deleted_id' => $articleId]
        ];
    }

    /**
     * Publie ou dépublie un article existant (TC-CMS-P02).
     *
     * @param int                  $articleId Identifiant de l'article.
     * @param array<string, mixed> $payload   Contient 'status'.
     * @param int                  $userId    Identifiant de l'utilisateur.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateArticleStatus(int $articleId, array $payload, int $userId): array
    {
        $status = (string) ($payload['status'] ?? '');
        if (!in_array($status, ['draft', 'published'], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Statut de publication invalide.',
                'data'    => null
            ];
        }

        $visibility = $payload['visibility'] ?? null;
        if ($visibility !== null && !in_array($visibility, ['public', 'members', 'board'], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Visibilité invalide.',
                'data'    => null
            ];
        }

        Database::transaction(function () use ($articleId, $status, $visibility, $userId) {
            if ($visibility !== null) {
                $stmt = $this->db->prepare("
                    UPDATE articles
                    SET status = :status, visibility = :vis, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute(['status' => $status, 'vis' => $visibility, 'uid' => $userId, 'id' => $articleId]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE articles
                    SET status = :status, updated_by = :uid, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute(['status' => $status, 'uid' => $userId, 'id' => $articleId]);
            }

            $stmtLog = $this->db->prepare("
                INSERT INTO app_logs (user_id, action, target_type, target_id)
                VALUES (:uid, 'ARTICLE_STATUS_UPDATED', 'article', :tid)
            ");
            $stmtLog->execute([
                'uid' => $userId,
                'tid' => $articleId
            ]);
        });

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Statut de l\'article mis à jour.',
            'data'    => [
                'id'     => $articleId,
                'status' => $status
            ]
        ];
    }

    /**
     * Gère l'upload sécurisé d'images d'illustration pour les articles (REQ-CMS-FILE-02).
     *
     * @param array<string, mixed> $file Tableau issu de $_FILES['file'].
     * @return array<string, mixed> Réponse JSend.
     */
    public function uploadArticleMedia(array $file): array
    {
        if (empty($file['tmp_name']) || !file_exists((string) $file['tmp_name'])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Aucun fichier média valide reçu.',
                'data'    => null
            ];
        }

        $originalName = strtolower((string) ($file['name'] ?? ''));
        if (preg_match('/\.(php|phtml|exe|sh|bat|js|svg|html)$/i', $originalName)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Extension de fichier interdite pour des raisons de sécurité.',
                'data'    => null
            ];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file((string) $file['tmp_name']);
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'video/mp4'  => 'mp4',
            'video/webm' => 'webm',
        ];

        if (!is_string($realMime) || !isset($allowedMimes[$realMime])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Format de média non supporté (seuls JPEG, PNG, WEBP, MP4 et WEBM sont autorisés).',
                'data'    => null
            ];
        }

        $uploadDir = __DIR__ . '/../../../public/uploads/media';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeFilename = 'media_' . bin2hex(random_bytes(8)) . '.' . $allowedMimes[$realMime];
        $destination = $uploadDir . '/' . $safeFilename;

        @copy((string) $file['tmp_name'], $destination);

        return [
            'status'  => 'success',
            'code'    => 201,
            'message' => 'Image uploadée avec succès.',
            'data'    => [
                'url'      => '/uploads/media/' . $safeFilename,
                'mimeType' => $realMime
            ]
        ];
    }

    /**
     * Liste toutes les pages institutionnelles et légales.
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function listPages(): array
    {
        $stmt = $this->db->query("SELECT * FROM pages ORDER BY id ASC");
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Pages récupérées.',
            'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /**
     * Récupère une page institutionnelle ou légale par son slug (US 4.1).
     *
     * @param string $slug         Slug de la page.
     * @param bool   $canManageCms Indique si l'appelant peut voir les brouillons.
     * @return array<string, mixed> Réponse JSend.
     */
    public function getPageBySlug(string $slug, bool $canManageCms = false): array
    {
        $stmt = $this->db->prepare("SELECT * FROM pages WHERE slug = :slug");
        $stmt->execute(['slug' => $slug]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$page) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Page introuvable.',
                'data'    => null
            ];
        }

        if ($page['status'] === 'draft' && !$canManageCms) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Page introuvable.',
                'data'    => null
            ];
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Page récupérée.',
            'data'    => $page
        ];
    }

    /**
     * Met à jour ou crée une page statique/légale par son ID ou son slug (US 4.1).
     *
     * @param string               $identifier ID numérique ou slug de la page.
     * @param array<string, mixed> $payload    Contient 'title', 'content' et 'status'.
     * @return array<string, mixed> Réponse JSend.
     */
    public function savePage(string $identifier, array $payload): array
    {
        $title = htmlspecialchars(trim((string) ($payload['title'] ?? '')), ENT_QUOTES, 'UTF-8');
        $content = $this->sanitizeHtml((string) ($payload['content'] ?? ''));
        $status = (string) ($payload['status'] ?? 'draft');

        if ($title === '' || !in_array($status, ['draft', 'published'], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Titre requis et statut valide (draft/published) obligatoire.',
                'data'    => null
            ];
        }

        Database::transaction(function () use ($identifier, $title, $content, $status, &$slug) {
            if (ctype_digit($identifier)) {
                $stmtCheck = $this->db->prepare("SELECT slug FROM pages WHERE id = :id");
                $stmtCheck->execute(['id' => (int) $identifier]);
                $slug = (string) ($stmtCheck->fetchColumn() ?: ('page-' . $identifier));
            } else {
                $slug = $identifier;
            }

            $stmtCheckSlug = $this->db->prepare("SELECT id FROM pages WHERE slug = :slug");
            $stmtCheckSlug->execute(['slug' => $slug]);
            $existingId = $stmtCheckSlug->fetchColumn();

            if ($existingId) {
                $stmt = $this->db->prepare("
                    UPDATE pages
                    SET title = :title, content = :content, status = :status, updated_at = CURRENT_TIMESTAMP
                    WHERE slug = :slug
                ");
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO pages (slug, title, content, status)
                    VALUES (:slug, :title, :content, :status)
                ");
            }

            $stmt->execute([
                'slug'    => $slug,
                'title'   => $title,
                'content' => $content,
                'status'  => $status
            ]);
        });

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Page enregistrée avec succès.',
            'data'    => [
                'slug'   => $slug,
                'title'  => $title,
                'status' => $status
            ]
        ];
    }

    /**
     * Supprime les médias uploadés orphelins (Point 25).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function cleanupOrphanMedia(): array
    {
        $uploadDir = __DIR__ . '/../../../public/uploads/';
        if (!is_dir($uploadDir)) {
            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Aucun dossier de médias.',
                'data'    => ['deleted_count' => 0]
            ];
        }

        $articlesContent = $this->db->query("SELECT content FROM articles")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $pagesContent = $this->db->query("SELECT content FROM pages")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $allText = implode(' ', array_merge($articlesContent, $pagesContent));

        $files = scandir($uploadDir) ?: [];
        $deletedCount = 0;
        foreach ($files as $file) {
            if (str_starts_with($file, '.') || !is_file($uploadDir . $file)) {
                continue;
            }
            if (strpos($allText, $file) === false) {
                @unlink($uploadDir . $file);
                $deletedCount++;
            }
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => "Nettoyage terminé : {$deletedCount} fichier(s) orphelin(s) supprimé(s).",
            'data'    => ['deleted_count' => $deletedCount]
        ];
    }

    /**
     * Nettoie le code HTML riche issu du Builder Multi-Colonnes pour prévenir toute injection XSS
     * tout en préservant les conteneurs de mise en page (<div>, <section>, <figure>, <img>).
     *
     * @param string $html Contenu HTML brut.
     * @return string Contenu HTML assaini.
     */
    private function sanitizeHtml(string $html): string
    {
        $clean = preg_replace('#<(script|iframe|object|embed|style)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $clean = strip_tags($clean, '<div><section><article><figure><figcaption><img><video><source><button><input><p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><blockquote><a><span>');
        $clean = preg_replace('/\bon\w+\s*=\s*(["\']?).*?\1/i', '', $clean) ?? '';
        $clean = preg_replace('/javascript\s*:/i', '', $clean) ?? '';

        return $clean;
    }
}