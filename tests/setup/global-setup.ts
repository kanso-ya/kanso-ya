/**
 * @file global-setup.ts
 * @description Sauvegarde l'état courant de la base SQLite (snapshot exploratoire)
 * et exécute 002_core_seed.php pour garantir que la BDD existe et contient les fixtures QA.
 */

import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

export default async function globalSetup(): Promise<void> {
    const rootDir = process.cwd();
    const dbPath = path.join(rootDir, 'storage', 'db', 'kanso-ya.sqlite');
    const backupPath = path.join(rootDir, 'storage', 'db', 'kanso-ya.sqlite.bak');

    if (fs.existsSync(dbPath)) {
        fs.copyFileSync(dbPath, backupPath);
    }

    execSync('php core/Api/Database/migrations/002_core_seed.php', { stdio: 'ignore' });
}