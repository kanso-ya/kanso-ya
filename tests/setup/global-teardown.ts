/**
 * @file global-teardown.ts
 * @description Restaure l'état exact de la base SQLite après la campagne Playwright.
 */

import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

export default async function globalTeardown(): Promise<void> {
    const rootDir = process.cwd();
    const dbPath = path.join(rootDir, 'storage', 'db', 'kanso-ya.sqlite');
    const backupPath = path.join(rootDir, 'storage', 'db', 'kanso-ya.sqlite.bak');

    if (fs.existsSync(backupPath)) {
        if (fs.existsSync(`${dbPath}-wal`)) fs.unlinkSync(`${dbPath}-wal`);
        if (fs.existsSync(`${dbPath}-shm`)) fs.unlinkSync(`${dbPath}-shm`);

        fs.copyFileSync(backupPath, dbPath);
        fs.unlinkSync(backupPath);
    } else {
        execSync('php core/Api/Database/migrations/002_core_seed.php', { stdio: 'ignore' });
    }
}