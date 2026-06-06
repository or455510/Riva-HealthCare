import { createServer } from 'node:http';
import { createReadStream, existsSync, statSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const root = process.cwd();
const mime = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
};

createServer((req, res) => {
  const urlPath = decodeURIComponent((req.url || '/').split('?')[0]);
  const safePath = normalize(urlPath).replace(/^(\.\.[/\\])+/, '');
  let file = join(root, safePath);

  if (urlPath === '/' || !existsSync(file) || !statSync(file).isFile()) {
    file = join(root, 'index.html');
  }

  res.setHeader('Content-Type', mime[extname(file)] || 'application/octet-stream');
  createReadStream(file).pipe(res);
}).listen(4300, '127.0.0.1', () => {
  console.log('static server listening on http://127.0.0.1:4300');
});
