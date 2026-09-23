/* Phase 10 Slice 1 — Doctor Portal independent shell JS — vanilla, no SPA, reuses existing /queue pattern */
(function(){
'use strict';
// This file is intentionally minimal — main logic is inlined in template for SSR+testability.
// It provides a placeholder to satisfy wp_enqueue_script and future enhancements.
if (typeof window !== 'undefined') {
    window.CPMS_DOCTOR_PORTAL = window.CPMS_DOCTOR_PORTAL || {};
    window.CPMS_DOCTOR_PORTAL.version = 'phase10-s1';
}
})();
