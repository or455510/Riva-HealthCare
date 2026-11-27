// agent-control/lib/git.mjs
//
// Every git invocation here uses execFileSync with a fixed argv array —
// never a shell string built from user input — so there is no command
// injection surface regardless of what a task ID or branch name contains
// (those are sanitized separately in workspace.mjs before they ever reach
// this module).

import { execFileSync } from 'node:child_process';

const PROTECTED_BRANCHES = new Set(['main', 'master']);

function git(cwd, args) {
  return execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

export function hasGitRepo(cwd) {
  try {
    git(cwd, ['rev-parse', '--is-inside-work-tree']);
    return true;
  } catch {
    return false;
  }
}

export function currentBranch(cwd) {
  return git(cwd, ['rev-parse', '--abbrev-ref', 'HEAD']).trim();
}

export function statusPorcelain(cwd) {
  return git(cwd, ['status', '--porcelain']).trim();
}

export function recentCommits(cwd, n = 10) {
  try {
    return git(cwd, ['log', `-${n}`, '--oneline'])
      .trim()
      .split('\n')
      .filter(Boolean);
  } catch {
    // Repo exists but has zero commits yet — not an error.
    return [];
  }
}

export function diffText(cwd, staged = false) {
  const args = staged ? ['diff', '--staged'] : ['diff'];
  return git(cwd, args);
}

/**
 * Create an agent/<taskId> (or review/<taskId>) branch off the current HEAD.
 * Refuses on: no repo, dirty working tree, or attempting to name the branch
 * "main"/"master" (defense in depth even though the ID is already sanitized).
 */
export function createTaskBranch(cwd, taskId, kind = 'agent') {
  if (!hasGitRepo(cwd)) {
    throw new Error(
      `No git repository at ${cwd}. This project currently has no git repository initialized ` +
        `(see docs/RIVA_AGENT_WORKFLOW_AUDIT.md §4). Initialize git deliberately, as a human, before creating branches.`
    );
  }
  if (kind !== 'agent' && kind !== 'review') {
    throw new Error('Branch kind must be "agent" or "review".');
  }
  const branch = `${kind}/${taskId}`;
  if (PROTECTED_BRANCHES.has(branch)) {
    throw new Error(`Refusing to create a branch named "${branch}".`);
  }
  const status = statusPorcelain(cwd);
  if (status) {
    throw new Error(
      `Uncommitted changes present in ${cwd} — refusing to branch on top of a dirty working tree. ` +
        `Commit or stash first, then retry.`
    );
  }
  const from = currentBranch(cwd);
  if (PROTECTED_BRANCHES.has(from) === false) {
    // Not required to be on main/master, just recorded for the caller.
  }
  git(cwd, ['checkout', '-b', branch]);
  return { branch, from };
}

export function isProtectedBranch(name) {
  return PROTECTED_BRANCHES.has(name);
}
