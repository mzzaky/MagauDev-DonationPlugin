import {readdirSync,readFileSync,statSync,mkdirSync,writeFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {join,relative} from 'node:path';
import {deflateRawSync,crc32} from 'node:zlib';
import './check-release.mjs';
const root=fileURLToPath(new URL('../',import.meta.url));
const version=JSON.parse(readFileSync(join(root,'package.json'),'utf8')).version;
const paths=['magaudev-donations.php','uninstall.php','readme.txt','README.md','CHANGELOG.md','LICENSE','src','docs'];
const walk=p=>statSync(join(root,p)).isDirectory()?readdirSync(join(root,p)).flatMap(n=>walk(`${p}/${n}`)):[p];
const files=paths.flatMap(walk).filter(path=>!path.startsWith('docs/dev-log'));const pieces=[];const central=[];let offset=0;
for(const path of files){const bytes=readFileSync(join(root,path));const compressed=deflateRawSync(bytes,{level:9});const name=Buffer.from(`magaudev-donations/${path}`);const crc=crc32(bytes);const local=Buffer.alloc(30);local.writeUInt32LE(0x04034b50,0);local.writeUInt16LE(20,4);local.writeUInt16LE(0x0800,6);local.writeUInt16LE(8,8);local.writeUInt16LE(0x5d5f,12);local.writeUInt32LE(crc,14);local.writeUInt32LE(compressed.length,18);local.writeUInt32LE(bytes.length,22);local.writeUInt16LE(name.length,26);pieces.push(local,name,compressed);const cd=Buffer.alloc(46);cd.writeUInt32LE(0x02014b50,0);cd.writeUInt16LE(20,4);cd.writeUInt16LE(20,6);cd.writeUInt16LE(0x0800,8);cd.writeUInt16LE(8,10);cd.writeUInt16LE(0x5d5f,14);cd.writeUInt32LE(crc,16);cd.writeUInt32LE(compressed.length,20);cd.writeUInt32LE(bytes.length,24);cd.writeUInt16LE(name.length,28);cd.writeUInt32LE(offset,42);central.push(cd,name);offset+=30+name.length+compressed.length;}
const directory=Buffer.concat(central);const end=Buffer.alloc(22);end.writeUInt32LE(0x06054b50,0);end.writeUInt16LE(files.length,8);end.writeUInt16LE(files.length,10);end.writeUInt32LE(directory.length,12);end.writeUInt32LE(offset,16);mkdirSync(join(root,'build'),{recursive:true});const output=join(root,'build',`magaudev-donations-${version}.zip`);writeFileSync(output,Buffer.concat([...pieces,directory,end]));console.log(`${relative(root,output)}: ${files.length} files, plugin root verified`);
