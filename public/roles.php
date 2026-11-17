<?php

declare(strict_types=1);

$pageTitle     = 'Rôles & Permissions';
$activeNav     = 'roles';
$requireAuth   = true;
$requiredPerms = ['roles.manage'];
$pageScripts   = ['/assets/ts/roles.js'];

ob_start();
?>
<div class="space-y-6">
    <!-- En-tête + Formulaire d'ajout d'une nouvelle colonne (Nouveau Rôle) -->
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-brand-primary">Gestion des Droits d'Accès</span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">Tableau des Rôles & Permissions</h1>
            <p class="text-xs text-slate-500 mt-0.5">Chaque colonne représente un rôle du club. Vous pouvez renommer un rôle, ajouter une nouvelle colonne ou supprimer un rôle personnalisé.</p>
        </div>

        <form id="form-add-role-column" class="flex items-center gap-2 shrink-0" novalidate>
            <input type="text" id="new-role-name" required placeholder="Nom du nouveau rôle (ex: Bénévole)..." class="border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-semibold w-56 focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            <button type="submit" id="btn-add-role-col" class="bg-brand-primary text-white px-4 py-2 rounded-xl text-xs font-bold shadow-xs transition shrink-0">
                + Ajouter un rôle
            </button>
        </form>
    </div>

    <!-- TABLEAU UNIQUE MULTI-COLONNES (Point 10) -->
    <div class="bg-white rounded-2xl shadow-xs border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table id="rbac-matrix-table" class="w-full text-left border-collapse text-xs">
                <thead id="rbac-matrix-thead" class="bg-slate-900 text-white border-b border-slate-800">
                    <tr>
                        <th class="p-4">Domaine & Permission</th>
                        <th class="p-4 text-center">Chargement de la matrice...</th>
                    </tr>
                </thead>
                <tbody id="rbac-matrix-tbody" class="divide-y divide-slate-200">
                    <tr>
                        <td colspan="2" class="p-4 text-slate-500">Initialisation des colonnes de rôles...</td>
                    </tr>
                </tbody>
                <tfoot id="rbac-matrix-tfoot" class="bg-slate-50 border-t border-slate-200"></tfoot>
            </table>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';