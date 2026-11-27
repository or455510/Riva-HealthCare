// agent-control/lib/report.mjs
//
// Generates a point-in-time engineering report: git state per target + task
// board snapshot. Never includes file contents from anywhere outside the
// task board itself, so there is nothing here for the secret-path guard to
// need to redact mid-report — but it still never shells out to read .env,
// as an explicit invariant worth stating rather than leaving implicit.

import fs from 'node:fs';
import path from 'node:path';
import { ROOT, TARGETS, assertInsideWorkspace } from './workspace.mjs';
import { hasGitRepo, currentBranch, statusPorcelain, recentCommits } from './git.mjs';
import { boardCounts } from './tasks.mjs';

const REPORTS_DIR = path.join(ROOT, 'agent-control', 'reports');

function gitSummary(name, dir) {
  if (!fs.existsSync(dir)) {
    return `- **${name}** (${dir}): target does not exist on disk.`;
  }
  if (!hasGitRepo(dir)) {
    return `- **${name}** (${dir}): no git repository.`;
  }
  const branch = currentBranch(dir);
  const dirty = statusPorcelain(dir);
  const dirtyCount = dirty ? dirty.split('\n').length : 0;
  const commits = recentCommits(dir, 5);
  return [
    `- **${name}** (${dir}):`,
    `  - branch: \`${branch}\``,
    `  - uncommitted changes: ${dirtyCount}`,
    `  - recent commits:`,
    ...(commits.length ? commits.map((c) => `    - ${c}`) : ['    - (none)']),
  ].join('\n');
}

export function generateReport() {
  const ts = new Date().toISOString();
  const counts = boardCounts();

  const lines = [
    `# Riva Agent Control Layer — Engineering Report`,
    ``,
    `Generated: ${ts}`,
    ``,
    `## Git State`,
    ``,
    gitSummary('root', TARGETS.root),
    gitSummary('backend', TARGETS.backend),
    gitSummary('frontend', TARGETS.frontend),
    ``,
    `## Task Board`,
    ``,
    ...Object.entries(counts).map(([s, n]) => `- ${s}: ${n}`),
    ``,
  ];

  const filename = `REPORT-${ts.replace(/[:.]/g, '-')}.md`;
  fs.mkdirSync(REPORTS_DIR, { recursive: true });
  const file = assertInsideWorkspace(path.join(REPORTS_DIR, filename));
  fs.writeFileSync(file, lines.join('\n'), 'utf8');
  return { file, text: lines.join('\n') };
}
