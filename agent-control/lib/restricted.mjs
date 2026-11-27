// agent-control/lib/restricted.mjs
//
// Restricted operations are recognized, not automated. There is no bypass
// flag and no "approved" token this tool understands — approval means a
// human runs the real command themselves, deliberately, outside this tool.
// See docs/RIVA_AGENT_WORKFLOW.md ("Restricted Operations").

export const RESTRICTED_OPERATIONS = {
  'db:migrate': 'Database migrations',
  'deps:install': 'Dependency installation (composer/npm install or require)',
  'file:delete': 'Deleting files',
  'env:modify': 'Modifying environment configuration (.env / config/*.php)',
  'deploy:prod': 'Production deployment',
  'payment:config': 'Payment configuration changes',
  'auth:modify': 'Authentication/authorization changes',
  'db:destructive': 'Destructive database operations (drop, truncate, force-seed on non-testing env)',
};

export function refuseRestricted(op) {
  const label = RESTRICTED_OPERATIONS[op];
  if (!label) {
    const keys = Object.keys(RESTRICTED_OPERATIONS).join(', ');
    throw new Error(`Unknown restricted-operation key "${op}". Known keys: ${keys}`);
  }
  return [
    `REFUSED: "${label}" is a restricted operation.`,
    '',
    'The Riva Agent Control Layer does not automate this category of change.',
    'It requires a human (the Product Owner or an operator acting on their behalf)',
    'to run the real command themselves, deliberately, outside this tool.',
    '',
    'See docs/RIVA_AGENT_WORKFLOW.md ("Restricted Operations") for the full list and why.',
  ].join('\n');
}
