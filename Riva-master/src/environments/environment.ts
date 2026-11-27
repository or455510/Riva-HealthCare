// Phase 1 Slice 2 / section 23: default (development) environment, used by
// `ng serve` and any build configuration that does not swap this file via
// angular.json's fileReplacements. This preserves today's exact working local
// dev behavior — API_BASE_URL used to be hardcoded directly in constants.ts;
// it is now sourced from here instead, so a real deployment can point at a
// real backend by swapping this file per build configuration (see
// environment.prod.ts) without editing application source.
export const environment = {
  production: false,
  apiBaseUrl: 'http://127.0.0.1:8000/api',
};
