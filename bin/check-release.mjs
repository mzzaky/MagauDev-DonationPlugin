import { readFileSync,existsSync } from 'node:fs';
const read=p=>readFileSync(new URL('../'+p,import.meta.url),'utf8');
const version=JSON.parse(read('package.json')).version;
if(!read('magaudev-donations.php').includes(`Version: ${version}`)||!read('magaudev-donations.php').includes(`'MGDD_VERSION', '${version}'`)||!read('CHANGELOG.md').includes(`## [${version}]`)||!existsSync(new URL(`../docs/releases/v${version}.md`,import.meta.url)))throw new Error('Release metadata mismatch');
console.log(`Magaudev Donations ${version}: release metadata OK`);
