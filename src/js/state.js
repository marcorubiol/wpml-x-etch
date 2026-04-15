// Every `let` variable from the original closure lives here as a property.
// Modules import `state` and read/write `state.variableName`.

export const RETURN_STATE_KEY = "zs_wxe_ate_return_state";

export const STATUS_PRIORITY = { not_translated: 0, waiting: 1, needs_update: 2, in_progress: 3, translated: 4, not_translatable: 5 };

export const ICON_SEARCH = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
export const ICON_CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
export const ICON_ARROW = '<svg class="wxe-row-arrow" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12H19"/><path d="M12 5l7 7-7 7"/></svg>';
export const ICON_SPARKLE = '<svg class="wxe-row-sparkle" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.912 5.813a2 2 0 0 0 1.275 1.275L21 12l-5.813 1.912a2 2 0 0 0-1.275 1.275L12 21l-1.912-5.813a2 2 0 0 0-1.275-1.275L3 12l5.813-1.912a2 2 0 0 0 1.275-1.275L12 3z"/></svg>';
export const ICON_SPARKLE_OFF = '<svg class="wxe-row-sparkle" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.912 5.813a2 2 0 0 0 1.275 1.275L21 12l-5.813 1.912a2 2 0 0 0-1.275 1.275L12 21l-1.912-5.813a2 2 0 0 0-1.275-1.275L3 12l5.813-1.912a2 2 0 0 0 1.275-1.275L12 3z"/><line x1="4" y1="4" x2="20" y2="20" stroke-width="2"/></svg>';

export const state = {
  attempts: 0,
  isOpeningTranslation: false,
  pendingReturnState: null,
  selectedLanguageFilter: new Set(),
  selectedStatusFilter: new Set(),
  filterByCurrentContext: true,
  lastBuiltPostId: null,
  refreshInFlight: null,
  activePill: null,
  pillCache: {},
  pillLoading: {},
  scrollPositions: {},
  pillStatuses: {},
  searchQuery: "",
  searchDebounceTimer: null,
  preSearchContext: true,
  preSearchPill: null,
  pillStatusesFetched: false,
  statusRefreshInterval: null,
  contextCheckInterval: null,
  panelChangeObserver: null,
  saveButtonObserver: null,
  teardownNetworkPatches: null,
  isOpeningPanel: false,
  isAiTranslating: false,
  isResyncing: false,
  resyncStatusInterval: null,
  // Last-run snapshots from /resync/status. Two scopes tracked separately:
  // - lastResync:      the global "Force Sync" snapshot { timestamp, stats }
  // - lastResyncLocal: the most recent silent auto-resync that ran after a
  //                    save: { timestamp, stats, post_id }. The post_id lets
  //                    the tooltip decide whether the local snapshot is
  //                    relevant to the post the user is currently viewing.
  lastResync: null,
  lastResyncLocal: null,
  // Site-wide translation health snapshot from /resync/status. Recomputed
  // server-side on every fetch and on every Force Sync completion. Drives
  // the "X of Y translations complete" line in the All Site tooltip row.
  siteHealth: null,
  languagePickerOpen: false,
  langSwitcherExpanded: false,
};
