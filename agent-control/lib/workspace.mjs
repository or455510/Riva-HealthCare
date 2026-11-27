// agent-control/lib/workspace.mjs
//
// Workspace confinement. Every other module in this tool routes filesystem
// paths through here first. Nothing in agent-control/ should call fs.*
// directly on a path that hasn't passed assertInsideWorkspace().

import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// lib/ -> agent-control/ -> repo root (rivatest-main/)
export const AGENT_CONTROL_DIR = path.resolve(__dirname, '..');
export const ROOT = path.resolve(AGENT_CONTROL_DIR, '..');

export const TARGETS = Object.freeze({
  root: ROOT,
  backend: path.join(ROOT, 'backend'),
  frontend: path.join(ROOT, 'Riva-master'),
});

export class WorkspaceViolation extends Error {}

// Filenames that must never be read, written, printed, or included in any
// generated report by this tool, regardless of which command is running.
const SECRET_FILENAME_PATTERNS = [
  /^\.env(\..+)?$/i,
  /secret/i,
  /credential/i,
  /\.pem$/i,
  /\.key$/i,
  /id_rsa/i,
];

export function isSecretPath(p) {
  return SECRET_FILENAME_PATTERNS.some((re) => re.test(path.basename(p)));
}

/**
 * Resolve `p` and verify it stays inside ROOT and is not a secret-like path.
 * Throws WorkspaceViolation otherwise. Every command handler must call this
 * before touching a filesystem path derived from user input.
 */
export function assertInsideWorkspace(p) {
  const resolved = path.resolve(p);
  const rootWithSep = ROOT.endsWith(path.sep) ? ROOT : ROOT + path.sep;
  if (resolved !== ROOT && !resolved.startsWith(rootWithSep)) {
    throw new WorkspaceViolation(
      `Refusing to operate outside the Riva workspace root (${ROOT}): ${resolved}`
    );
  }
  if (isSecretPath(resolved)) {
    throw new WorkspaceViolation(`Refusing to touch a secret-like path: ${resolved}`);
  }
  return resolved;
}

export function resolveTarget(name = 'root') {
  const dir = TARGETS[name];
  if (!dir) {
    throw new Error(`Unknown target "${name}". Valid targets: ${Object.keys(TARGETS).join(', ')}`);
  }
  const resolved = assertInsideWorkspace(dir);
  if (!fs.existsSync(resolved)) {
    throw new Error(`Target "${name}" does not exist on disk: ${resolved}`);
  }
  return resolved;
}

// Sanitize a task-ID-like string for safe use as a filename/branch fragment.
// Only used for values that end up in filesystem paths or git ref names.
export function sanitizeId(id) {
  let clean = String(id)
    .trim()
    .replace(/[^A-Za-z0-9._-]/g, '-')
    .replace(/\.{2,}/g, '-') // collapse ".."/"..." runs — invalid in git ref names, also blocks path-traversal-shaped input
    .replace(/^[.-]+/, ''); // no leading dot/hyphen
  if (!clean) throw new Error('Task ID is empty after sanitization.');
  return clean;
}
