import { readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const root=fileURLToPath(new URL('../',import.meta.url));
const files=readdirSync(root,{recursive:true}).filter(f=>f.endsWith('.php')&&!/^(vendor|build|\.tools)[\\/]/.test(f));
for(const file of files){const r=spawnSync('php',['-l',file],{cwd:root,encoding:'utf8'});if(r.status!==0){console.error(r.stdout,r.stderr);process.exit(1);}}
console.log(`PHP syntax: ${files.length} files OK`);
const phpcs=process.env.MGDD_PHPCS??'vendor/bin/phpcs';
const result=spawnSync('php',[phpcs,'--standard=phpcs.xml.dist'],{cwd:root,stdio:'inherit'});
process.exit(result.status??1);
