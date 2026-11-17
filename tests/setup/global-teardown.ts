/**
 * @file global-teardown.ts
 * @description Nettoie les fichiers temporaires après la campagne Playwright sans purger les journaux récents de la base SQLite.
 */

import fs from 'fs';
import path from 'path';

export default async function globalTeardown(): Promise<void> {
    const rootDir = process.cwd();
    const backupPath = path.join(rootDir, 'storage', 'db', 'kanso-ya.sqlite.bak');

    // Conserve la base active et ses journaux (sys_logs, app_logs) intacts pour consultation post-tests
    if (fs.existsSync(backupPath)) {
        fs.unlinkSync(backupPath);
    }
}