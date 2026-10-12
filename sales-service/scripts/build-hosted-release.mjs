import {cp, mkdir, readdir, rm} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const output = path.join(root, 'publish', 'reach');
await rm(output, {recursive: true, force: true});
await mkdir(path.join(output, 'public_html'), {recursive: true});
await cp(path.join(root, 'dist'), path.join(output, 'public_html'), {recursive: true});
await cp(path.join(root, 'deploy', 'public_html'), path.join(output, 'public_html'), {recursive: true});
await cp(path.join(root, 'backend'), path.join(output, 'fieldwork', 'backend'), {recursive: true});
for (const localOnly of ['add-user.php', 'provision-local.php', 'recover-local-owner.php', 'setup.php']) {
  await rm(path.join(output, 'fieldwork', 'backend', localOnly));
}
await cp(path.join(root, 'deploy', 'config.production.example.php'), path.join(output, 'fieldwork', 'config.production.example.php'));
const publicFiles = await readdir(path.join(output, 'public_html'));
if (!publicFiles.includes('index.html') || !publicFiles.includes('index.php') || !publicFiles.includes('.htaccess')) {
  throw new Error('Hosted release is missing a required public file');
}
console.log(`Hosted release ready: ${output}`);
