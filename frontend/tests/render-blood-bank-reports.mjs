// Uses the optional local PDF QA tools already used by directory reports.
import { readFile, writeFile, readdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { createCanvas } from '../.superdesign/pdf-tools/node_modules/@napi-rs/canvas/index.js';
import { getDocument } from '../.superdesign/pdf-tools/node_modules/pdfjs-dist/legacy/build/pdf.mjs';
const root = fileURLToPath(new URL('../.superdesign/blood-bank-reports/', import.meta.url));
for (const name of (await readdir(root)).filter(n => n.endsWith('.pdf'))) {
  const bytes = await readFile(`${root}/${name}`);
  const task = getDocument({ data: new Uint8Array(bytes), useSystemFonts: false, isEvalSupported: false });
  const document = await task.promise;
  const selected = document.numPages <= 4 ? Array.from({ length: document.numPages }, (_, i) => i + 1) : [...new Set([1, Math.ceil(document.numPages / 2), document.numPages])];
  for (const number of selected) {
    const page = await document.getPage(number); const viewport = page.getViewport({ scale: 1.3 });
    const canvas = createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    await writeFile(`${root}/${name.replace('.pdf', '')}-page-${number}.png`, canvas.toBuffer('image/png'));
  }
  console.log(`${name}: ${document.numPages} pages; rendered first/middle/last (${selected.join(', ')}).`);
  await task.destroy();
}
