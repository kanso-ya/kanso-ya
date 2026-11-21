/**
 * @file api.ts
 * @description Wrapper Fetch pour la communication asynchrone avec le Backend PHP.
 * Respecte la règle de rétention utilisateur (Soft Errors - Section 1 du Guide UI/UX) :
 * aucune redirection brutale sur erreur 403/422, affichage systématique d'un Toast.
 */

import { showToast } from './main.js';

/**
 * Enveloppe standardisée JSend retournée par l'API (HTTP 200 constant).
 */
export interface ApiResponse<T = unknown> {
    status: 'success' | 'error';
    success?: boolean;
    code: number;
    message: string;
    data: T | null;
}

/**
 * Exécute une requête HTTP vers l'API interne du CMS avec injection automatique du Token CSRF.
 *
 * @template T
 * @param {string} endpoint Le chemin de la route (ex: '/api/v1/members').
 * @param {RequestInit} options Les options natives fetch (method, body, etc.).
 * @returns {Promise<ApiResponse<T>>} La promesse résolue avec l'enveloppe JSend.
 */
export async function fetchApi<T = any>(endpoint: string, options: RequestInit = {}): Promise<ApiResponse<T>> {
    const headers = new Headers(options.headers || {});

    if (!headers.has('Content-Type') && !(options.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }

    const csrfMeta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (csrfMeta && !['GET', 'HEAD'].includes(options.method?.toUpperCase() || 'GET')) {
        headers.set('X-CSRF-Token', csrfMeta.content);
    }

    try {
        const response = await fetch(endpoint, {
            ...options,
            headers,
        });

        if (!response.ok) {
            throw new Error(`Erreur HTTP ${response.status}`);
        }

        const data = (await response.json()) as ApiResponse<T>;

        // Rétention de l'utilisateur (Soft Error) : Toast explicite sans quitter la page
        if (data.status === 'error') {
            showToast(data.message || `Action refusée (${data.code})`, 'error');
        }

        return data;
    } catch (error) {
        showToast('Erreur de communication avec le serveur.', 'error');
        return {
            status: 'error',
            code: 500,
            message: (error as Error).message,
            data: null
        };
    }
}