import {readFile,writeFile,mkdir,readdir} from 'node:fs/promises';
import {createCanvas,loadImage} from '../.superdesign/pdf-tools/node_modules/@napi-rs/canvas/index.js';
import {getDocument} from '../.superdesign/pdf-tools/node_modules/pdfjs-dist/legacy/build/pdf.mjs';
await mkdir('.superdesign/report-review',{recursive:true});
for(const stage of ['before','after'])for(const unit of ['doctors','clinics']){
  const root=`../backend/docs/samples/comparison/${stage}/${unit}`;
  const pages=[];
  for(const name of (await readdir(root)).filter(n=>n.endsWith('.pdf')).sort()){
    const task=getDocument({data:new Uint8Array(await readFile(`${root}/${name}`))});const pdf=await task.promise;
    for(let i=1;i<=pdf.numPages;i++)pages.push({name:name.replace('.pdf','')+'-'+i+'.png',label:name+' p'+i});
    await task.destroy();
  }
  for(let start=0;start<pages.length;start+=6){
    const canvas=createCanvas(1500,1680),ctx=canvas.getContext('2d');ctx.fillStyle='#dce4e1';ctx.fillRect(0,0,1500,1680);
    for(let j=0;j<6&&start+j<pages.length;j++){const item=pages[start+j],im=await loadImage(`docs/screenshots/comparison/${stage}/${unit}/${item.name}`);
      const x=(j%3)*500,y=Math.floor(j/3)*840;const scale=Math.min(480/im.width,790/im.height);ctx.drawImage(im,x+10,y+30,im.width*scale,im.height*scale);ctx.fillStyle='#173d35';ctx.font='14px Arial';ctx.fillText(item.label,x+8,y+20);
    }
    await writeFile(`.superdesign/report-review/${stage}-${unit}-${start/6+1}.png`,canvas.toBuffer('image/png'));
  }
}
for(const unit of ['doctors','clinics']){
 const prefix=unit==='doctors'?'doctor':'clinic';
 for(const kind of ['list','detail','short','list-excel-preview']){
  const ims=await Promise.all(['before','after'].map(stage=>loadImage(`docs/screenshots/comparison/${stage}/${unit}/${prefix}-${kind}-1.png`)));
  const width=1800,height=Math.ceil(Math.max(...ims.map(i=>i.height/i.width*880)))+45;
  const canvas=createCanvas(width,height),ctx=canvas.getContext('2d');ctx.fillStyle='#e7eeeb';ctx.fillRect(0,0,width,height);
  ims.forEach((im,index)=>{ctx.drawImage(im,index*900+10,35,880,im.height/im.width*880);ctx.fillStyle='#173d35';ctx.font='bold 20px Arial';ctx.fillText(index?'AFTER':'BEFORE',index*900+15,25);});
  await writeFile(`docs/screenshots/comparison/${prefix}-${kind}.png`,canvas.toBuffer('image/png'));
 }
}
console.log('Contact sheets for every actual page and eight matched before/after comparisons generated.');
