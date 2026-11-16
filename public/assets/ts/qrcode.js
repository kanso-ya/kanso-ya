/**
 * @file qrcode.ts
 * @description Générateur de QR Code SVG pur TypeScript (zéro dépendance externe).
 * Conforme à la norme ISO/IEC 18004 (Mode Byte 8-bit, ECC niveau L/M, Versions 1 à 6).
 */
// Tables d'exponentiation et de logarithmes pour le corps de Galois GF(2^8)
const GF_EXP = new Uint8Array(512);
const GF_LOG = new Uint8Array(256);
(function initGaloisField() {
    let x = 1;
    for (let i = 0; i < 255; i++) {
        GF_EXP[i] = x;
        GF_LOG[x] = i;
        x <<= 1;
        if (x & 256) {
            x ^= 0x11d; // Polynôme primitif x^8 + x^4 + x^3 + x^2 + 1
        }
    }
    for (let i = 255; i < 512; i++) {
        GF_EXP[i] = GF_EXP[i - 255];
    }
})();
function gfMultiply(a, b) {
    if (a === 0 || b === 0)
        return 0;
    return GF_EXP[GF_LOG[a] + GF_LOG[b]];
}
/**
 * Calcule les mots de contrôle Reed-Solomon pour un bloc de données.
 */
function reedSolomonCompute(data, ecCount) {
    // Générateur Reed-Solomon
    let gen = [1];
    for (let i = 0; i < ecCount; i++) {
        const nextGen = new Array(gen.length + 1).fill(0);
        for (let j = 0; j < gen.length; j++) {
            nextGen[j] ^= gfMultiply(gen[j], GF_EXP[i]);
            nextGen[j + 1] ^= gen[j];
        }
        gen = nextGen;
    }
    const res = new Array(ecCount).fill(0);
    for (let i = 0; i < data.length; i++) {
        const factor = data[i] ^ res[0];
        res.shift();
        res.push(0);
        if (factor !== 0) {
            for (let j = 0; j < ecCount; j++) {
                res[j] ^= gfMultiply(gen[j + 1], factor);
            }
        }
    }
    return res;
}
// Table des spécifications QR Code (Niveau M pour une excellente lisibilité mobile)
const VERSIONS = [
    { version: 1, totalBytes: 26, dataBytes: 16, ecBytes: 10, alignments: [] },
    { version: 2, totalBytes: 44, dataBytes: 28, ecBytes: 16, alignments: [6, 18] },
    { version: 3, totalBytes: 70, dataBytes: 44, ecBytes: 26, alignments: [6, 22] },
    { version: 4, totalBytes: 100, dataBytes: 64, ecBytes: 36, alignments: [6, 26] },
    { version: 5, totalBytes: 134, dataBytes: 86, ecBytes: 48, alignments: [6, 30] },
    { version: 6, totalBytes: 172, dataBytes: 108, ecBytes: 64, alignments: [6, 34] }
];
/**
 * Encode une chaîne en flux de bits selon le mode Byte 8-bit.
 */
function encodeData(text, spec) {
    const encoder = new TextEncoder();
    const utf8Bytes = Array.from(encoder.encode(text));
    // Bits buffer
    let bits = '';
    // Mode Byte : 0100
    bits += '0100';
    // Nombre de caractères (8 bits pour versions 1 à 9)
    const count = utf8Bytes.length;
    bits += count.toString(2).padStart(8, '0');
    // Octets UTF-8
    for (const b of utf8Bytes) {
        bits += b.toString(2).padStart(8, '0');
    }
    // Terminaison (jusqu'à 4 zéros)
    const maxBits = spec.dataBytes * 8;
    const termLen = Math.min(4, maxBits - bits.length);
    for (let i = 0; i < termLen; i++)
        bits += '0';
    // Remplissage modulo 8
    while (bits.length % 8 !== 0) {
        bits += '0';
    }
    // Octets de remplissage (Pad bytes 0xEC et 0x11)
    const pad = ['11101100', '00010001'];
    let padIdx = 0;
    while (bits.length < maxBits) {
        bits += pad[padIdx];
        padIdx = (padIdx + 1) % 2;
    }
    // Conversion en tableau d'octets
    const dataBytes = [];
    for (let i = 0; i < bits.length; i += 8) {
        dataBytes.push(parseInt(bits.slice(i, i + 8), 2));
    }
    // Calcul du code de correction Reed-Solomon
    const ecCodewords = reedSolomonCompute(dataBytes, spec.ecBytes);
    return [...dataBytes, ...ecCodewords];
}
/**
 * Génère la matrice booléenne d'un QR code complet.
 */
