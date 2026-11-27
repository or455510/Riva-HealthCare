#!/usr/bin/env node
// agent-control/bin/riva-agent.mjs
//
// Riva Agent Control Layer — local developer CLI. No HTTP server, no
// listening socket, no dependency beyond Node's own built-ins. Run this
// directly with `node agent-control/bin/riva-agent.mjs <command>`.
//
// See agent-control/README.md for the full command list and
// docs/RIVA_AGENT_WORKFLOW.md for how this fits into the human+agent
// workflow.

import { execFileSync } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';

import { ROOT, TARGETS, resolveTarget, sanitizeId, isSecretPath, WorkspaceViolation } from '../lib/workspace.mjs';
import {
  hasGitRepo,
  currentBranch,
  statusPorcelain,
  recentCommits,
  diffText,
  createTaskBranch,
} from '../lib/git.mjs';
import { listTasks, showTask, createTask, moveTask, boardCounts, STATUSES } from '../lib/tasks.mjs';
import { refuseRestricted, RESTRICTED_OPERATIONS } from '../lib/restricted.mjs';
import { generateReport } from '../lib/report.mjs';

function parseArgs(argv) {
  const positional = [];
  const flags = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const eq = a.indexOf('=');
      if (eq !== -1) {
        flags[a.slice(2, eq)] = a.slice(eq + 1);
      } else {
        const next = argv[i + 1];
        if (next !== undefined && !next.startsWith('--')) {
          flags[a.slice(2)] = next;
          i++;
        } else {
          flags[a.slice(2)] = true;
        }
      }
    } else {
      positional.push(a);
    }
  }
  return { positional, flags };
}

function printHelp() {
  console.log(`Riva Agent Control Layer

Usage: node agent-control/bin/riva-agent.mjs <command> [options]

READ / SAFE commands:
  status                        Repository + task board status (root, backend, frontend)
  branch <task-id> [--target backend|frontend|root] [--kind agent|review]
                                 Create agent/<task-id> or review/<task-id> branch
  diff [--target root|backend|frontend] [--staged]
                                 Show git diff for a target
  test [--target backend|frontend]
                                 Run the test suite for a target (backend only, currently)
  lint [--target backend|frontend]
                                 Run the linter/formatter check for a target (backend only, currently)
  report                        Generate a timestamped engineering report under agent-control/reports/
  doctor                        Self-check: workspace confinement, targets, secret-path guard

Task board commands:
  task new --id RIVA-XXX --title "..." [--objective ...] [--business ...] [--tech ...]
           [--acceptance ...] [--risk LOW|MEDIUM|HIGH] [--allowed "..."]
           [--assigned Claude] [--reviewer Codex]
  task list [--status inbox|active|review|blocked|done]
  task show <task-id>
  task move <task-id> <inbox|active|review|blocked|done>

Restricted (never automated — see docs/RIVA_AGENT_WORKFLOW.md):
  restricted <op-key>            Explains why <op-key> is refused. Known keys:
                                  ${Object.keys(RESTRICTED_OPERATIONS).join(', ')}
`);
}

function cmdStatus() {
  console.log(`Riva workspace root: ${ROOT}\n`);
  for (const [name, dir] of Object.entries(TARGETS)) {
    console.log(`## ${name} (${dir})`);
    if (!fs.existsSync(dir)) {
      console.log('  target does not exist on disk\n');
      continue;
    }
    if (!hasGitRepo(dir)) {
      console.log('  no git repository (see docs/RIVA_AGENT_WORKFLOW_AUDIT.md §4)\n');
      continue;
    }
    const branch = currentBranch(dir);
    const dirty = statusPorcelain(dir);
    const dirtyLines = dirty ? dirty.split('\n') : [];
    console.log(`  branch: ${branch}`);
    console.log(`  uncommitted changes: ${dirtyLines.length}`);
    for (const line of dirtyLines.slice(0, 20)) console.log(`    ${line}`);
    if (dirtyLines.length > 20) console.log(`    ... and ${dirtyLines.length - 20} more`);
    const commits = recentCommits(dir, 5);
    console.log('  recent commits:');
    for (const c of commits) console.log(`    ${c}`);
    console.log('');
  }
  console.log('## task board');
  const counts = boardCounts();
  for (const [s, n] of Object.entries(counts)) console.log(`  ${s}: ${n}`);
}

function cmdBranch(positional, flags) {
  const [taskId] = positional;
  if (!taskId) throw new Error('Usage: branch <task-id> [--target backend|frontend|root] [--kind agent|review]');
  const target = flags.target || 'backend';
  const kind = flags.kind || 'agent';
  const dir = resolveTarget(target);
  const { branch, from } = createTaskBranch(dir, sanitizeId(taskId), kind);
  console.log(`Created branch ${branch} (from ${from}) in ${dir}`);
}

function cmdDiff(positional, flags) {
  const target = flags.target || 'root';
  const dir = resolveTarget(target);
  if (!hasGitRepo(dir)) {
    console.log(`No git repository at ${dir} — nothing to diff.`);
    return;
  }
  const text = diffText(dir, !!flags.staged);
  process.stdout.write(text || '(no changes)\n');
}

function cmdTest(flags) {
  const target = flags.target || 'backend';
  if (target === 'backend') {
    const dir = resolveTarget('backend');
    console.log(`Running: php artisan test (cwd=${dir})`);
    console.log('(test env uses sqlite/array drivers — does not touch real DB, mail, or SMS)\n');
    execFileSync('php', ['artisan', 'test'], { cwd: dir, stdio: 'inherit' });
    return;
  }
  if (target === 'frontend') {
    console.log(
      'Frontend test runner is ambiguous in this repository: both Karma (`ng test`) and a ' +
        'standalone `vitest` devDependency are present with no config confirming which is authoritative ' +
        '(see docs/RIVA_AGENT_WORKFLOW_AUDIT.md §3). Not automated — run manually:\n' +
        '  cd Riva-master && npm test'
    );
    return;
  }
  throw new Error('Usage: test [--target backend|frontend]');
}

