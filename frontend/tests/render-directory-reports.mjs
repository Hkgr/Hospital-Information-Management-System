// Local visual QA helper. See docs/doctors.md for the optional rendering tools.
import { readFile,writeFile,mkdir,readdir } from 'node:fs/promises';
import { createCanvas } from '../.superdesign/pdf-tools/node_modules/@napi-rs/canvas/index.js';
import { getDocument } from '../.superdesign/pdf-tools/node_modules/pdfjs-dist/legacy/build/pdf.mjs';

for(const directory of ['doctors','clinics']) {
  const root=`../backend/docs/samples/${directory}`;
  const output=`docs/screenshots/${directory}/pdf`;
  await mkdir(output,{recursive:true});
  for(const name of (await readdir(root)).filter(name=>name.endsWith('.pdf'))) {
    const bytes=await readFile(`${root}/${name}`);
    const fontNames=[...new Set([...bytes.toString('latin1').matchAll(/\/BaseFont\s*\/([^\s/<>[\]]+)/g)].map(m=>m[1]))];
    if(!name.includes('excel-preview')) {
      if(!fontNames.length||fontNames.some(name=>!name.toLowerCase().includes('cairo'))||!bytes.includes(Buffer.from('/FontFile2'))) throw new Error(`Unexpected/missing embedded font in ${name}: ${fontNames}`);
    }
    const task=getDocument({data:new Uint8Array(bytes),useSystemFonts:false,isEvalSupported:false});const pdf=await task.promise;
    let arabic=0;
    for(let number=1;number<=pdf.numPages;number++) {
      const page=await pdf.getPage(number);const text=(await page.getTextContent()).items.map(i=>i.str).join(' ');arabic+=(text.match(/[\u0600-\u06ff]/g)||[]).length;
      const viewport=page.getViewport({scale:1.3});const canvas=createCanvas(Math.ceil(viewport.width),Math.ceil(viewport.height));await page.render({canvasContext:canvas.getContext('2d'),viewport}).promise;
      await writeFile(`${output}/${name.replace('.pdf','')}-${number}.png`,canvas.toBuffer('image/png'));
    }
    console.log(`${directory}/${name}: ${pdf.numPages} rendered pages; ${arabic} Arabic characters; embedded fonts: ${fontNames.join(', ')}`);await task.destroy();
  }
}
for(const name of ['Cairo-Regular.ttf','Cairo-Bold.ttf']) {
  const bytes=await readFile(`../backend/resources/fonts/cairo/${name}`);const tags=[];
  for(let i=0;i<bytes.readUInt16BE(4);i++)tags.push(bytes.toString('ascii',12+i*16,16+i*16));
  if(tags.includes('fvar')||!tags.includes('GSUB')||!tags.includes('GPOS'))throw new Error('Expected static Cairo with OpenType shaping tables.');
  console.log(`${name}: static TTF; GSUB and GPOS verified.`);
}