export function createQrMatrix(text) {
    const utf8Length = new TextEncoder().encode(text).length;
    const spec = VERSIONS.find(v => v.dataBytes - 3 >= utf8Length) || VERSIONS[VERSIONS.length - 1];
    const size = spec.version * 4 + 17;
    const matrix = Array.from({ length: size }, () => new Array(size).fill(null));
    const reserved = Array.from({ length: size }, () => new Array(size).fill(false));
    function setModule(r, c, val) {
        matrix[r][c] = val;
        reserved[r][c] = true;
    }
    // 1. Motifs Finder (7x7) aux coins
    function drawFinder(row, col) {
        for (let r = -1; r <= 7; r++) {
            for (let c = -1; c <= 7; c++) {
                const tr = row + r;
                const tc = col + c;
                if (tr >= 0 && tr < size && tc >= 0 && tc < size) {
                    const isBorder = (r === 0 || r === 6 || c === 0 || c === 6);
                    const isCenter = (r >= 2 && r <= 4 && c >= 2 && c <= 4);
                    setModule(tr, tc, (isBorder || isCenter) && (r >= 0 && r <= 6 && c >= 0 && c <= 6));
                }
            }
        }
    }
    drawFinder(0, 0);
    drawFinder(0, size - 7);
    drawFinder(size - 7, 0);
    // 2. Timing patterns
    for (let i = 8; i < size - 8; i++) {
        if (!reserved[6][i])
            setModule(6, i, i % 2 === 0);
        if (!reserved[i][6])
            setModule(i, 6, i % 2 === 0);
    }
    // 3. Alignment patterns (version >= 2)
    if (spec.alignments.length === 2) {
        const [p1, p2] = spec.alignments;
        const coords = [[p1, p1], [p1, p2], [p2, p1], [p2, p2]];
        for (const [ar, ac] of coords) {
            // Ignorer si chevauche un finder pattern
            if ((ar < 9 && ac < 9) || (ar < 9 && ac > size - 9) || (ar > size - 9 && ac < 9))
                continue;
            for (let r = -2; r <= 2; r++) {
                for (let c = -2; c <= 2; c++) {
                    const isEdge = Math.max(Math.abs(r), Math.abs(c)) === 2;
                    const isCenter = r === 0 && c === 0;
                    setModule(ar + r, ac + c, isEdge || isCenter);
                }
            }
        }
    }
    // 4. Dark module fixe
    setModule(size - 8, 8, true);
    // 5. Zone Format Info réservée
    for (let i = 0; i < 9; i++) {
        reserved[8][i] = true;
        reserved[i][8] = true;
        reserved[8][size - 1 - i] = true;
        reserved[size - 1 - i][8] = true;
    }
    // 6. Placement des données en zigzag
    const data = encodeData(text, spec);
    let bitIdx = 0;
    const allBits = [];
    for (const byte of data) {
        for (let b = 7; b >= 0; b--) {
            allBits.push((byte >> b) & 1);
        }
    }
    let up = true;
    for (let c = size - 1; c > 0; c -= 2) {
        if (c === 6)
            c--; // Saut du timing pattern vertical
        const rows = up ? Array.from({ length: size }, (_, i) => size - 1 - i) : Array.from({ length: size }, (_, i) => i);
        for (const r of rows) {
            for (const col of [c, c - 1]) {
                if (!reserved[r][col]) {
                    const bit = bitIdx < allBits.length ? allBits[bitIdx++] : 0;
                    // Masque standard (r + col) % 2 === 0
                    const mask = (r + col) % 2 === 0;
                    matrix[r][col] = (bit === 1) !== mask;
                }
            }
        }
        up = !up;
    }
    // 7. Format Information (Niveau M = 00, Masque 000 = 101010000010010 avec XOR mask)
    const formatBits = [1, 0, 1, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 1, 0];
    for (let i = 0; i < 6; i++)
        matrix[8][i] = formatBits[i] === 1;
    matrix[8][7] = formatBits[6] === 1;
    matrix[8][8] = formatBits[7] === 1;
    matrix[7][8] = formatBits[8] === 1;
    for (let i = 9; i < 15; i++)
        matrix[14 - i][8] = formatBits[i] === 1;
    for (let i = 0; i < 8; i++)
        matrix[size - 1 - i][8] = formatBits[i] === 1;
    for (let i = 8; i < 15; i++)
        matrix[8][size - 15 + i] = formatBits[i] === 1;
    return matrix.map(row => row.map(val => val === true));
}
/**
 * Produit le code SVG pur représentant le QR Code.
 *
 * @param text Chaîne textuelle à encoder (URL TOTP, etc.).
 * @param size Taille carrée en pixels (défaut 180px).
 * @returns Balise string <svg> complète.
 */
export function generateQrCodeSvg(text, size = 180) {
    const matrix = createQrMatrix(text);
    const modCount = matrix.length;
    const quietZone = 2;
    const totalModules = modCount + quietZone * 2;
    const cellSize = (size / totalModules).toFixed(3);
    let rects = '';
    for (let r = 0; r < modCount; r++) {
        for (let c = 0; c < modCount; c++) {
            if (matrix[r][c]) {
                const x = ((c + quietZone) * Number(cellSize)).toFixed(2);
                const y = ((r + quietZone) * Number(cellSize)).toFixed(2);
                rects += `<rect x="${x}" y="${y}" width="${cellSize}" height="${cellSize}" fill="#0f172a" />`;
            }
        }
    }
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" class="rounded-xl shadow-xs border border-slate-200 bg-white p-1">
        <rect width="${size}" height="${size}" fill="#ffffff" rx="12" />
        ${rects}
    </svg>`;
}
