/**
 * @file MarkdownErrorReporter.ts
 * @description Reporter Playwright personnalisé. Intercepte les tests en échec 
 * et compile un rapport détaillé au format Markdown pour analyse rapide.
 */

import { Reporter, TestCase, TestResult, FullResult } from '@playwright/test/reporter';
import * as fs from 'fs';
import * as path from 'path';


/**
 * Interface représentant les données extraites d'un test ayant échoué.
 */
interface FailedTestDetails {
    title: string;
    location: string;
    duration: number;
    error: string;
}

export default class MarkdownErrorReporter implements Reporter {
    private failedTests: FailedTestDetails[] = [];
    private readonly reportDir: string = path.join(process.cwd(), 'error-reports');

    /**
     * Intercepte la fin de chaque test individuel.
     * Si le test échoue ou expire, ses métadonnées sont stockées.
     * 
     * @param {TestCase} test - Le scénario de test Playwright.
     * @param {TestResult} result - Le résultat de l'exécution.
     */
    public onTestEnd(test: TestCase, result: TestResult): void {
        if (result.status === 'failed' || result.status === 'timedOut') {
            this.failedTests.push({
                title: test.title,
                location: `${test.location.file}:${test.location.line}`,
                duration: result.duration,
                error: result.error?.message || 'Erreur inconnue',
            });
        }
    }

    /**
     * Déclenchée à la fin de la suite de tests complète.
     * Génère le fichier Markdown si des échecs ont été enregistrés.
     * 
     * @param {FullResult} result - Le résultat global de la suite de tests.
     */
    public onEnd(result: FullResult): void {
        if (this.failedTests.length === 0) {
            console.log('✅ Matrice QA validée. Aucun rapport Markdown généré.');
            return;
        }

        if (!fs.existsSync(this.reportDir)) {
            fs.mkdirSync(this.reportDir, { recursive: true });
        }

        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
        const filename = path.join(this.reportDir, `failed-tests-${timestamp}.md`);

        const content = this.generateMarkdownContent(result.startTime);
        fs.writeFileSync(filename, content, 'utf-8');

        console.log(`❌ ${this.failedTests.length} échec(s) détecté(s). Rapport généré : ${filename}`);
    }

    /**
     * Formate les données des tests échoués en une structure Markdown.
     * 
     * @param {Date} startTime - La date de début d'exécution des tests.
     * @returns {string} Le contenu formaté en Markdown.
     */
    private generateMarkdownContent(startTime: Date): string {
        let md = `# Rapport d'Erreurs QA - Kanso-ya\n\n`;
        md += `**Date d'exécution :** ${startTime.toLocaleString('fr-FR')}\n`;
        md += `**Total des échecs :** ${this.failedTests.length}\n\n`;
        md += `---\n\n`;

        this.failedTests.forEach((test, index) => {
            md += `### ${index + 1}. ${test.title}\n\n`;
            md += `- **Fichier :** \`${test.location}\`\n`;
            md += `- **Durée d'exécution :** ${test.duration} ms\n\n`;
            md += `**Trace de l'erreur :**\n`;
            md += "```text\n";
            md += `${test.error}\n`;
            md += "```\n\n";
            md += `---\n\n`;
        });

        return md;
    }
}