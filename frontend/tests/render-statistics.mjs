// Optional local visual QA using the report-rendering tools already installed
// for directory reports. Generated artifacts stay under ignored test-results.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createCanvas, loadImage } from '../.superdesign/pdf-tools/node_modules/@napi-rs/canvas/index.js';
import { getDocument } from '../.superdesign/pdf-tools/node_modules/pdfjs-dist/legacy/build/pdf.mjs';
for (const name of ['report', 'long-report']) {
  const bytes = await readFile(`test-results/statistics/${name}.pdf`);
  const fonts = [...new Set([...bytes.toString('latin1').matchAll(/\/BaseFont\s*\/([^\s/<>[\]]+)/g)].map(m => m[1]))];
  assert.ok(fonts.length && fonts.every(f => /cairo/i.test(f)));
  const task = getDocument({ data: new Uint8Array(bytes), useSystemFonts: false, isEvalSupported: false });
  const pdf = await task.promise;
  for (let number = 1; number <= pdf.numPages; number++) {
    const page = await pdf.getPage(number), content = await page.getTextContent();
    const text = content.items.map(i => i.str).join(' ');
    assert.doesNotMatch(text, /SECRET|0900999000|patient_id|dossier_id/);
    assert.ok(text.length > 20, 'No blank overflow page');
    const viewport = page.getViewport({ scale: 1.1 });
    const canvas = createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    await writeFile(`test-results/statistics/${name}-${number}.png`, canvas.toBuffer('image/png'));
  }
  console.log(`${name}: ${pdf.numPages} pages rendered, embedded Cairo, no fixture secrets in extracted text.`);
  const contact = createCanvas(1200, Math.ceil(pdf.numPages / 3) * 570), ctx = contact.getContext('2d');
  ctx.fillStyle = 'white'; ctx.fillRect(0, 0, contact.width, contact.height);
  for (let n = 1; n <= pdf.numPages; n++) ctx.drawImage(await loadImage(`test-results/statistics/${name}-${n}.png`), ((n - 1) % 3) * 400, Math.floor((n - 1) / 3) * 570, 400, 565);
  await writeFile(`test-results/statistics/${name}-overview.png`, contact.toBuffer('image/png'));
  await task.destroy();
}
