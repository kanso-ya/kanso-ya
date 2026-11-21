<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

use Core\Api\Endpoints\CmsController;

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? 'mentions-legales'))) ?: 'mentions-legales';
if ($slug === 'horaires') {
    header('Location: /horaires', true, 302);
    exit;
}
$cms = new CmsController();
$res = $cms->getPageBySlug($slug, false);
$pageData = $res['data'] ?? [
    'title'   => 'Page introuvable (404)',
    'content' => '<p>Cette page est actuellement en brouillon ou n\'existe pas.</p>'
];

$pageTitle = (string) $pageData['title'];
$activeNav = '';

ob_start();
?>
<article class="bg-white p-8 rounded-2xl shadow-xs border border-slate-200 max-w-4xl mx-auto space-y-4">
    <h1 id="legal-page-title" class="text-2xl font-extrabold text-slate-900 border-b border-slate-100 pb-4">
        <?= htmlspecialchars((string) $pageData['title'], ENT_QUOTES, 'UTF-8') ?>
    </h1>
    <div id="legal-page-content" class="prose max-w-none text-slate-700 space-y-3 leading-relaxed">
        <?= (string) $pageData['content'] ?>
    </div>
</article>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';