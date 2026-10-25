=== CPMS Classic — Disposable Demo Installer ===
Requires at least: 6.9
Requires PHP: 8.1
Requires Plugins: clinic-practice-management
Stable tag: 1.0.0

Temporary, browser-only companion plugin that builds, inspects and removes one
synthetic CPMS Classic demo (Organization Manager, Clinic Manager, Doctor,
Receptionist, Accountant, Patient) on a provably disposable WordPress site.

* No REST route, no AJAX, no frontend code, no WP-CLI requirement.
* Refuses non-empty sites, extra users, further active plugins, multisite,
  non-default WordPress content and any CPMS data beyond its bootstrap rows.
* Passwords are CSPRNG per persona and shown once in a no-store response.
* Teardown is ownership-proofed from live rows and blocks when proof fails.
* The production CPMS plugin is never modified.

See tools/disposable-demo-installer/README.md in the repository for the full
security contract, the acceptance evidence and the Persian Owner guide.
