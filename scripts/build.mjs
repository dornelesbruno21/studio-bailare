import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
if(process.argv.length>2)throw new Error('Portfólio não aceita ativação de produção.');
const target=path.join(root,'dist');
if(fs.existsSync(target))throw new Error('dist já existe. Guarde ou remova o build anterior antes de gerar outro.');
fs.cpSync(path.join(root,'public'),target,{recursive:true});
console.log('Prévia em dist/. Não substituir o site real por esta edição.');
