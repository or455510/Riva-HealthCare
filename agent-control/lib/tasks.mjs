// agent-control/lib/tasks.mjs
//
// The task board is plain Markdown files under tasks/<status>/. No database,
// no server. Each file has a small fenced "fields" block at the top (parsed
// with a simple line-based reader, no YAML/front-matter dependency) followed
// by free-text sections. See agent-control/templates/TASK_TEMPLATE.md for
// the canonical shape.

import fs from 'node:fs';
import path from 'node:path';
import { ROOT, assertInsideWorkspace, sanitizeId } from './workspace.mjs';

export const STATUSES = ['inbox', 'active', 'review', 'blocked', 'done'];
const TASKS_DIR = path.join(ROOT, 'tasks');

const FIELD_KEYS = [
  'ID',
  'Title',
  'Status',
  'Risk Level',
  'Assigned',
  'Reviewer',
  'Allowed Operations',
  'Created',
  'Updated',
];

function statusDir(status) {
  if (!STATUSES.includes(status)) {
    throw new Error(`Unknown task status "${status}". Valid: ${STATUSES.join(', ')}`);
  }
  return assertInsideWorkspace(path.join(TASKS_DIR, status));
}

function nowIso() {
  return new Date().toISOString();
}

function fieldsBlock(fields) {
  return FIELD_KEYS.map((k) => `- **${k}:** ${fields[k] ?? ''}`).join('\n');
}

function parseFields(text) {
  const fields = {};
  for (const key of FIELD_KEYS) {
    const re = new RegExp(`^- \\*\\*${key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}:\\*\\*\\s*(.*)$`, 'm');
    const m = text.match(re);
    if (m) fields[key] = m[1].trim();
  }
  return fields;
}

export function listTasks(status) {
  const statuses = status ? [status] : STATUSES;
  const out = [];
  for (const s of statuses) {
    const dir = statusDir(s);
    if (!fs.existsSync(dir)) continue;
    for (const f of fs.readdirSync(dir)) {
      if (!f.endsWith('.md')) continue;
      const full = path.join(dir, f);
      const text = fs.readFileSync(full, 'utf8');
      out.push({ status: s, file: full, ...parseFields(text) });
    }
  }
  return out;
}

export function findTaskFile(id) {
  const clean = sanitizeId(id);
  for (const s of STATUSES) {
    const dir = statusDir(s);
    if (!fs.existsSync(dir)) continue;
    const match = fs.readdirSync(dir).find((f) => f.startsWith(clean + '-') || f === `${clean}.md`);
    if (match) return { status: s, file: assertInsideWorkspace(path.join(dir, match)) };
  }
  return null;
}

export function showTask(id) {
  const found = findTaskFile(id);
  if (!found) throw new Error(`No task found with ID "${id}".`);
  return { ...found, text: fs.readFileSync(found.file, 'utf8') };
}

export function createTask({
  id,
  title,
  objective,
  businessReason,
  technicalRequirements,
  acceptanceCriteria,
  riskLevel = 'UNKNOWN',
  allowedOperations = 'read, safe-operations',
  assigned = 'UNASSIGNED',
  reviewer = 'UNASSIGNED',
}) {
  if (!id || !title) throw new Error('Task requires at least an id and a title.');
  const cleanId = sanitizeId(id);
  if (findTaskFile(cleanId)) throw new Error(`Task "${cleanId}" already exists.`);

  const slug = String(title)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);
  const filename = `${cleanId}-${slug || 'task'}.md`;
  const dir = statusDir('inbox');
  fs.mkdirSync(dir, { recursive: true });
  const file = assertInsideWorkspace(path.join(dir, filename));

  const ts = nowIso();
  const fields = {
    ID: cleanId,
    Title: title,
    Status: 'INBOX',
    'Risk Level': riskLevel,
    Assigned: assigned,
    Reviewer: reviewer,
    'Allowed Operations': allowedOperations,
    Created: ts,
    Updated: ts,
  };

  const body = `# ${cleanId}: ${title}

${fieldsBlock(fields)}

## Objective
${objective || 'UNKNOWN / REQUIRES VERIFICATION'}

## Business Reason
${businessReason || 'UNKNOWN / REQUIRES VERIFICATION'}

## Technical Requirements
${technicalRequirements || 'UNKNOWN / REQUIRES VERIFICATION'}

## Acceptance Criteria
${acceptanceCriteria || 'UNKNOWN / REQUIRES VERIFICATION'}
`;

  fs.writeFileSync(file, body, 'utf8');
  return { file, fields };
}

export function moveTask(id, newStatus) {
  if (!STATUSES.includes(newStatus)) {
    throw new Error(`Unknown target status "${newStatus}". Valid: ${STATUSES.join(', ')}`);
  }
  const found = findTaskFile(id);
  if (!found) throw new Error(`No task found with ID "${id}".`);

  let text = fs.readFileSync(found.file, 'utf8');
  const ts = nowIso();
  text = text.replace(/^- \*\*Status:\*\*.*$/m, `- **Status:** ${newStatus.toUpperCase()}`);
  text = text.replace(/^- \*\*Updated:\*\*.*$/m, `- **Updated:** ${ts}`);

  const destDir = statusDir(newStatus);
  fs.mkdirSync(destDir, { recursive: true });
  const destFile = assertInsideWorkspace(path.join(destDir, path.basename(found.file)));
  fs.writeFileSync(destFile, text, 'utf8');
  if (destFile !== found.file) fs.unlinkSync(found.file);
  return { from: found.status, to: newStatus, file: destFile };
}

export function boardCounts() {
  const counts = {};
  for (const s of STATUSES) {
    const dir = statusDir(s);
    counts[s] = fs.existsSync(dir) ? fs.readdirSync(dir).filter((f) => f.endsWith('.md')).length : 0;
  }
  return counts;
}