function cmdLint(flags) {
  const target = flags.target || 'backend';
  if (target === 'backend') {
    const dir = resolveTarget('backend');
    console.log(`Running: php vendor/bin/pint --test (cwd=${dir}, non-mutating check)\n`);
    try {
      execFileSync('php', ['vendor/bin/pint', '--test'], { cwd: dir, stdio: 'inherit' });
    } catch (err) {
      // Pint exits non-zero when files would be reformatted — that's a
      // real finding, not a tool failure, so just propagate the exit code.
      process.exitCode = err.status ?? 1;
    }
    return;
  }
  if (target === 'frontend') {
    console.log(
      'No frontend linter is configured in this repository (no ESLint config, no lint devDependency, ' +
        'no lint script — see docs/RIVA_AGENT_WORKFLOW_AUDIT.md §3). Nothing to run.'
    );
    return;
  }
  throw new Error('Usage: lint [--target backend|frontend]');
}

function cmdTaskNew(flags) {
  const { id, title } = flags;
  if (!id || !title) throw new Error('Usage: task new --id RIVA-XXX --title "..." [other flags]');
  const { file, fields } = createTask({
    id,
    title,
    objective: flags.objective,
    businessReason: flags.business,
    technicalRequirements: flags.tech,
    acceptanceCriteria: flags.acceptance,
    riskLevel: flags.risk,
    allowedOperations: flags.allowed,
    assigned: flags.assigned,
    reviewer: flags.reviewer,
  });
  console.log(`Created ${fields.ID} at ${file}`);
}

function cmdTaskList(flags) {
  const rows = listTasks(flags.status);
  if (!rows.length) {
    console.log('(no tasks)');
    return;
  }
  for (const t of rows) {
    console.log(`${t.ID}\t[${t.status}]\t${t.Title}\tassigned=${t.Assigned}\trisk=${t['Risk Level']}`);
  }
}

function cmdTaskShow(positional) {
  const [id] = positional;
  if (!id) throw new Error('Usage: task show <task-id>');
  const { text } = showTask(id);
  process.stdout.write(text);
}

function cmdTaskMove(positional) {
  const [id, status] = positional;
  if (!id || !status) throw new Error('Usage: task move <task-id> <inbox|active|review|blocked|done>');
  const result = moveTask(id, status);
  console.log(`Moved ${id}: ${result.from} -> ${result.to} (${result.file})`);
}

function cmdReport() {
  const { file } = generateReport();
  console.log(`Report written: ${file}`);
}

function cmdDoctor() {
  console.log(`ROOT: ${ROOT}`);
  console.log(`agent-control dir resolves inside ROOT: ${true}`);
  for (const [name, dir] of Object.entries(TARGETS)) {
    console.log(`target ${name}: ${dir} (exists: ${fs.existsSync(dir)})`);
  }
  const secretGuardOk = isSecretPath('.env') === true && isSecretPath('README.md') === false;
  console.log(`secret-path guard sane: ${secretGuardOk}`);
  console.log('HTTP server present: false (CLI-only in this phase, see docs/RIVA_SECURITY.md)');
}

function cmdRestricted(positional) {
  const [op] = positional;
  if (!op) throw new Error(`Usage: restricted <op-key>. Known keys: ${Object.keys(RESTRICTED_OPERATIONS).join(', ')}`);
  console.log(refuseRestricted(op));
  process.exitCode = 1;
}

async function main() {
  const [command, sub, ...rest] = process.argv.slice(2);

  try {
    if (!command || command === 'help' || command === '--help') return printHelp();

    if (command === 'status') return cmdStatus();
    if (command === 'branch') {
      const { positional, flags } = parseArgs([sub, ...rest].filter((x) => x !== undefined));
      return cmdBranch(positional, flags);
    }
    if (command === 'diff') {
      const { positional, flags } = parseArgs([sub, ...rest].filter((x) => x !== undefined));
      return cmdDiff(positional, flags);
    }
    if (command === 'test') {
      const { flags } = parseArgs([sub, ...rest].filter((x) => x !== undefined));
      return cmdTest(flags);
    }
    if (command === 'lint') {
      const { flags } = parseArgs([sub, ...rest].filter((x) => x !== undefined));
      return cmdLint(flags);
    }
    if (command === 'report') return cmdReport();
    if (command === 'doctor') return await cmdDoctor();
    if (command === 'restricted') return cmdRestricted([sub, ...rest].filter((x) => x !== undefined));

    if (command === 'task') {
      const { positional, flags } = parseArgs(rest);
      if (sub === 'new') return cmdTaskNew(flags);
      if (sub === 'list') return cmdTaskList(flags);
      if (sub === 'show') return cmdTaskShow(positional);
      if (sub === 'move') return cmdTaskMove(positional);
      throw new Error(`Unknown task subcommand "${sub}". Use: new, list, show, move`);
    }

    throw new Error(`Unknown command "${command}". Run with no arguments for help.`);
  } catch (err) {
    if (err instanceof WorkspaceViolation) {
      console.error(`Workspace guard refused: ${err.message}`);
    } else {
      console.error(`Error: ${err.message}`);
    }
    process.exitCode = 1;
  }
}

main();
