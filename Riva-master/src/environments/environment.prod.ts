// Phase 1 Slice 2 / section 23: production environment file, swapped in for
// environment.ts by angular.json's "production" build configuration
// (fileReplacements). REQUIRES OWNER DECISION: the real production API URL is
// not something this change can safely guess — set it below before building
// for production. Left as an obviously-unset placeholder on purpose (rather
// than silently defaulting to localhost, which is what API_BASE_URL used to
// hardcode everywhere) so a misconfigured production build fails loudly
// instead of quietly trying to reach 127.0.0.1.
export const environment = {
  production: true,
  apiBaseUrl: 'REPLACE_WITH_PRODUCTION_API_BASE_URL', // e.g. 'https://api.riva.example.com/api'
};
