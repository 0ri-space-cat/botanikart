import {readFile,writeFile} from 'node:fs/promises';
const file=new URL('./catalog.json',import.meta.url);
const data=JSON.parse(await readFile(file,'utf8'));
if(!Array.isArray(data.products)||!data.products.length)throw new Error('Каталог пуст: рабочие данные сохранены');
await writeFile(new URL('./catalog.js',import.meta.url),'window.BOTANIK_CATALOG = '+JSON.stringify(data,null,2)+';\n');
