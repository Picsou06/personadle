#!/usr/bin/env node
/**
 * scripts/generate-aoa-blurred.js — Pré-génère les variantes floutées de chaque
 * portrait du mode All-Out Attack, servies ensuite par api/game/image.php sans
 * jamais exposer le nom du fichier source au client.
 *
 * Même raison que scripts/generate-silhouette-blackened.js : le flou CSS
 * (`filter: blur()`) actuel est purement décoratif — l'image d'origine transite
 * en clair sur le réseau, et « clic droit → copier l'image » rend l'original
 * net, flou compris.
 *
 * Le flou est PROGRESSIF avec les tentatives (contrairement à la silhouette,
 * fixe) : niveau = max(20 - 3×tentatives, 0), soit exactement 8 paliers
 * distincts pour tentatives 0 à 7 (20,17,14,11,8,5,2,0), stable à 0 au-delà.
 * On pré-génère donc ces 8 paliers par personnage, plus une variante Expert
 * (flou fixe 20 + niveaux de gris) — 9 fichiers par personnage.
 *
 * Usage :
 *   node scripts/generate-aoa-blurred.js         # régénère tout
 *   node scripts/generate-aoa-blurred.js --check # vérifie sans écrire
 *
 * npm run aoa-blur:build / npm run aoa-blur:check — voir package.json.
 */

import { readFileSync, writeFileSync, existsSync, mkdirSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import sharp from "sharp";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const CHECK = process.argv.includes("--check");
const SRC_DIR = join(ROOT, "allOutAttackMode/database/allOutAttack");
const OUT_DIR = join(ROOT, "api/data/aoa_blurred");

export const BLUR_LEVELS = [20, 17, 14, 11, 8, 5, 2, 0];

/**
 * Description : niveau de flou attendu pour un nombre de tentatives donné —
 *               même formule que blurFilter() (allOutAttackMode/modeAllOutAttack.js),
 *               portée ici pour que le build et l'endpoint restent synchronisés.
 * Usage       : const level = blurLevelForAttempts(3);
 * Inputs      : attempts (number).
 * Outputs     : number — un des BLUR_LEVELS.
 */
export function blurLevelForAttempts(attempts) {
  return Math.max(20 - 3 * Math.min(attempts, 7), 0);
}

async function blurVariant(inputBuffer, level, grayscale) {
  let pipeline = sharp(inputBuffer);
  if (level > 0) pipeline = pipeline.blur(level);
  if (grayscale) pipeline = pipeline.grayscale();
  return pipeline.webp().toBuffer();
}

async function main() {
  const { aoaCharacters } = await import(
    pathToFileURL(join(ROOT, "allOutAttackMode/database/aoaCharacters.js")).href
  );
  const { portraitsMap } = await import(
    pathToFileURL(join(ROOT, "allOutAttackMode/database/portraitsMap.js")).href
  );

  let changed = 0;
  let missing = 0;
  const variants = [...BLUR_LEVELS.map((l) => ({ suffix: String(l), level: l, grayscale: false })), {
    suffix: "expert",
    level: 20,
    grayscale: true,
  }];

  if (!CHECK) mkdirSync(OUT_DIR, { recursive: true });

  for (const { nom } of aoaCharacters) {
    const imageName = portraitsMap[nom] || nom.split(" ")[0];
    const srcPath = join(SRC_DIR, `${imageName}.webp`);
    if (!existsSync(srcPath)) {
      console.warn(`⚠ Source manquante pour "${imageName}" (${srcPath})`);
      missing++;
      continue;
    }
    const srcBuffer = readFileSync(srcPath);

    for (const { suffix, level, grayscale } of variants) {
      const outPath = join(OUT_DIR, `${imageName}_${suffix}.webp`);
      const blurred = await blurVariant(srcBuffer, level, grayscale);

      if (CHECK) {
        const current = existsSync(outPath) ? readFileSync(outPath) : null;
        if (!current || !current.equals(blurred)) {
          console.error(`❌ ${imageName}_${suffix}.webp désynchronisé — lancer \`npm run aoa-blur:build\`.`);
          changed++;
        }
        continue;
      }

      writeFileSync(outPath, blurred);
    }
  }

  if (CHECK) {
    if (missing > 0) {
      console.error(`❌ ${missing} image(s) source manquante(s).`);
      process.exit(1);
    }
    if (changed > 0) process.exit(1);
    console.log(`✅ ${aoaCharacters.length * variants.length} variantes AOA à jour.`);
    return;
  }

  console.log(
    `✅ ${(aoaCharacters.length - missing) * variants.length} variantes AOA générées dans ` +
      `${OUT_DIR.replace(ROOT + "/", "")}` +
      (missing > 0 ? ` (${missing} source(s) manquante(s), voir avertissements ci-dessus)` : "")
  );
}

main().catch((err) => {
  console.error("[generate-aoa-blurred] Erreur:", err);
  process.exitCode = 1;
});
