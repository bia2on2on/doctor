# PROJECT CURRENT STATE

> Recover the project from this file + Git/remote/PR + linked canonical docs.
> Do **not** use a previous chat session as memory.
>
> **Integrated main checkpoint (Phase 10 — Doctor Portal = IN PROGRESS — NOT CLOSED; Phase 10 Slice 1 shell + Today + Live Queue = CLOSED / TECHNICALLY COMPLETE (BOUNDED) via PR #113 + docs #116; Slice 2 queue actions = CLOSED via PR #117 + docs #118; Slice 3 Visit Workspace = CLOSED via PR #120; prescription write = CLOSED via PR #121 (RED + GREEN); recommendations/follow-up = CLOSED via PR #122 + harness fix #123; shared canonical Staff Portal shell + one-time legacy 302 = CLOSED via PR #124; Visit Complete = CLOSED via PR #125; Medical Files in Visit Workspace = CLOSED via PR #126; handwriting/stylus = NEXT and REQUIRED before Phase 10 closure; prescription print = NOT a closure requirement; Phase 11 NOT STARTED; re-verified live 2026-09-26):** `fb861736d0b384ae8b5b54000822f0c2566b234f` — "Merge pull request #126 from bia2on2on/arena/01a0daf5-doctor" (MERGED 2026-09-26T02:22:56Z; merge SHA `fb861736d0b384ae8b5b54000822f0c2566b234f`; verified live via `gh api repos/bia2on2on/doctor/pulls/126 --jq .merge_commit_sha/.merged_at`; open PRs = 0; latest migration `2026_09_20_0022_slot_holds_patient_binding.php` remains latest, 22 files 0001..0022, no 0023). **Phase 10 delivered slices (all SHAs/dates verified live via gh api):** Slice 1 shell + Today + Live Queue = PR #113 MERGED 2026-09-24T08:27:29Z merge `b49a777367a3bdf15f9ad944eab4e65ca70c5b26` + docs closure PR #116 MERGED 2026-09-24T10:43:38Z merge `e9177bcd80c80c59fc08153a6760fb24a3c6c493`; harness fix PR #115 MERGED 2026-09-24T09:33:05Z merge `84d664982517dd98c30678816e068e38c7d541fe` (D-class, NOT a slice); queue actions = PR #117 MERGED 2026-09-24T12:56:18Z merge `87625a03fd92e966de7b13930fc3fb9651138b9f` + docs closure PR #118 MERGED 2026-09-24T13:31:47Z merge `2dbb8d4a5e21b0b7ff6f4d1bc1c8b0332250bf55`; harness fix PR #119 MERGED 2026-09-24T14:09:35Z merge `911be1645c9f3eb1e518c514a3e0e433005bf1f3`; Visit Workspace = PR #120 MERGED 2026-09-24T22:17:29Z merge `972df97cebb23d3877515daf4c46e282ef37935a`; prescription write (RED + GREEN) = PR #121 MERGED 2026-09-25T11:44:07Z merge `4656a66096d8ec5287d67d5304161ce36138a7f9`; recommendations/follow-up = PR #122 MERGED 2026-09-25T14:54:52Z merge `5e202c663ae2304f9b04c69394d7bb80f958c5bf` + harness fix PR #123 MERGED 2026-09-25T15:28:46Z merge `705d14c041bd2ba9d7df5459c58525f2dc6d5690`; shared canonical Staff Portal shell + one-time legacy 302 = PR #124 MERGED 2026-09-25T21:31:34Z merge `aa42888c7ab641c86d1c45c200dfb887861e6ff1`; Visit Complete = PR #125 MERGED 2026-09-25T23:23:25Z merge `f1bb5d49bf92307005fe41af348a1341b1e99f28`; Medical Files in Visit Workspace = PR #126 MERGED 2026-09-26T02:22:56Z merge `fb861736d0b384ae8b5b54000822f0c2566b234f`. **Phase 10 IN PROGRESS — NOT CLOSED; handwriting/stylus is the NEXT capability and REQUIRED before Phase 10 closure; prescription print is NOT a closure requirement; Phase 11 NOT STARTED.** Migration `2026_09_20_0022_slot_holds_patient_binding.php` remains latest (22 files 0001..0022; no 0023).
> **Phase 10 Slice 2 — Doctor Portal queue actions (Call / Start / Recall / Skip) = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #117** (MERGED 2026-09-24T12:56:18Z; merge = `87625a03fd92e966de7b13930fc3fb9651138b9f`; accepted head = `a73c27d2dbf823754d58ded63e2df2f67af74b84`; the accepted RED remains historical evidence and is not rewritten). Bounded delivered scope: **Call · Start · Recall · Skip** reusing the **existing queue state machine** — **no new queue state**; **no migration**; the **selected trusted Location is enforced on mutations when Location scope is present**; **cross-Location mutation is rejected non-enumerably** (canonical `404 CLINIC_NOT_FOUND` before any state change, pre-transaction and post-`FOR UPDATE` alike); **legacy/shared no-Location mutation behavior is preserved** (no global Location rule — the PR #112 mistake is not repeated); the existing **transaction / `FOR UPDATE` / state validation** is retained; the **existing recall limit** is retained; the **Skip reason is required**; the **optional existing room text contract** is retained; Doctor Portal action controls are **state-driven** (WAITING ⇒ Call + Skip; CALLED ⇒ Start + Recall + Skip; otherwise none); the **existing nonce / capability / doctor-ownership** checks are retained; secretary/reception observability continues through the **existing queue history / realtime / `QUEUE_CALLED` notification** (**no new realtime architecture**); Product/security truth only — no new REST route, no new service layer, no shell redesign. This is NOT a release, go-live, V1-complete, commercial-completeness, production-deployment or overall-product-completion declaration.
> **Previous integrated main checkpoint (historical; test-infrastructure / browser-harness correction — NOT a Phase slice; Phase 10 = IN PROGRESS with Slice 1 CLOSED / TECHNICALLY COMPLETE (BOUNDED) at that checkpoint; preserved as historical evidence, not rewritten; superseded as live main by the PR #116 docs-closure merge `e9177bcd80c80c59fc08153a6760fb24a3c6c493` and then by the PR #117 merge `87625a03fd92e966de7b13930fc3fb9651138b9f`):** `84d664982517dd98c30678816e068e38c7d541fe` — "Merge pull request #115 from bia2on2on/arena/01a0d2b1-doctor" (MERGED 2026-09-24T09:33:05Z; merge parents `b49a777367a3bdf15f9ad944eab4e65ca70c5b26` (main before the merge = the merge of **PR #113** — Phase 10 Slice 1 product merge) + `4beaa186aa5fc4f8e5033e0710c376c925ce927e` — the exact accepted PR #115 head). **This checkpoint itself is a test-infrastructure / browser-harness correction only** (a D-class harness fix in **one non-product file**, `clinic-practice-management/bin/pilot-doctor-portal.py`) — **no product scope change, no migration, no phase/slice churn; PR #115 is NOT a Phase slice and is not represented as one.** The superseded earlier documentation attempt **PR #114** was **CLOSED / NOT MERGED** (superseded by the replacement documentation-closure PR; no rebase, no force-push, no empty retrigger commit, source branch preserved) — no stale checkpoint metadata was carried over from it. Open PRs at re-verification = the replacement documentation-closure PR only. Post-merge on the exact merge SHA `84d6649…`: four required workflows terminal-success — CI `35981920078` · Real WordPress Acceptance `35981920109` · Closure Gate `35981920090` · Pilot/Staging Readiness Gate `35981920191` — all `push`→`main` runs bound to `84d6649…`, all retrieved terminal-success live, + 19/19 check runs success; **no queued / in_progress / cancelled run is counted as PASS**. Migration `2026_09_20_0022_slot_holds_patient_binding.php` remains the latest (22 files `0001`..`0022`; no `0023`).
> **Owner Roadmap Phase 10 — Doctor Portal: IN PROGRESS — NOT CLOSED.** The Owner **explicitly authorized the start of Phase 10** (canonical decision record: [`decisions/2026-09-24-phase10-doctor-portal-owner-authorization.md`](decisions/2026-09-24-phase10-doctor-portal-owner-authorization.md) — بخش اول). The first bounded slice was approved as the independent Doctor Portal foundation / Today + Live Queue, and **Today's Appointments was later explicitly approved as a bounded read-only addition to the same first slice**. The previously recorded live state "**Phase 10 = NOT STARTED**" was historically correct at earlier checkpoints and is **preserved as historical evidence, not rewritten** so that Phase 10 does not appear to have always been started.
> **Phase 10 Slice 1 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #113** (MERGED 2026-09-24T08:27:29Z; merge = `b49a777367a3bdf15f9ad944eab4e65ca70c5b26`; accepted head `0ad8b5d5841d75a7992cc53aa22bbe8907fe20cf`). Bounded delivered scope: **independent Doctor Portal shell** (no wp-admin/theme chrome) · **shared WordPress auth/session foundation** · **doctor-only portal boundary** · **server-derived professional identity** · **trusted Clinic scope** · **trusted operational Location scope** · **0/1/N Location selection behavior** (0 eligible ⇒ **fail closed / no data**; 1 eligible ⇒ **auto-resolution allowed**; N>1 ⇒ **explicit Location selection required**; **no first/primary fallback**) · **Location is the operational timezone source** · **Location-local operational day** · **read-only Today summary** · **read-only Live Queue** · **read-only Today's Appointments** (owner-approved bounded read-only addition) · **own-doctor + Clinic + Location isolation** · **multi-location browser evidence** · **Persian/RTL/responsive Doctor Portal shell**. **Closure evidence (retrieved live, exact-head discipline):** post-merge on the exact merge SHA `b49a777…`: four required workflows terminal-success — CI `35975354166` · Real WordPress Acceptance `35975354115` · Closure Gate `35975354213` · Pilot/Staging Readiness Gate `35975354133` — all `push`→`main` runs bound to `b49a777…`, all retrieved terminal-success live; **19/19 check runs `completed`/`success` on the merge SHA** and 19/19 on the accepted head `0ad8b5d…`; **no queued / in_progress / cancelled run is counted as PASS**. **No migration/schema was required** — latest migration **remains `2026_09_20_0022_slot_holds_patient_binding.php`** (22 migration files `0001`..`0022`; no `0023`) and **PR #113 added no migration**. **Legacy/shared behavior truth:** the Doctor Portal's stricter N>1 Location requirement is **portal-specific** (`VisitService::todayForDoctorPortal()`); the **established shared / wp-admin queue behavior was preserved** (`VisitService::today()` legacy path, still serving `/secretary/today` and `/queue`) — **the portal requirement must NOT be misstated as a global rule for every staff queue caller**. **PR history truth:** **PR #112** (branch `arena/01a0cf64-doctor`, head `deb580ccf14d0e21b841de5bccb7c55ad8b2f5b0`) is **historical / superseded — CLOSED, NOT MERGED** (closed 2026-09-24T06:17:23Z); **PR #113** is the **authoritative merged product continuation**, preserving the accepted RED ancestry and forward-only fix history. **Phase 10 REMAINS IN PROGRESS** — at the **Slice 1 checkpoint** the following owner-stated requirements were explicitly **NOT claimed implemented** (**queue actions have since been delivered — see the Phase 10 Slice 2 block above; the remainder stay pending**): broader appointment operations · visit workspace · note authoring UX · prescriptions workflow · handwriting integration · files workflow · patient search · doctor schedule/presence management · doctor practice-location management · finance/visit fee · secretary/cashier workflow · MRN customization/editing. **At the Slice 1 checkpoint the next bounded Phase 10 capability was recorded as: Doctor Portal queue actions — call / start / recall / skip — NOT IMPLEMENTED / pending** (historical — correct for that checkpoint and preserved, not rewritten; **now CLOSED / TECHNICALLY COMPLETE (BOUNDED) via PR #117**). This is NOT a release, go-live, V1-complete, commercial-completeness, production-deployment or overall-product-completion declaration.
> **Previous integrated main checkpoint (historical; Phase 9 FORMAL CLOSURE — Phase 10 was NOT STARTED at this checkpoint; preserved as historical evidence, not rewritten; superseded as live main by the PR #113 merge `b49a777367a3bdf15f9ad944eab4e65ca70c5b26` and then by the PR #115 merge `84d664982517dd98c30678816e068e38c7d541fe`): Phase 9 = CLOSED / TECHNICALLY COMPLETE (BOUNDED) by explicit Owner decision 2026-09-23; Slices 1–3 = CLOSED; Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED); Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Prescriptions = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Files = CLOSED / TECHNICALLY COMPLETE (BOUNDED); no additional Phase 9 capability was required at closure; re-verified live 2026-09-23):** `72bb3fdda418914d9b7b33eed207c3c1de1a951f` — "Merge pull request #110 from bia2on2on/arena/01a0cee2-doctor" (MERGED 2026-09-23T15:53:55Z; merge parents `04d31fb1fb1b1a8e33fbf1bbf4e3803a6d3db3eb` (main before the merge = the merge of **PR #109** — Phase 9 My Files bounded documentation closure) + `ef44d7212e91b45f11b9a9e0d4d1458d65219323` — the exact accepted PR #110 head; open PRs at re-verification = 0).
> **Owner Roadmap Phase 9 — Patient Portal: CLOSED / TECHNICALLY COMPLETE (BOUNDED) — formal Phase 9 closure approved by explicit Owner decision on 2026-09-23** (canonical decision record: [`decisions/2026-09-21-phase9-patient-portal-owner-policy.md`](decisions/2026-09-21-phase9-patient-portal-owner-policy.md) — بخش سوم; closure date 2026-09-23; closed at main `72bb3fdda418914d9b7b33eed207c3c1de1a951f` = main at the Phase-9 closure checkpoint). This is NOT a release, go-live, V1-complete, commercial-completeness, production-deployment or overall-product-completion declaration, and it does **not** claim Staff Portal completion, native mobile completion, future notification-provider completion, Organization Identity activation, or any speculative future feature. **All already-recorded bounded Phase 9 slices remain CLOSED and unchanged** — **Slices 1–3 = CLOSED** via **PR #93** / **PR #96** / **PR #99**, **Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #101**, **Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #103**, **My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #105**, **My Prescriptions = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #107**, **My Files = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #109** (slice evidence unchanged — recorded in the historical blocks below). The implemented Patient Portal sequence (shell + notifications + Profile + Visits + Prescriptions + Files) is complete per owner-policy ordering Profile/Visits/Prescriptions/Files and wireframe §7; **no explicitly required Phase 9 capability remains pending and no additional Phase 9 capability was required at closure**. **Closure checkpoint evidence (retrieved live, exact-head discipline):** post-merge on the exact merge SHA `72bb3fd…`: four required workflows terminal-success — CI `35884923760` · Real WordPress Acceptance `35884923831` · Closure Gate `35884923751` · Pilot/Staging Readiness Gate `35884923752` — all `push`→`main` runs bound to `72bb3fd…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA; **no queued / in_progress / cancelled run is counted as PASS**. **No migration/schema was required** — latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (re-verified live on the `72bb3fd…` tree: 22 migration files `0001`..`0022`, latest = `0022`; no `0023` exists or is reserved) and **PR #110 added no migration**. **Historical READY state preserved as historical evidence, not rewritten:** at the My Files documentation-closure checkpoint `04d31fb1fb1b1a8e33fbf1bbf4e3803a6d3db3eb` Phase 9 was **READY FOR PHASE-CLOSURE DECISION** and explicitly not automatically CLOSED — see the preserved historical block below (no retroactive rewriting of history is performed). **Phase 8 remains CLOSED / TECHNICALLY COMPLETE (BOUNDED)**, **Phase 10 was NOT started at this checkpoint** (its existing roadmap scope was untouched — *historical; Phase 10 is now **IN PROGRESS** with **Slice 1 CLOSED / TECHNICALLY COMPLETE (BOUNDED)** per the Phase 10 checkpoint block above*), and no other phase is marked complete.
> **Previous integrated main checkpoint (historical; preserved as historical READY-state evidence, not rewritten; Phase 9 MY FILES closure — Phase 9 = READY FOR PHASE-CLOSURE DECISION at this checkpoint; Slices 1–3 = CLOSED; Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED); Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Prescriptions = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Files = CLOSED / TECHNICALLY COMPLETE (BOUNDED); re-verified live 2026-09-23; superseded as live main by the PR #110 merge `72bb3fdda418914d9b7b33eed207c3c1de1a951f` and the Phase 9 formal closure block above):** `04d31fb1fb1b1a8e33fbf1bbf4e3803a6d3db3eb` — "Merge pull request #109 from bia2on2on/arena/01a0cdf3-doctor" (MERGED 2026-09-23T15:05:34Z; merge parents `25718f43778904b15221908d51fc0c60d0f8e736` (main before the merge = the merge of **PR #108** — Phase 9 My Prescriptions bounded documentation closure) + `000a551d97b6396e6f8c9ac8428e32a074483e34` — the exact accepted PR #109 head; open PRs at re-verification = 0; no existing closure PR covered #109).
> **Owner Roadmap Phase 9 — Patient Portal: READY FOR PHASE-CLOSURE DECISION — the implemented Patient Portal sequence is complete.** This is NOT a release, go-live, V1-complete, or commercial-completeness declaration, and **the Patient Portal as a whole is NOT claimed CLOSED** — it is **READY FOR PHASE-CLOSURE DECISION** per the bounded documentation closure discipline (no automatically declared Phase CLOSED by inference). **Slices 1–3 = CLOSED** via **PR #93** / **PR #96** / **PR #99**, **Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #101**, **Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #103**, **My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #105**, **My Prescriptions = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #107** (all unchanged — recorded in the historical blocks below). **My Files = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #109** (MERGED 2026-09-23T15:05:34Z; merge `04d31fb…` = current main; accepted head `000a551d97b6396e6f8c9ac8428e32a074483e34` exact-head 19/19 check runs success; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35879016172` · Real WordPress Acceptance `35879015943` · Closure Gate `35879015854` · Pilot/Staging Readiness Gate `35879016279` — all `push`→`main` runs bound to `04d31fb…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA; **no queued / in_progress / cancelled run is counted as PASS**). Bounded scope: **the smallest complete My Files section (patient-visible list + protected download/stream + patient upload using existing C3) inside the already-approved independent CPMS Patient Portal shell (Slice 3)**, delivered on the existing C3 `POST /patients/{patient_id}/files`, C4 `GET /patients/{patient_id}/files` and E17 `GET /files/{id}/stream` routes with an optional integer `link_id` patient-record selector — **no new REST route, no new authentication system, no migration/schema change, no delete/edit/replace/sharing/public-link capability, no framework/router and no shell redesign were introduced.** **Accepted multi-record patient-record selection semantics are now active on C3/C4:** `link_id` is **selector-only** and is never tenant or patient authority (the authenticated WordPress user remains the sole authority; client Clinic / Patient / Organization / role fields do not authorize access); **0 eligible records ⇒ canonical safe not-found `404 CLINIC_NOT_FOUND`; 1 eligible record ⇒ auto-resolution allowed; N>1 eligible records without an explicit selector ⇒ `422 CLINIC_SELECTION_REQUIRED`; invalid / foreign / inactive selector ⇒ canonical non-enumerating `404 CLINIC_NOT_FOUND`** (the same non-enumerating fingerprint as the zero-record case, never a fallback); and there is **no primary/first fallback**. **E17 patient stream authorizes by durable active linked Patient/Clinic membership and checks authorization before storage read** — a patient-visible, non-deleted file of any active Patient record durably linked to the authenticated WP user is downloadable without a client selector (membership establishes authority); foreign/unlinked/inactive/private/deleted files share one non-enumerating `404` fingerprint; denial happens before storage read; headers `Content-Disposition: attachment`, `nosniff`, private no-cache, realpath jail and missing-physical-file generic `404` remain enforced; no storage path leakage. **Patient upload preserves existing visibility/MIME/size/category/rate/storage protections** — forced `patient_visible`, `visit_id = NULL`, real MIME sniffing, extension↔MIME match, category allowlist, per-Clinic size ceiling, 10/hour per-user rate limit, randomized stored filename in protected out-of-webroot storage, `FILE_UPLOADED` audit preserved; no delete/edit/replace. **File dates use Location-derived Jalali only when trusted Location context exists; no date is fabricated when Location context is absent** — visit-linked files gain `created_at_jalali = Jalali::formatYmd(local_date)` via validated chain `file.visit_id → Visit → Location → IANA timezone`; visit-less patient uploads carry no Jalali date and no guessed date; raw backend/storage datetime semantics remain Gregorian UTC `Y-m-d H:i:s.000` and unchanged; no second calendar engine, no `Intl` dependency, no storage/timezone/Location/UTC change. UI display allowlist remains original_filename, mime_type, file_size, category and trusted Jalali date only; internal fields (storage_path, stored_filename, patient_id, clinic_id, visit_id, visibility, audit) are suppressed and file id rides only as technical selector for protected stream. **No migration/schema was required** — latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (re-verified live on the `04d31fb…` tree: 22 migration files `0001`..`0022`, latest = `0022`; no `0023` exists or is reserved) and **PR #109 added no migration**. **Phase 9 is READY FOR PHASE-CLOSURE DECISION** — the implemented Patient Portal sequence (shell + notifications + Profile + Visits + Prescriptions + Files) is complete per owner-policy ordering Profile/Visits/Prescriptions/Files and wireframe §7; no explicitly required Phase 9 capability remains pending per live authoritative roadmap/SRS/owner-policy/current-state/wireframes/decisions (see closure determination below); this is not an automatic Phase CLOSED declaration, and no future contract is invented. Phase 8 remains **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** and no other phase is marked complete.

> **Previous integrated main checkpoint (historical; Phase 9 MY PRESCRIPTIONS closure — Phase 9 = IN PROGRESS; Slices 1–3 = CLOSED; Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED); Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Prescriptions = CLOSED / TECHNICALLY COMPLETE (BOUNDED); re-verified live 2026-09-23; superseded as live main by the PR #109 merge `04d31fb…` and the Phase 9 My Files closure block above):** `d0644706324be211e7fe68d1f9f2b8232357f704` — "Merge pull request #107 from bia2on2on/arena/01a0cd28-doctor" (MERGED 2026-09-23T09:59:20Z; merge parents `cfc13ab64938666e2587f1cbaf132c8d6d418307` (main before the merge = the merge of **PR #106** — Phase 9 My Visits bounded documentation closure) + `d0f390e997fb09e22aa00701170bc01161407153` — the exact accepted PR #107 head; open PRs at re-verification = 0; no existing closure PR covered #107).


> **Previous integrated main checkpoint (historical; Phase 9 MY VISITS closure — Phase 9 = IN PROGRESS; Slices 1–3 = CLOSED; Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED); Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Prescriptions = NOT IMPLEMENTED / pending; re-verified live 2026-09-23; superseded as live main by the PR #107 merge `d064470…` and the Phase 9 My Prescriptions closure block above):** `f09a81c132d545d1d1657029632a8b069515dd26` — "Merge pull request #105 from bia2on2on/arena/01a0cb00-doctor" (MERGED 2026-09-23T04:37:56Z; merge parents `ed7db7acfa56129035d762e8388c86fded8b7c24` (main before the merge = the merge of **PR #104** — Phase 9 Slice 4 Patient Profile UI bounded documentation closure) + `3e7d2103044ef6282953f04dea9f8c47bb18443f` — the exact accepted PR #105 head, i.e. the owner-requested Jalali/Shamsi display-correction head; open PRs at re-verification = 0; no existing closure PR covered #105).
> **Owner Roadmap Phase 9 — Patient Portal: IN PROGRESS — NOT complete.** This is NOT a release, go-live, V1-complete, or commercial-completeness declaration, and **the Patient Portal as a whole is NOT claimed complete**. **Slices 1–3 = CLOSED** via **PR #93** / **PR #96** / **PR #99**, **Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #101** and **Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #103** (all unchanged — recorded in the historical blocks below). **My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #105** (MERGED 2026-09-23T04:37:56Z; merge `f09a81c…` = current main; accepted head `3e7d2103044ef6282953f04dea9f8c47bb18443f` exact-head 19/19 check runs success; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35819115348` · Real WordPress Acceptance `35819115341` · Closure Gate `35819115342` · Pilot/Staging Readiness Gate `35819115349` — all `push`→`main` runs bound to `f09a81c…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA; **no queued / in_progress / cancelled run is counted as PASS**). Bounded scope: **the smallest read-only My Visits section (list + detail) inside the already-approved independent CPMS Patient Portal shell (Slice 3)**, delivered on the existing C5 `GET /clinic/v1/visits` and C6 `GET /clinic/v1/visits/{id}` routes with an optional integer `link_id` patient-record selector — **no new REST route, no new authentication system, no migration/schema change, no write/edit/delete capability, no Prescriptions-list or Files UI, no framework/router and no shell redesign were introduced.** **Accepted patient-record selector semantics now active for C5/C6:** `link_id` is **selector-only** and is never tenant or patient authority (the authenticated WordPress user remains the sole authority; client Clinic / Patient / Organization / role fields do not authorize access); **0 eligible records ⇒ canonical safe not-found `404 CLINIC_NOT_FOUND`; 1 eligible record ⇒ auto-resolution allowed; N>1 eligible records without an explicit selector ⇒ `422 CLINIC_SELECTION_REQUIRED`; invalid / foreign / inactive / non-matching-persisted-Clinic selector ⇒ canonical non-enumerating `404 CLINIC_NOT_FOUND`** (the same non-enumerating fingerprint as the zero-record case, never a fallback); and there is **no primary/first fallback in C5/C6**. Existing C5 date defaults / ordering / 100-row cap, the persisted Patient and Clinic checks, the existing forbidden-access audit and the existing query-level patient-visible note / recommendation visibility rules are retained. **Jalali/Shamsi display:** patient-visible visit dates are displayed in **Jalali/Shamsi** form through an additive paired presenter field (`visit_jalali = ClinicCore\Domain\Time\Jalali::formatYmd(visit_date)` on C5 rows and on the C6 `visit` object) while **raw backend/storage `visit_date` semantics remain Gregorian `Y-m-d` and unchanged** — no second calendar engine, no `Intl` dependency, and no change to storage, timezone, Location or UTC semantics. The UI display allowlist remains visit date / clinician and patient-visible note text / recommendation text; actor IDs, correction reasons, workflow enums and unresolved metadata are not rendered, and no Prescriptions or Files surface is added. **No migration/schema was required** — latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (re-verified live on the `f09a81c…` tree: 22 migration files `0001`..`0022`, latest = `0022`; no `0023` exists or is reserved) and **PR #105 added no migration**. **Phase 9 is NOT complete and the Patient Portal is NOT claimed complete; the next defined Patient Portal capability is “My Prescriptions” (per the owner-policy section ordering Profile/Visits/Prescriptions/Files) and it remains NOT IMPLEMENTED / pending — not started, not scoped, and no future contract is invented by this documentation-only closure.** Phase 8 remains **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** and no other phase is marked complete.
>
> **Previous integrated main checkpoint (historical; Phase 9 Slice 4 PATIENT PROFILE UI closure — Phase 9 = IN PROGRESS; Slices 1–3 = CLOSED; Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED); Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED); My Visits = NOT IMPLEMENTED / pending; re-verified live 2026-09-22; superseded as live main by the PR #105 merge `f09a81c…` and the Phase 9 My Visits closure block above):** `9909ce184c973e700c838db1c74095eac5c8553c` — "Merge pull request #103 from bia2on2on/arena/01a0c531-doctor" (MERGED 2026-09-22T16:03:18Z; merge parents `cc45176ceeffade6ce9a87ec986dca15f6305714` (merge of **PR #102** — Phase 9 Slice 4 backend-foundation bounded documentation closure) + `db19e97a630f4900f9ac12f19563a71c6ea5518c` — the exact accepted PR #103 head; open PRs at re-verification = 0; no existing closure PR covered #103).
> **Owner Roadmap Phase 9 — Patient Portal: IN PROGRESS — NOT complete.** This is NOT a release, go-live, V1-complete, or commercial-completeness declaration, and **the Patient Portal as a whole is NOT claimed complete**. **Slices 1–3 = CLOSED** via **PR #93** / **PR #96** / **PR #99** and **Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #101** (all unchanged — recorded in the historical blocks below). **Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #103** (MERGED 2026-09-22T16:03:18Z; merge `9909ce1…` = current main; accepted head `db19e97a630f4900f9ac12f19563a71c6ea5518c` exact-head 28/28 check runs success; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35751521914` · Real WordPress Acceptance `35751521858` · Closure Gate `35751521808` · Pilot/Staging Readiness Gate `35751521838` — all `push`→`main` runs bound to `9909ce1…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA). Bounded scope: **the smallest complete Patient Profile section inside the already-approved independent CPMS Patient Portal shell (Slice 3)** — Profile navigation + Profile section (Clinic label + current profile data); profile-record selector over only server-linked active Clinic-level Patient records (explicit choice required for N>1; reusing the Slice 4 backend-foundation contracts `GET /patient/my-records` C0 + `GET/PUT /patient/me?link_id?` C1/C2 — `422 CLINIC_SELECTION_REQUIRED` / `404 CLINIC_NOT_FOUND` / `400 CLINIC_VALIDATION_FAILED`; no backend selector rewrite); `ME_EDITABLE` field whitelist with read-only login mobile; save through the existing REST (`link_id` + `wp_rest` nonce) with Persian success/error regions. **No migration, no new REST route, no mobile-change flow, no SPA/router/framework, no shell redesign, and no Staff Portal changes were introduced.** **Owner visual approval for the Slice 4 Patient Profile UI = COMPLETE** (owner statement recorded by this closure — PR #103 carries no separate GitHub review artifact: 0 reviews; this satisfies the owner-policy "one bounded visual review" for the Profile UI only). **No migration/schema was required** — latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (re-verified live on the `9909ce1…` tree: 22 migration files `0001`..`0022`, latest = `0022`; no `0023` exists or is reserved). **Phase 9 is NOT complete; the next defined Patient Portal capability (clinical history / Visits, per the owner-policy section ordering Profile/Visits/Prescriptions/Files) remains NOT IMPLEMENTED and was not started or scoped by this documentation-only closure; no future contract is invented.** Phase 8 remains **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** and no other phase is marked complete.
>
> **Previous integrated main checkpoint (historical; Phase 9 Slice 4 BACKEND FOUNDATION closure — Phase 9 = IN PROGRESS; Slices 1–3 = CLOSED; Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED); Slice 4 Patient Profile UI = NOT IMPLEMENTED / pending; re-verified live 2026-09-21; superseded as live main by the PR #103 merge `9909ce1…` and the Phase 9 Slice 4 Patient Profile UI closure block above):** `28e7fbf61ec4e783f81dc3ecfd7ba92e6a2b8380` — "Merge pull request #101 from bia2on2on/arena/01a0c4ae-doctor" (MERGED 2026-09-21T17:40:46Z; merge parents `3c1db8250f24cff509b7a544317c01043c1e1244` (merge of **PR #100** — Phase 9 Slice 3 bounded documentation closure) + `d0580bebccdeee66d492a4b1607c7c223c4b8ff2` — the exact accepted PR #101 head; open PRs at re-verification = 0; no existing closure PR covered #101).
> **Owner Roadmap Phase 9 — Patient Portal: IN PROGRESS — NOT complete.** This is NOT a release, go-live, V1-complete, or commercial-completeness declaration, and **the Patient Portal as a whole is NOT claimed complete**. **Slices 1–3 = CLOSED** via **PR #93** / **PR #96** / **PR #99** (unchanged — recorded in the historical blocks below). **Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #101** (MERGED 2026-09-21T17:40:46Z; merge `28e7fbf…` = current main; accepted head `d0580bebccdeee66d492a4b1607c7c223c4b8ff2` exact-head check runs success; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35633544036` · Real WordPress Acceptance `35633543948` · Closure Gate `35633543996` · Pilot/Staging Readiness Gate `35633544127` — all `push`→`main` runs bound to `28e7fbf…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA). Bounded scope: **Slice 4 backend foundation delivered linked-Patient profile record selection and scoped profile update**: (1) patient may select only active Clinic-level Patient records durably linked to the authenticated WP account (`GET /patient/my-records` C0 returns array of `{link_id, clinic_id, clinic_name, patient_id, patient_display_name, mrn, is_primary}`); (2) `link_id` is a selector only, never authority; (3) N>1 active links without explicit `link_id` selector fails closed with `422 CLINIC_SELECTION_REQUIRED`; (4) no primary/first fallback when N>1; exactly 1 active link auto-resolves for backwards compatibility; 0 active links or unknown/unlinked selector fails closed with `404 CLINIC_NOT_FOUND`; (5) no cross-Clinic merge or automatic link creation; (6) mobile/login identity remains read-only; (7) National ID remains Clinic-scoped in current storage, validated, editable in Profile, and is NOT a booking requirement; competing same-Clinic national ID conflict fails closed with `400 CLINIC_VALIDATION_FAILED` before mutation/audit; (8) Organization Identity synchronization remains outside Slice 4; (9) **Slice 4 Patient Profile UI remains NOT IMPLEMENTED / pending**; no UI work was delivered or started. **No migration/schema was required** — latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (re-verified live on the `28e7fbf…` tree: 22 migration files `0001`..`0022`, latest = `0022`; no `0023` exists or is reserved). **Phase 9 is NOT complete; Profile UI remains pending; no next product work was started by this documentation-only closure**; Phase 8 remains **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** and no other phase is marked complete.
>
> **Previous integrated main checkpoint (historical; Phase 9 Slice 3 closure — Phase 9 = IN PROGRESS; Slice 1 = CLOSED; Slice 2 = CLOSED; Slice 3 = CLOSED / TECHNICALLY COMPLETE (BOUNDED); re-verified live 2026-09-21; superseded as live main by the PR #101 merge `28e7fbf…` and the Phase 9 Slice 4 backend-foundation closure block above):** `8a324e59341851f5ced01ed37ecd5e69c725f30a` — "Merge pull request #99 from bia2on2on/arena/01a0c3f2-doctor" (MERGED 2026-09-21T14:53:05Z; merge parents `352a093649265063fc055585fed7bacafbb37453` (merge of **PR #98** — owner-issued Portal Architecture Contract + standalone-shell theme-independence technical proof; **NOT a phase slice**) + `8d6231c19d3caccb94194836040f575a1566d345` — the exact accepted PR #99 head; open PRs at re-verification = 0; no existing closure PR covered #99).
> **Owner Roadmap Phase 9 — Patient Portal: IN PROGRESS — NOT complete.** This is NOT a release, go-live, V1-complete, or commercial-completeness declaration, and **the Patient Portal as a whole is NOT claimed complete**. **Slice 1 = CLOSED** via **PR #93** and **Slice 2 = CLOSED** via **PR #96** (unchanged — recorded in the historical blocks below). **Slice 3 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #99** (MERGED 2026-09-21T14:53:05Z; merge `8a324e59…` = current main; accepted head `8d6231c19d3caccb94194836040f575a1566d345` exact-head 19/19 check runs success — CI `35608010833` · Real WordPress Acceptance `35608010848` · Closure Gate `35608005843` · Pilot/Staging Readiness Gate `35608005844`; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35615159555` · Real WordPress Acceptance `35615159562` · Closure Gate `35615159508` · Pilot/Staging Readiness Gate `35615159515` — all `push`→`main` runs bound to `8a324e59…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA). Bounded scope: **the independent CPMS Patient Portal shell is now production code on main** — `src/Frontend/PatientPortalShell.php` + plugin-owned standalone full-document template `templates/patient-portal-shell.php` + dedicated portal stylesheet `assets/css/cpms-patient-portal.css`, delivered through the PR #98 mechanism (CPMS-owned WordPress Page `cpms-patient-portal` + plugin-owned `template_include` interception + plugin-owned standalone template; no rewrite framework); pure-patient `login_redirect` and pure-patient wp-admin GETs (including the legacy `page=cpms-patient` console) now target the frontend portal (POST/AJAX/REST/CLI untouched), so **the Patient Portal no longer uses wp-admin as its operational UI** and **the active WordPress Theme does not own the Patient Portal shell presentation**; the existing Slice 1/2 portal content (appointments + self-cancel, notifications + unread badge + mark-all-read), the WordPress session and the `wp_rest` nonce are reused — **no new REST endpoint, no new authentication system and no migration/schema change was introduced**. **Owner visual approval for the Slice-3 shell = COMPLETE at the accepted head `8d6231c1…`** (owner statement recorded by this closure — PR #99 carries no separate GitHub review artifact: 0 reviews, 0 human comments; this satisfies the owner-policy "one bounded visual review" for the shell only, not for any later portal section). Evidence honesty: the PR title retains its pre-merge "test(red) … (RED)" wording — Git history is deliberately not rewritten; the merged final state carries the GREEN product implementation (GREEN commit `ca659193…` → accepted head `8d6231c1…`), proven by the check runs on the merge SHA. **PR #98** (merge `352a0936…`, MERGED 2026-09-21T12:14:14Z; head `4cdf1e06…`) remains the owner-issued Portal Architecture Contract + standalone-shell technical proof — **the governing architecture decision for the Patient Portal shell, NOT a phase slice** — recorded in [`decisions/2026-09-21-phase9-patient-portal-owner-policy.md`](decisions/2026-09-21-phase9-patient-portal-owner-policy.md) (owner-issued policy, NOT original SRS wording; preserved, not rewritten). **Phase 9 is NOT complete; no further Phase 9 slice (Profile/Visits/Prescriptions/Files or any other) is scoped, started, or claimed by this documentation-only closure**; Phase 8 remains **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** and no other phase is marked complete.
> **Latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php`** (re-verified live on the `8a324e59…` tree: 22 migration files `0001`..`0022`, latest = `0022`; no `0023` exists or is reserved). Phase 9 Slice 3 required **no** migration/schema change. The "`0022` is now/remains the latest" wordings in the historical blocks below are the state of their own checkpoints and are preserved as history, not rewritten. This is **not** a release/V1/commercial claim (documentation-only closure).
>
> **Previous integrated main checkpoint (historical; Phase 9 Slice 2 closure — Phase 9 = IN PROGRESS; Slice 1 = CLOSED; Slice 2 = CLOSED / TECHNICALLY COMPLETE (BOUNDED); re-verified live 2026-09-21; superseded as live main by the PR #98 merge `352a0936…` and the Phase 9 Slice 3 closure block above):** `c18c18f4a33e3690d5002f6b21d56ad60334cdde` — "Merge pull request #96 from bia2on2on/arena/01a0c10e-doctor" (MERGED 2026-09-21T09:08:17Z; merge parents `28f51144ece98420ae9052e55e7f821156a37787` (merge of **PR #95** — Phase 9 Slice 1 bounded documentation closure) + `0f17aa2e562dd45b2bc0f1bfdd19b1895a093940` — the exact accepted PR #96 head; open PRs at re-verification = 0).
> **Owner Roadmap Phase 9 — Patient Portal: IN PROGRESS — NOT complete.** This is NOT a release, go-live, V1-complete, or commercial-completeness declaration, and **the Patient Portal as a whole is NOT claimed complete**. **Slice 1 = CLOSED** via **PR #93** (unchanged — recorded in the historical block below). **Slice 2 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #96** (MERGED 2026-09-21T09:08:17Z; merge `c18c18f…` = main at the Slice-2 closure checkpoint, since superseded as live main by the PR #98 merge `352a0936…` and the Phase 9 Slice 3 merge `8a324e59…`; accepted head `0f17aa2e562dd45b2bc0f1bfdd19b1895a093940` exact-head 28/28 check runs success; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35581581186` · Real WordPress Acceptance `35581581172` · Closure Gate `35581581149` · Pilot/Staging Readiness Gate `35581581181` — all `push`→`main` runs bound to `c18c18f…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA). Bounded scope: **Slice 2 delivered the Internal Patient Portal notification list, unread badge and explicit mark-all-read using the existing notification backend** — **no migration, no new REST route and no provider implementation was introduced**. Evidence honesty: the PR title retains its pre-merge "test(red) … (RED)" wording — Git history is deliberately not rewritten; the merged final state carries the GREEN product implementation, proven by the check runs on the merge SHA. **PR #95** (merge `28f51144…`) remains the Phase 9 Slice 1 documentation-only closure — **not** a phase slice. **OWNER-ISSUED PRODUCT POLICY (2026-09-21) recorded durably** in [`decisions/2026-09-21-phase9-patient-portal-owner-policy.md`](decisions/2026-09-21-phase9-patient-portal-owner-policy.md): (A) portal identity — only the Admin portal visually resides inside wp-admin; the Patient Portal must use an independent professional CPMS frontend shell without WordPress admin sidebar/toolbar/update notices/admin footer/version strings; (B) visual quality — professional, simple, Persian/RTL, responsive; final visual appearance of user-facing portals requires owner visual approval — **functional acceptance of PR #96 is NOT final visual-design approval**, and this is one bounded visual review, then fix only concrete owner feedback; (C) Phase 9 ordering — establish the independent professional Patient Portal shell BEFORE major sections (Profile/Visits/Prescriptions/Files); (D) notification channels — CURRENT: Internal Patient Portal notifications + SMS; FUTURE: optional external channels (e.g. Telegram / Iranian messaging platforms) behind explicit patient consent/preferences, event/domain logic kept separable from delivery transport, no premature provider classes/tables/settings/schema; (E) engineering direction — shortest clear professional implementation before abstractions, future extensibility without speculative complexity, WordPress/PHP matrix compatibility, responsive performance-aware portals, strong admin customization only where a real product setting is required. These are **owner-issued product policy, NOT original SRS requirements** — the historical SRS wording is preserved, not rewritten. **Phase 9 is NOT complete; no Phase 9 Slice 3 is scoped, started, or claimed here, and the Patient Portal shell is NOT started by this documentation-only closure**; Phase 8 remains **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** and no other phase is marked complete.
> **Latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php`** (re-verified live on `c18c18f…`: 22 migration files `0001`..`0022`, latest = `0022`). Phase 9 Slice 2 required **no** migration/schema change. The "`0022` is now/remains the latest" wordings in the historical blocks below are the state of their own checkpoints and are preserved as history, not rewritten. This is **not** a release/V1/commercial claim (documentation-only closure).
>
> **Previous integrated main checkpoint (historical; Phase 9 Slice 1 closure — Phase 9 = STARTED / IN PROGRESS; Slice 1 = CLOSED (TECHNICAL; BOUNDED); re-verified live 2026-09-20; superseded as live main by the Phase 9 Slice 2 closure block above):** `a593958521386d459f76c5efa839d9962e1f67fc` — "Merge pull request #93 from bia2on2on/arena/01a0c007-doctor" (MERGED 2026-09-20T21:52:03Z; merge parents `b6c34c116807c99105aa603fc71ee220475a47a7` (merge of **PR #94** — ci(wpcs) added-lines collector fix + WPCS ratchet governance) + `9e7058cf9768ccb4dfce56eafbfb2c85f5e7c9bb` — the exact accepted PR #93 head; open PRs at re-verification = 0).
> **Owner Roadmap Phase 9 — Patient Portal: STARTED / IN PROGRESS — NOT complete.** This is NOT a release, go-live, V1-complete, or commercial-completeness declaration, and **the Patient Portal as a whole is NOT claimed complete**. **Slice 1 = CLOSED (TECHNICAL; BOUNDED)** via **PR #93** (MERGED 2026-09-20T21:52:03Z; merge `a593958…` = main at the Slice-1 closure checkpoint, since superseded as live main by the Phase 9 Slice 2 merge `c18c18f…`; accepted head `9e7058cf9768ccb4dfce56eafbfb2c85f5e7c9bb` exact-head 19/19 checks success; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35539966134` · Real WordPress Acceptance `35539966131` · Closure Gate `35539966142` · Pilot/Staging Readiness Gate `35539966158` — all `push`→`main` runs bound to `a593958…`, all retrieved terminal-success live). Bounded scope: **patient self-cancel delivered from the existing patient-portal surface using the existing B4 backend** (`POST clinic/v1/appointments/{id}/cancel`) — **no new REST route** and **no schema change**. Evidence honesty: the PR title retains its pre-merge "test(red) … (RED)" wording — Git history is deliberately not rewritten; the merged final state carries the GREEN product implementation, proven by the 19/19 check runs on the merge SHA. **PR #94** (merge `b6c34c11…`) remains a merged CI-infrastructure fix + WPCS ratchet governance — **not** a phase slice; the WPCS ratchet governance recorded from #94 is preserved unchanged. **Phase 9 is NOT complete and no Phase 9 Slice 2 is scoped, started, or claimed here** *(the state of that checkpoint — Slice 2 was subsequently delivered and closed via PR #96, per the block above)*; Phase 8 remains **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** and no other phase is marked complete.
> **Latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php`** (re-verified live on `a593958…`: 22 migration files `0001`..`0022`, latest = `0022`). Phase 9 Slice 1 required **no** migration/schema change. The "`0022` is now the latest" wording in the Phase-8 Slice-3 block below is the state of its own checkpoint and is preserved as history, not rewritten. This is **not** a release/V1/commercial claim (documentation-only closure).
>
> **Previous integrated main checkpoint (historical; Phase 8 Slice 3 closure — Phase 8 = CLOSED / TECHNICALLY COMPLETE (BOUNDED); re-verified live 2026-09-20; superseded as live main by the Phase 9 Slice 1 closure block above):** `9abd3379a02a320705cadac5229726bbd1b202cb` — "Merge pull request #90 from bia2on2on/arena/01a0be60-doctor" (MERGED 2026-09-20T17:04:39Z; merge parents `7873d46eab1c47cc0161c8f5ebb4205938976d16` + `0f044eb518d1e9a3c91f48a49a05df4f5c62ba83` — the exact accepted PR #90 head; open PRs at re-verification = 0).
> **Owner Roadmap Phase 8 — Patient Public Booking: CLOSED / TECHNICALLY COMPLETE (BOUNDED).** This is NOT a release, go-live, V1-complete, or commercial-completeness declaration. **Slice 1 = CLOSED** via **PR #87** (MERGED 2026-09-19T20:43:04Z; merge `4122539…`; approved head `0643e2b59479d73021c20508d82e20ee5bde06b5` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35468244421` · Real WordPress Acceptance `35468244416` · Closure Gate `35468244423` · Pilot/Staging Readiness Gate `35468244448`). **Slice 2 = CLOSED** via **PR #89** (MERGED 2026-09-20T09:24:19Z; merge `440ba7e…`; approved head `70d033cc3121519e882e2b18d4ada8ac1a303f81` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35502216463` · Real WordPress Acceptance `35502216468` · Closure Gate `35502216456` · Pilot/Staging Readiness Gate `35502216452`). **Slice 3 = CLOSED / MERGED via PR #90** — linked-Patient booking-subject selection (the PR title retains its pre-merge "test(red) … (RED)" wording — Git history is deliberately not rewritten; the merged final state carries the GREEN product implementation, proven by the 19/19 check runs on the merge SHA) (MERGED 2026-09-20T17:04:39Z; merge `9abd3379…` = main at the Phase-8 closure checkpoint, since superseded as live main by the Phase 9 Slice 1 merge `a593958…`; accepted head `0f044eb518d1e9a3c91f48a49a05df4f5c62ba83` exact-head 19/19 checks success; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35524710717` · Real WordPress Acceptance `35524710714` · Closure Gate `35524710737` · Pilot/Staging Readiness Gate `35524710697` — all `push`→`main` runs bound to `9abd3379…`, all retrieved terminal-success live). **PR #88** (merge `e00cec8d…`) remains the relevant non-slice multi-Clinic-safe REST-bootstrap/booking-settings hardening fix — not a Phase 8 slice. The owner-issued policy distinctions already recorded for Slices 1–3 (post-auth Hold timing; new-Patient minimum names; Clinic-bound OTP challenge; deterministic OTP WP-user reuse; linked-Patient Slice-3 policy) remain owner policy — the historical SRS wording is deliberately not rewritten to pretend it originally specified them. No open Phase 8 product-code gap was found in the completed scoping pass; no further Phase 8 slice is claimed; no other phase is marked complete; the open deferred items (`booking.max_future_days` 30-vs-60, A1 min-lead visibility, anonymous A1/A4 rate-limit policy, cross-Clinic OTP cooldown/attempt coupling, Patient Portal / Phase 9, full patient profile, unrelated technical debt) are **not** pulled into Phase 8 by this closure.
> **Latest main migration is now `2026_09_20_0022_slot_holds_patient_binding.php`** (re-verified live on `9abd3379…`: 22 migration files `0001`..`0022`, latest = `0022` — `cpms_slot_holds.patient_id` BIGINT UNSIGNED NULL, FK → `cpms_patients(id)` RESTRICT, no backfill; merged by PR #90 within the accepted Slice-3 scope). The `0021`-latest / "`0022` does NOT exist on main" statements in the block below and the `0020`-latest statements in the historical blocks below that are the state of their own checkpoints and are preserved as history, not rewritten. This is **not** a release/V1/commercial claim (documentation-only closure).
>
> **Previous integrated main checkpoint (historical; Phase 8 Slices 1+2 — bounded post-merge documentation closure; re-verified live 2026-09-20; superseded as live main by the Phase 8 Slice 3 closure block above):** `440ba7e7d07579036128f288f502d390d27c98d3` — "Merge pull request #89 from bia2on2on/arena/01a0bba9-doctor" (MERGED 2026-09-20T09:24:19Z; merge parents `4122539885cadb28d69f62e765ee3368b50b0509` + `70d033cc3121519e882e2b18d4ada8ac1a303f81`; open PRs at re-verification = 1: draft **PR #90** `arena/01a0be60-doctor` @ `c17c965d32ec056f3e53be280173dc5182ef1c2e` — base `main`; **not touched by this closure**). Preceding main: `4122539885cadb28d69f62e765ee3368b50b0509` (PR #87 — Phase 8 Slice 1, MERGED 2026-09-19T20:43:04Z), `e00cec8dedd3245eb0c7dbfe772775c04e9af1f8` (PR #88 — multi-Clinic-safe REST-bootstrap/booking-settings hardening fix; not a Phase 8 slice, MERGED 2026-09-19T17:52:42Z) and `97efdb450d3e6238a8380c541d71a5122a7daada` (PR #86 — Phase 7 bounded documentation closure, MERGED 2026-09-19T12:55:30Z).
> **Owner Roadmap Phase 8 — Patient Public Booking: IN PROGRESS — NOT complete.** **Slice 1 = CLOSED (BOUNDED)** — public anonymous read-only booking **browse** surface (`[cpms_public_booking clinic_id="N"]`; existing A1/A4 reused; no PHI; no new REST routes; no migration) via **PR #87** (MERGED 2026-09-19T20:43:04Z; merge `4122539…`; approved head `0643e2b59479d73021c20508d82e20ee5bde06b5` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35468244421` · Real WordPress Acceptance `35468244416` · Closure Gate `35468244423` · Pilot/Staging Readiness Gate `35468244448`). **Slice 2 = CLOSED / MERGED via PR #89** — public booking **OTP → authenticated Hold → final confirm** completion (MERGED 2026-09-20T09:24:19Z; merge `440ba7e…` = current main; approved head `70d033cc3121519e882e2b18d4ada8ac1a303f81` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35502216463` · Real WordPress Acceptance `35502216468` · Closure Gate `35502216456` · Pilot/Staging Readiness Gate `35502216452` — + 19/19 check runs success). **Slice 3 = ACTIVE in draft PR #90 only** (`test(red): Phase 8 Slice 3 — linked-Patient booking-subject selection (RED)`; head `c17c965d…`) — **NOT accepted, NOT merged; no Slice-3 closure claim exists at this checkpoint.** Phase 8 as a whole is NOT complete; no other Phase 8 slice is claimed.
> **Phase 8 Slice 2 scope (PR #89, recorded without product re-litigation):** authenticated continuation of the Slice-1 anonymous surface — A2 `otp/request` executes with the Clinic derived **only** from persisted selection data (raw `clinic_id` body claims are never authority; multi-Clinic without selection fails closed `CLINIC_SCOPE_REQUIRED`); A3 `otp/verify` executes under the Clinic persisted on the challenge; deterministic OTP patient-user reuse (`{mobile}@otp.cpms.local`; same `user_id`, `is_new_user=false`, no duplicate `wp_users` row) — fail-closed on bridge-email collision (`CLINIC_OTP_INVALID` envelope, no session, no disclosure, no role conversion — regression T26); authenticated B1 creates the standard Hold (existing TTL/`atomicHold`; FR-4.6 `nearby_slots` policy unchanged); B2 confirms with idempotent same-key replay (same `reference_code`); expired/foreign holds fail closed. Merged integration suite: `tests/Integration/Phase8Slice2OtpHoldConfirmRedTest.php` (26 tests).
> **Owner-issued product policies for Slice 2 (NOT original SRS wording — recorded as owner policy resolving FR-4.2/UC-02 ambiguity; the historical SRS text is deliberately not rewritten):** (1) **Hold-Timing** — pre-authentication never consumes capacity: only the non-PHI selection is persisted; no anonymous Hold, no `held_count` movement before a real authenticated session; the Hold is exclusively the authenticated B1 act. (2) **New-Patient minimum identity** — a genuinely new Patient in `hold.clinic_id` requires non-blank `first_name`/`last_name` at B2 (400 `CLINIC_VALIDATION_FAILED`; Hold stays active and retryable); existing-Patient names are ignored (B2 is never a profile-edit route). No full profile in this slice. Canonical decision record: [`decisions/2026-09-20-phase8-slice2-owner-decisions.md`](decisions/2026-09-20-phase8-slice2-owner-decisions.md).
> **Latest main migration is now `2026_09_20_0021_otp_tokens_clinic_binding.php`** (re-verified live on `440ba7e…`: 21 migration files `0001`..`0021`, latest = `0021` — `cpms_otp_tokens.clinic_id` nullable FK binding the OTP challenge to the appointment Clinic; justified by the Slice-2 owner decision; created and merged by PR #89). The `0020`-latest statements in the historical blocks below are the state of their own checkpoints and are preserved as history, not rewritten. **Migration `0022` does NOT exist on main** — `2026_09_20_0022_slot_holds_patient_binding.php` exists **only** on draft PR #90's branch (`arena/01a0be60-doctor`) as that PR's own reservation; it must **not** be described as main's migration and is **not** approved by this checkpoint. If new schema is required beyond this: STOP and ask Owner. This is **not** a release/V1/commercial claim (documentation-only closure).
>
> **Previous integrated main checkpoint (historical; Phase 7 — bounded documentation closure; re-verified live 2026-09-19; superseded as live main by the Phase 8 Slices 1+2 block above):** `d7484ceddf698898483122cf52bb5abb55718744` — "Merge pull request #85 from bia2on2on/arena/01a0b8a0-doctor" (MERGED 2026-09-19T12:04:04Z; open PRs at re-verification = 0). Preceding main: `cf1ace137922d84b7042841e68629aedfb8334a8` (PR #83, MERGED 2026-09-19T07:19:56Z) and `8c05c831aea6806038521bfb11fb6bcc5aa49cce` (PR #84 — ci(pilot-gate) release-zip artifact pin; CI/tooling only, MERGED 2026-09-19T06:54:45Z).
> **Owner Roadmap Phase 7 — Appointment Engine: CLOSED / TECHNICALLY COMPLETE (BOUNDED).** The bounded closure claims exactly the merged Phase 7 work: the existing `FR-4.x` booking primitives (slot holds with TTL + automatic expiry, idempotency, atomic claim — already present per the read-only Phase 7 scoping); **PR #79** Slice 1 / FR-4.6 nearby alternatives; **PR #81** Slice 2 — I-3 active-Visit protection (T5/T6/T7) + check-in serialization; **PR #83** FR-5.3 staff/secretary reschedule; **PR #85** FR-5.5 manual + automatic no-show completion incl. stale-pointer correction and concurrency/ER-06 compatibility. No other phase is marked complete; no further Phase 7 slice is claimed. This is **not** a release/V1/commercial claim (documentation-only closure).
> **FR-5.3 staff/secretary reschedule — CLOSED (PR #83, MERGED 2026-09-19T07:19:56Z; merge = `cf1ace137922d84b7042841e68629aedfb8334a8`; approved head `54612ab8fce4bf5d449dcdcb458dfb842964f1aa`; exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35429028253` · Real WordPress Acceptance `35429028209` · Closure Gate `35429028230` · Pilot/Staging Readiness Gate `35429028233` — + 19/19 check runs success).** Shared reschedule core + REST `POST /appointments/{id}/reschedule` (established `clinic/v1` namespace) with `cpms_appt_reschedule` and trusted Clinic scope: confirmed → rescheduled + one replacement + two-way linkage; slot counters; missing authz and cross-Clinic fail-closed; `HAS_ACTIVE_VISIT`/409 vs stale pointer; Idempotency-Key required/replay; audit + internal notification + reschedule SMS. **Owner-issued product policy (NOT original SRS wording):** staff bypasses the patient 24h reschedule deadline and the patient destination min-lead restriction; a successful staff reschedule produces both the internal patient notification and the existing reschedule SMS/change notification; patient B5 deadline/min-lead/ownership remain unchanged. Product files merged by PR #83: `src/Application/Booking/BookingService.php`, `src/Rest/BookingController.php`, plus the integration suite `tests/Integration/Phase7SliceStaffRescheduleRedTest.php`. **No migration / schema change was required.**
> **FR-5.5 no-show completion — CLOSED (PR #85, MERGED 2026-09-19T12:04:04Z; merge = `d7484ceddf698898483122cf52bb5abb55718744` = current main; approved head `2bbb6085c31e11e12e76a0d26a6b5a8589999c06`; exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35441813653` · Real WordPress Acceptance `35441813668` · Closure Gate `35441813683` · Pilot/Staging Readiness Gate `35441813696` — + 19/19 check runs success).** Per SRS FR-5.5 + `docs/state-machines/appointment.md` T8: **manual** staff surface REST `POST /appointments/{id}/no-show` (established `clinic/v1` namespace) through the established nonce → `cpms_appt_no_show` capability → trusted explicit Clinic scope (reason required; `no_show_at` written; reason preserved in the `APPOINTMENT_NO_SHOW` audit; `appointments.reason` not overwritten; slot counters unchanged; missing authz 403 / cross-Clinic 404 parity / no-scope 403 / genuinely active Visit 409 `HAS_ACTIVE_VISIT` / repeat from terminal 409 `CLINIC_INVALID_TRANSITION`); **automatic** completion by the existing sweep after the grace period (default 30 min after slot, configurable; Location timezone = operational time source) — including the **stale-pointer correction**: a stale `active_visit_id` (pointing at an inactive/terminal Visit) no longer blocks the sweep and is cleared on successful completion, while a genuinely active Visit still blocks (I-3); **concurrency/ER-06 compatibility**: check-in can never bind a genuinely active Visit to a terminal `no_show` appointment (covered by the real-DB race test in the merged suite). Product files merged by PR #85: `src/Application/Booking/BookingService.php`, `src/Application/Visits/VisitService.php`, `src/Infrastructure/Repository/VisitRepository.php`, `src/Rest/BookingController.php`, plus the integration suite `tests/Integration/Phase7Fr55NoShowRedTest.php` (12 tests). **No migration / schema change was required.** Evidence honesty: PR #85's title retains its pre-merge "DRAFT — TEST-ONLY RED" wording (Git history is deliberately not rewritten); the merged branch's final state carries the GREEN product implementation — the final state is proven by the 19/19 check runs on the merge SHA, and the pre-merge title wording is not reported as the merged state.
> **FR-5.4 — BOUNDED NON-CLAIM (recorded, not overstated):** SRS FR-5.4 uses `pending` with TTL and gives doctor-approval as an illustrative example ("e.g. created by secretary, needs doctor approval"). The current architecture **does not persist appointment `pending`**: unconfirmed booking capacity is represented by **slot holds with TTL and automatic expiry** (FR-4.2 `cpms_slot_holds` + atomic `held_count`; FR-4.4 per-minute idempotent expiry job — `HoldsExpireHandler`), and staff create intentionally **collapses create→pending→confirm in one operation**, persisting `confirmed` directly (verified in `src/Application/Booking/BookingService.php`, N-2 comment). Therefore **Phase 7 does NOT claim a doctor-approval workflow**: no such workflow is implemented, and it must not be invented as a closure requirement without future owner-directed product scope. **No persisted pending-appointment expiry job exists** — the existing expiry job is for slot holds, not for pending appointments.
> **Latest migration remains `0020` — no `0021` exists or was reserved** (re-verified live on `d7484ce…`: 20 migration files `0001`..`0020`, latest = `2026_09_09_0020_idempotency_clinic_scope.php`; PR #83 and PR #85 required no migration/schema change). This is **not** a release/V1/commercial claim (documentation-only closure).
>
> **Previous integrated main checkpoint (historical; Phase 7 Slice 2 — bounded technical closure; re-verified live 2026-09-19; superseded as live main by the Phase 7 bounded closure block above):** `e60c62428e6109e7182d04d3266f0d15b12b0d2f` — "Merge pull request #81 from bia2on2on/arena/01a0b642-doctor" (MERGED 2026-09-19T03:08:27Z; open PRs at re-verification = 0).
> **Owner Roadmap Phase 7 — Appointment Engine: STARTED / IN PROGRESS. Slice 2 = CLOSED (bounded technical closure). Phase 7 as a whole is NOT complete; no other `FR-4.x` requirement is claimed closed; no Slice 3 exists or is claimed.**
> **Phase 7 Slice 2 — CLOSED (PR #81, MERGED 2026-09-19T03:08:27Z; merge = `e60c62428e6109e7182d04d3266f0d15b12b0d2f`).** Slice 2 enforces appointment invariant **I-3** (`docs/state-machines/appointment.md` §4) while a **genuinely active Visit** exists (the connected Visit in one of the established live `ACTIVE_STATUSES` with `active = 1`) for: **T5 patient cancel**, **T6 staff cancel**, **T7 reschedule** — rejected with error code **`HAS_ACTIVE_VISIT`** / **HTTP 409**. **Check-in now serializes against these appointment mutations using the existing appointment-row locking architecture** (the I-3 check runs on the locked appointment row, before any mutation). A **stale `active_visit_id` pointer by itself is NOT treated as a genuinely active Visit** (a stale pointer alone never blocks). Product files merged by PR #81: `src/Application/Booking/BookingService.php`, `src/Application/Visits/VisitService.php`, `src/Domain/Machine/VisitMachine.php`, plus the integration suite `tests/Integration/Phase7Slice2ActiveVisitInvariantRedTest.php` (8 tests, including two `pcntl_fork` real-concurrency tests). **No migration / schema change was required — latest migration remains `0020`.**
> **Evidence history (recorded honestly, not converted):** accepted **final RED = `29bd8c36a263fc25b62a0f29554a7ce3224415a3`** ("test(phase7-slice2): RED hygiene + explicit concurrency evidence classification") — exact-head RED CI run `35398347887` (`completed/failure` at that head; check runs at that SHA: 18 success + 1 failure). **GREEN product commit = `6c16e296d73eb6caf7271d49634de58ca0e6b1c6`** ("feat(phase7-slice2): GREEN — I-3 HAS_ACTIVE_VISIT + check-in serialization on the appointment row"). **Process history preserved, not rewritten:** the earlier RED attempt **`14319d0fd38dae9fd55ff36e13b301a658934c9d`** carried **collateral failures caused by test queue pollution**; those were **test/harness hygiene defects**, corrected before the accepted final RED — that earlier attempt is **not** recorded as clean evidence.
> **Final post-merge evidence on the merge SHA `e60c6242…`: all four required workflows terminal-success** (CI `35417703302` · Real WordPress Acceptance `35417703278` · Closure Gate `35417703283` · Pilot/Staging Readiness Gate `35417703280`) **and 19/19 check runs `success`** (re-verified live 2026-09-19).
> **Slice 1 boundary preserved:** the Phase 7 Slice 1 / FR-4.6 closure (PR #79) and its scope/claims are unchanged and remain recorded in the historical block below.
> **Latest migration remains `0020` — no `0021` exists or was reserved.** This is **not** a release/V1/commercial claim (documentation-only closure).
>
> **Previous integrated main checkpoint (historical; C7 hardening closure + Phase 7 Slice 1 / FR-4.6 bounded technical closure; re-verified live 2026-09-18; superseded as live main by the Phase 7 Slice 2 block above):** `757d7424332d87cdd3d1894ee39f9f5f01bf0a33` — "Merge pull request #79 from bia2on2on/arena/01a0b557-doctor" (MERGED 2026-09-18T18:12:54Z; open PRs at re-verification = 0).
> **C7 hardening — CLOSED (PR #78, MERGED 2026-09-18T15:07:23Z; merge = `4b322d9dc8839e1efd66d712aedc06d9f22cd175`).** The two deferred Phase-6 hardening items recorded in the previous block are implemented:
> **ج** `ScheduleService::list()` / `listExceptions()` (the no-scope READ paths through `requireClinicianWithinTrustedClinic()`) now **fail closed** without an explicit trusted Clinic scope — the stable `CLINIC_NOT_FOUND` / 404 parity packet, and no undomained query is ever executed; `clinicians.clinic_id` is never used as tenant authority in those reads.
> **د** `SlotsGenerateHandler`: a numeric payload `horizon_days` now shares the settings path's `1..365` bound — `<= 0` or `> 365` fails closed as a per-clinician skip under the established invalid-horizon behavior (warning `SLOTS_GEN_SKIP_INVALID_HORIZON`; the sweep continues, the job stays successful).
> **Post-merge evidence on the merge SHA `4b322d9d…`: all four required workflows terminal-success** (CI `35360479404` · Real WordPress Acceptance `35360479402` · Pilot/Staging Readiness Gate `35360479660` · Closure Gate `35360479487`) **and 19/19 check runs `success`** (re-verified live 2026-09-18).
> **Remaining no-scope compatibility branches (write helpers `requireScheduleForTrustedClinic()` / `requireExceptionForTrustedClinic()`):** separately reconstructed after PR #78 — production REST and wp-admin write callers establish an explicit trusted scope (REST: `RestClinicContext` ← `TrustedClinicEstablisher`; wp-admin: `authorizeAdminWrite()` → `App::replaceExplicitScope()`); no current `src`/`bin`/job caller without scope was found; no verified compatibility consumer depends on the no-scope branch ⇒ classification **NO CHANGE JUSTIFIED** — **not** another required C7 patch.
> **Producer reality for `horizon_days` (verified on the main tree):** `ScheduleService::regenerate()` enqueues `slots.generate` with `['source' => 'manual']` (no `horizon_days`); the recurring scheduler (`App::scheduleRecurringJobs()`) enqueues an empty payload (no `horizon_days`); **`bin/cpms slots generate --days=N` DOES set `horizon_days`** — a real operator/server CLI producer; no external REST job-enqueue route was verified (a limited verification statement — **not** a universal claim about all possible external systems).
> **الف (unchanged):** the inaccurate `ScheduleController.php` exception-route comment (404 vs the boundary's 403 `CLINIC_SCOPE_UNAVAILABLE` / `reason=location`) is comment-only debt; PR #78 did not touch that file; it is to be corrected in the next legitimate product slice that touches that file.
> **Owner Roadmap Phase 7 — Appointment Engine: STARTED. Slice 1 / FR-4.6 = CLOSED (bounded technical closure). Phase 7 as a whole is NOT complete; no other `FR-4.x` requirement is claimed closed; no Slice 2 exists or is claimed.**
> **Phase 7 Slice 1 / FR-4.6 — CLOSED (PR #79, MERGED 2026-09-18T18:12:54Z; merge = `757d7424332d87cdd3d1894ee39f9f5f01bf0a33`).** The alternatives behavior is **owner-issued product policy — distinct from the original SRS wording of FR-4.6** (the SRS line only says: clear message + display nearby free slots; the specifics below are owner-issued policy resolving the FR-4.6 ambiguity, **not** SRS text): on a `CLINIC_SLOT_TAKEN` race loss (at the two accepted sites — the hold `atomicHold` loss and the `confirm()` final `atomicClaim` loss) the response carries **additive `BookingException` data under `nearby_slots`**: up to **5** free alternatives; **same local date** (single-date window); **same losing Location**; **same Clinic + clinician** taken only from the persisted losing-slot context (request IDs are selectors, never authority); the established deterministic ordering (`slot_date` ASC, `slot_time` ASC, `id` ASC); **`[]`** when there is no eligible alternative (also for missing trusted context or any unexpected error — the `CLINIC_SLOT_TAKEN` envelope is never transformed); the existing **`CLINIC_SLOT_TAKEN` code / message / HTTP 409 are unchanged**.
> **Evidence history (recorded honestly, not converted):** final accepted **VALID RED = `81d06619c63e23212c209bf8d30ff9ddd45dfaa6`** — CI run `35370158310` (`failure`; the three named failures are exactly the missing `nearby_slots` key); **A/B/C accepted as VALID RED**. Historical RED attempt **`b690e6c550f726a0329da51fbd37519e307f5c5e`** (CI run `35369425482`): **B valid at that attempt; A/C INVALID RED (class D — fixture arithmetic counted the not-yet-held losing slot as free)** — not rewritten as valid. The deterministic fixture B exercises the real `confirm()` / `atomicClaim` failure branch but is **NOT a runtime reproduction of a genuine concurrency race** — and is never claimed to be. **GREEN product commit = `dfb37d41eba75c8543abea574f18858370aacf0d`** (`src/Application/Booking/BookingService.php` only).
> **Final post-merge evidence on the merge SHA `757d7424…`: all four required workflows terminal-success** (CI `35378860137` · Closure Gate `35378860056` · Real WordPress Acceptance `35378860138` · Pilot/Staging Readiness Gate `35378860185`) **and 19/19 check runs `success`** (re-verified live 2026-09-18).
> **Phase 7 scoping boundary:** the read-only Phase 7 scoping found substantial booking/hold infrastructure already present; that scoping is **not** itself an implementation closure for every `FR-4.x` requirement.
> **Latest migration remains `0020` — no `0021` exists or was reserved.** This is **not** a release/V1/commercial claim (documentation-only closure).
>
> **Previous integrated main checkpoint (historical; Phase 6 — bounded technical closure; re-verified live 2026-09-18; superseded as live main by the C7 hardening + Phase 7 Slice 1 / FR-4.6 block above):** `bd5e6a1819a838648dbdcbc6914c0d8b24bba38b` — "Merge pull request #76 from bia2on2on/arena/01a0b31d-doctor" (MERGED 2026-09-18T09:59:44Z; approved PR head `bd840163b33688d17af51f006aa9dccaea9e8c53`; merge parents `fc0a598e739d6e4951de3167bbc4509e0d9c5378` + `bd840163…`; open PRs at re-verification = 0).
> **Owner Roadmap Phase 6 — Scheduling Engine: CLOSED / TECHNICALLY COMPLETE (BOUNDED)**, in the bounded
> scope of the merged Phase-6 slices **PRs #69–#71 + #73–#76** (Slice 1 Clinic-scoped schedule regeneration/impact;
> Slice 2 Location-local scheduling boundaries; Slice 3 explicit authorized Location on schedule create;
> Slice 4 multi-shift with deterministic overlap rejection; Slice 5 explicit wp-admin schedule-row identity;
> Slice 6 Location-scoped schedule exceptions; **Slice 7 concurrency-safe multi-shift conflict enforcement**). The PR range also contains the
> documentation-only **PR #72** (owner-requirement preservation — not a slice).
> **Phase 6 Slice 7 = CLOSED.** The multi-shift TOCTOU concurrency blocker was fixed by **PR #76**.
> Accepted **VALID RED = `fca63d3a21a7918c950d2cf07bf43343fe0c20ef`** (CI run `35327220676`: `Tests: 983,
> Assertions: 13622, Failures: 3` — cases A/C/D failed against the shipped check-then-act path while the
> positive control E passed). The two earlier RED attempts **remain INVALID RED and are not rewritten as
> valid**: `091fae1c5c59142d7bc4d60aeb2ae8954e52ed28` (run `35318716174` — `ParseError`, zero tests executed)
> and `c8b9592056307fea8213d131e135131198508674` (run `35320273122` — the intended suite **did execute**:
> `Tests: 983, Assertions: 13649, Failures: 3`; the forked children's fatal outcomes contained
> `PHPUnit\Framework\Error\Warning: Undefined array key "id"` and the non-overlapping positive control
> failed, but **the source/attribution of that warning was NOT RETRIEVED**, so the failures could not
> validly be attributed to the intended product concurrency contract — **neither a product defect nor a
> harness defect is asserted**).
> **Final post-merge evidence on the merge SHA `bd5e6a18…`: all four required workflows terminal-success** —
> CI `35332404611` · Real WordPress Acceptance `35332404609` · Pilot/Staging Readiness Gate `35332404592` ·
> Closure Gate `35332404644` — **and 19/19 check runs `completed`/`success`** (no pending, no cancelled,
> no failure). Exact-head evidence: 19/19 checks success at `bd840163…`; Integration `OK (983 tests,
> 13851 assertions)` with `Phase6ScheduleShiftConcurrencyRedTest` 5 tests / 290 assertions / 0 failures
> (run `35329496603`).
> **Permanent evidence-honesty exceptions (recorded, not hidden):**
> (1) **Process exception — Slice 7 (historical, non-blocking):** the write agent was instructed to stop
> after obtaining VALID RED but proceeded into GREEN (`0202a51710f0f5c209b01e9f6e58436e117e987b`) before
> director authorization. This is recorded as a historical process / evidence-discipline exception; it does
> **not** invalidate the independently accepted final evidence recorded above.
> (2) **Historical Slice-5 RED-evidence exception:** the test-only commit
> `bd9351e75407ec0449204733d3b5894fd7d33aa8` has **no CI / check-run evidence at that exact test-only SHA**
> (GitHub check-runs API returns `total_count = 0` for it). No retrospective RED is manufactured for it;
> final behavior remains covered by later accepted evidence (PR #74 merged; the merge-SHA evidence above).
> (3) **Historical post-merge exception on the previous main `fc0a598e739d6e4951de3167bbc4509e0d9c5378`:**
> that post-merge state was **18 success + 1 cancelled**, with `Responsive smoke (Chromium ×4 viewport)`
> cancelled (Pilot/Staging run `35310058979` = `cancelled`). It was **NEVER a PASS** and is recorded only as
> a historical evidence exception; it does not contradict the later successful post-merge evidence on
> `bd5e6a18…`.
> **Requirement coverage (preserved as established):** `FR-3.1`, `FR-3.2`, `FR-3.3`, `FR-3.4`, `FR-3.6`,
> `FR-3.7`, `FR-3.8`, `FR-3.9`, `FR-3.10`. **`FR-3.5` (pre-login public calendar / free slots) belongs to
> Phase 8 — Patient Public Booking and is NOT claimed as Phase 6 coverage.**
> **Deferred hardening — NOT implemented in this closure; preserved for the planned C7 hardening slice:** *(🔴 historical — the planned C7 hardening slice has since been MERGED as **PR #78** (merge `4b322d9dc8839e1efd66d712aedc06d9f22cd175`); the ج/د items below are implemented — see the top block. This record is preserved, not rewritten.)*
> **ج** the no-scope legacy branch in `ScheduleService::requireClinicianWithinTrustedClinic()` —
> *deferrable hardening, not a current production security blocker*: production admin paths establish an
> explicit trusted scope (`ClinicianAdminPage::saveSchedules()` → `authorizeAdminWrite()` →
> `App::replaceExplicitScope($scope)`) and the REST boundary is separately scoped (`RestClinicContext` +
> `TrustedClinicEstablisher::verifiedScope`), so the legacy branch is not currently reachable from those
> production paths and does not use `clinicians.clinic_id` as tenant authority.
> **د** the `horizon_days` job-payload override in `SlotsGenerateHandler` — *deferrable hardening*: no
> production producer sets this override and no external job-payload route was verified; the settings path
> clamps `1..365` while the payload path only rejects `<= 0` (no upper bound). Preserved for the planned C7
> hardening slice **with an upper-bound clamp**; not implemented here.
> **الف** comment/documentation debt only: `src/Rest/ScheduleController.php` contains an inaccurate
> exception-route comment describing **404** while the executing REST scope boundary produces the established
> uniform **403 `CLINIC_SCOPE_UNAVAILABLE` / `reason=location`** before the service path. Product behavior is
> correct; the source file is deliberately **not** edited in this documentation-only closure, and the comment
> must be corrected in the next legitimate product slice that touches that file.
> **Latest migration remains `0020` — no `0021` exists or was reserved.** This is **not** a
> release/V1/commercial claim and does not promote drift rows O-03/O-04.
>
> **Previous integrated main checkpoint (historical; Phase 5 Slice 1 — bounded technical closure; re-verified live 2026-09-17; superseded as live main by the Phase 6 block above):** `e063b42260cb5ab740acf48dcf73c6c67b11fef6` — "Merge pull request #67 from bia2on2on/arena/01a0afef-doctor" (MERGED 2026-09-17T15:36:54Z; approved PR head `fa86e41e415d1b7fcca3dec4c88fadb25da71d1a`; merged PR #67 changed 3 files).
> **Owner Roadmap Phase 5 — Pricing Engine: CLOSED / TECHNICALLY COMPLETE (BOUNDED)**, in the bounded scope
> of the merged **Phase 5 Slice 1 (PR #67)**. Bounded scope: **base pricing is Clinic-scoped and sufficient
> for the current approved V1 flow**, and Slice 1 closed the concrete **Location attribution** gap by
> deriving `invoice.location_id` from the **validated Visit** (never from the request payload);
> `invoiceView()` returns the stored value, and historical `location_id = NULL` invoices are **not**
> backfilled. Existing V1 finance behavior is preserved: manual `unit_price` override remains supported, the
> `service.price` fallback remains supported, and invoice-item snapshots preserve historical charged values.
> **Not claimed:** ServiceOffering; any change to `u_service_code`; distinct tariffs for the *same* Service
> across different Locations of the same Clinic; server-side `is_active` invoice rejection as a proven
> product contract; tax/VAT policy (**REQUIRES LEGAL VERIFICATION**). **V1 is neither single-Clinic nor
> single-Location** — the authoritative model is `Organization → Clinic → Location` (ADR-0031), and
> multi-Location product support is **not** deferred. Deferring per-Location tariff sophistication rests on
> the **absence of a proven hard V1 requirement**, **not** on a single-Location assumption; a future verified
> same-Service-per-Location pricing requirement is a separate scoped decision with schema/uniqueness review.
> **No additional Phase 5 migration is justified; latest migration remains `0020` — no `0021` exists.**
> Evidence limitations that remain truthful and permanent: historical **VALID RED = NOT AVAILABLE** (no
> workflow run exists on the Slice-1 implementation commit `ac9fe35`); direct named execution proof for
> `Phase5InvoiceLocationFromVisitTest` = **NOT RETRIEVED** (the PR's Integration completion-evidence comment
> names only the Phase-3 Slice-6 / Phase-4 Slice-3 / Phase-4 Slice-4 / Phase-4 Location suites — aggregate
> counts are not per-test proof). Exact-head evidence: 19/19 checks success at `fa86e41e…`; post-merge gates
> on the merge SHA all success (CI `35241393668` · Real WP `35241393522` · Pilot/Staging `35241393533` ·
> Closure `35241393493`).
> **Previous integrated main checkpoint (historical; Phase 4 technical closure — re-verified live 2026-09-17; superseded by the Phase 5 slice-1 checkpoint above):** `70ace204d1524a8f5e83d33c67c1a09b7543e7a3` — "Merge pull request #65 from bia2on2on/arena/01a0ae2c-doctor" (MERGED 2026-09-17T09:27:34Z).
> **Owner Roadmap Phase 4 — Master Data: CLOSED / TECHNICALLY COMPLETE (BOUNDED)** based on the
> merged Phase-4 slices **PRs #59–#65**: professional multi-Clinic participation through membership;
> shared-professional staff Booking, WalkIn, and queue clinician resolution; Clinic Profile
> canonicalization; attaching an existing WP user to another Clinic; and Location master-data
> create/update for name/timezone. This is **not** a release/V1/commercial claim. The closure does
> **not** claim ServiceOffering, Specialty/Department/Room, Iran geography master data, or any other
> deferred/open feature implemented; the stale `clinic.phone` Patient Portal consumer is not claimed
> fixed. Location timezone remains the operational source-of-truth decision, and a professional's
> participation across Clinics remains membership-based. **Phase 5 implementation had not started at this
> Phase-4 checkpoint and was not part of that closure.** *(🔴 historical — superseded by the bounded
> Phase 5 Slice 1 closure in the top block.)* Latest migration remains `0020`; no `0021` exists.
> **Previous integrated main checkpoint (historical; Phase 3 End Gate — technically COMPLETED & FROZEN, re-verified
> live 2026-09-16; superseded by the current Phase 4 checkpoint above):** `ebf8588f34be1da2ff18a152dea2c8badc472056` — "Merge pull request #57 from
> bia2on2on/arena/01a0aae9-doctor" (MERGED 2026-09-16T16:46:59Z by `arena-ai-coding-agent[bot]`).
> **Phase 3 — Role & Access Control: COMPLETED / FROZEN** (implementation COMPLETE / technically
> accepted based on merged implementation — PRs #49, #50, #52, #54, #55, #56, #57 — and exact-head
> evidence: 19/19 checks success at `ebf8588f34be1da2ff18a152dea2c8badc472056`; Phase 3 End Gate
> result: PASS as provided; no owner Ready/merge authorization assumed). **This is NOT a release/V1/commercial claim and
> is NOT Phase 4.** Established Phase 3 foundations include: central Clinic-scoped
> `AuthorizationService`; scoped staff/membership management; scoped SMS configuration/secrets;
> scoped reports/export lifecycle; scoped clinical/medical-file access; scoped Clinician Admin /
> Handwriting / Finance; scoped Queue / Schedule / staff Booking / staff Patient; patient-self /
> public separation; installation administrator without implicit clinical access; dynamic
> multi-Clinic authorization coverage; Integration completion guard preventing premature
> false-green. *(Foundations summary only — not an implementation report.)* Latest migration
> remains `0020` — `0021` does not exist or was invented.
> **Previous integrated main checkpoint (historical):** `35acced4993287bdffd1fb5c0ef2f8cb1634f99f`
> — "Merge pull request #47" (MERGED 2026-09-15T11:32:03Z — Location timezone for reminder SMS
> quiet hours; approved head `bb70079f`; merge parents `229b0b0c6c03310bb6ee1415689f11b822703225`
> (main before merge) + `bb70079f3f46888c3123cc36d7054b82993f27bb` (PR #47 head)). Post-merge
> gates on `35acced` — all SUCCESS (push event; 19 check-runs, none pending/failed): CI
> `34963801460` · Real WordPress Acceptance `34963801412` · Pilot/Staging Readiness Gate
> `34963801393` · Closure Gate `34963801453`. **Technical Phase 2 = COMPLETE** (M-2 CLOSED — all
> eight canonical jobs resolved on main; M-4 CLOSED — typed non-retryable job failure semantics;
> Location is the operational timezone truth for the reachable `appt.reminder`/`fu.reminder`
> quiet-hours paths; post-M4 fixture correction merged via PR #46; latest migration remains
> `0020` — no `0021` exists or was invented). **This is NOT a release/V1/commercial claim. At this
> 2026-09-15 checkpoint Phase 3 was NOT STARTED — superseded by the Phase 3 completion recorded
> above at `ebf8588`.** This cycle's verified merges: PR #47 (head `bb70079f`) · PR #46
> (head `ea7b683` — post-merge fixture triage) · PR #45 (head `84a858f` — M-4 terminal
> policy) · PR #43 (head `da71e6d` — M-2 `handwriting.gc`) · PR #42 (head `37068ad` —
> backup configuration keys) · PR #41 · PR #40 (installation-level `notif.archive_days`).
> PR #44 (earlier M-4 draft) was **CLOSED WITHOUT MERGE**. No open PRs at reconciliation
> time; PR #13 was subsequently CLOSED without merge (2026-09-14) — its lineage was already
> integrated through PR #14, so nothing changed.
> **Previous integrated main checkpoint (historical):** `bdb135e9b3eff9db9fbe104c33bc6c30850c5263`
> (PR #25 MERGED 2026-09-12T06:42:07Z — documentation-only C10 performance evidence
> package integrated; approved head `e2d9ce74`; merge parents `bd2634a` (main before merge,
> PR #24 docs merge) + `e2d9ce74` (PR #25 head); post-merge gates success: CI `34678813474` ·
> Real WP `34678813477` · Pilot/Staging `34678813488` · Closure `34678813479`).
> **C10 FORMALLY CLOSED by explicit Owner decision 2026-09-12** (bounded evidence review
> only — see §K). Phase 2 IN PROGRESS; Phase 3 / Phase 17 NOT STARTED at that 2026-09-12 checkpoint. *(Historical — superseded by the 2026-09-15 reconciliation (Phase 2 COMPLETED) and further superseded by the Phase 3 completion at `ebf8588`; see the top block.)*
> **Previous integrated checkpoint (historical):** `7146d5bb4167d2ac333000188d404c2aa977b817`
> (PR #23 MERGED 2026-09-11T19:35:54Z — bounded C9 i18n remediation integrated;
> approved head `c92737bb0a30fbdf13d804a8dcdffa522fd556ae`;
> merge parents `0fd5c2790e33a3d233a0b6ecb5c997b85fa5db54` (main before merge,
> itself the PR #22 documentation merge) + `c92737bb…` (PR #23 head)).
> **Previous integrated checkpoint (historical):** `0fd5c2790e33a3d233a0b6ecb5c997b85fa5db54`
> (PR #22 MERGED 2026-09-11T15:56:52Z — documentation-only Owner C7 acceptance +
> C8 closure sync; merge parents `b19930fe` + `c5f98ab9`).
> **Earlier integrated checkpoint (historical):** `b19930fe95a7d64b69f5ab9b5ac8a924261c48b8`
> (PR #21 MERGED 2026-09-11T14:27:14Z — post-C7 documentation-only continuity sync;
> head `3589b15d`; merge parents `a385d868` + `3589b15d`).
> **Previous integrated checkpoint (historical):** `a385d8681d5c37386407360b5b3c86e9b4af4e05`
> (PR #20 MERGED 2026-09-11T13:09:16Z — C7 remediation integration;
> merge parents: `4871f842d95732add73b972b7bed69d6da1eff89` (main before merge,
> itself the PR #19 documentation merge) + `6b438238228d2609fe47e069987bc91a7be4cdfa` (PR #20 head)).
> **Earlier integrated checkpoint (historical):** `248ca1049b49ea8f82b622744e39cf5b391a6838`
> (PR #17 MERGED 2026-09-10T20:55:57Z — post-closure C6 finance corrective).
> **Earlier integrated checkpoint (historical):** `099b6449362ce16be185aa811ff1f7da7dec269e`
> (PR #14 MERGED 2026-09-10; parents: pre-merge main `8087b42` + PR #14 head `a49b182`).
> **Pre-merge implementation evidence (historical):** `3fc5a54c3a340f8d6881048ff299e6700a7fb99e`
> **Historical baseline:** `c2bff76d1e21643a66bc0056a29881faaa2f299f` (tenant-hardening batch)

If Git/remote/PR, this file, and the repository tree disagree: **STOP**.

This file describes the current integrated main checkpoint `87625a03fd92e966de7b13930fc3fb9651138b9f` (Phase 10 — Doctor Portal = IN PROGRESS;
Phase 10 Slice 1 = CLOSED / TECHNICALLY COMPLETE (BOUNDED) via PR #113; Phase 10 Slice 2 / queue actions = CLOSED / TECHNICALLY COMPLETE (BOUNDED) via PR #117; see the top header block) plus the immediately preceding
`e9177bc` (PR #116 — Phase 10 Slice 1 bounded documentation closure; documentation-only, historical),
`84d6649` (PR #115 — test-infrastructure / browser-harness correction only; NOT a Phase slice, no product scope change, historical),
`b49a7773` (PR #113 — Phase 10 Slice 1 product merge — Phase 10 = IN PROGRESS with Slice 1 CLOSED / TECHNICALLY COMPLETE (BOUNDED) at that checkpoint, historical),
`72bb3fd` (PR #110 — Phase 9 FORMAL CLOSURE — Phase 9 = CLOSED / TECHNICALLY COMPLETE (BOUNDED) by explicit Owner decision 2026-09-23, historical),
`04d31fb1` (PR #109 — Phase 9 My Files — Phase 9 = READY FOR PHASE-CLOSURE DECISION at that checkpoint, historical),
`25718f43` (PR #108 — Phase 9 My Prescriptions documentation closure), `d0644706` (PR #107 — Phase 9 My Prescriptions),
`cfc13ab6` (PR #106 — Phase 9 My Visits documentation closure),
`f09a81c1` (PR #105 — Phase 9 My Visits),
`ed7db7ac` (PR #104 — Phase 9 Slice 4 Patient Profile UI documentation closure),
`9909ce18` (PR #103 — Phase 9 Slice 4 Patient Profile UI),
`cc45176c` (PR #102 — Phase 9 Slice 4 backend-foundation documentation closure),
`28e7fbf6` (PR #101 — Phase 9 Slice 4 backend foundation),
`3c1db825` (PR #100 — Phase 9 Slice 3 documentation closure),
`8a324e59` (PR #99 — Phase 9 Slice 3 independent CPMS Patient Portal shell),
`352a0936` (PR #98 — owner-issued Portal Architecture Contract + standalone-shell technical proof; not a phase slice),
`c18c18f4` (PR #96 — Phase 9 Slice 2 internal portal notifications), `28f51144` (PR #95 — Phase 9 Slice 1 documentation closure),
`a593958` (PR #93 — Phase 9 Slice 1 patient self-cancel), `b6c34c11` (PR #94 — ci(wpcs) collector fix; CI/tooling only),
`9abd3379` (PR #90 — Phase 8 Slice 3 closure; Phase 8 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)),
`440ba7e` (PR #89 — Phase 8 Slice 2 public booking OTP→Hold→Confirm),
`41225398` (PR #87 — Phase 8 Slice 1 public browse surface),
`e00cec8d` (PR #88 — multi-Clinic-safe REST-bootstrap/booking-settings hardening fix),
`97efdb45` (PR #86 — Phase 7 bounded documentation closure),
`d7484ce` (PR #85 — Phase 7 bounded closure; main at that checkpoint),
`cf1ace13` (PR #83 — FR-5.3 staff/secretary reschedule),
`8c05c831` (PR #84 — ci(pilot-gate) release-zip artifact pin; CI/tooling only),
`e60c6242` (Phase 7 Slice 2 — bounded technical closure),
`757d7424` (C7 hardening closure + Phase 7 Slice 1 / FR-4.6 bounded closure),
`4b322d9d` (PR #78 — C7 hardening) and `bd5e6a18` (Phase 6 — bounded Phase 6
technical closure) checkpoints and preserved Phase-5/Phase-4/Phase-3
checkpoint and pre-merge/pre-corrective evidence SHAs below. It does
**not** self-refer to the SHA of any later documentation-only commit.

---

## A. Product goal

Commercial clinic/practice platform (CPMS) on one Core / one Schema:

- Single Doctor
- Multi Doctor
- Multi Location
- Multi Clinic
- Organization with multiple Clinics

Authoritative domain model: `Organization → Clinic → Location` ([ADR-0031](adr/ADR-0031-organization-clinic-location-scoped-authorization.md)).

---

## B. Authoritative roadmap

**Only** Owner-approved Phase 0..20 in [`docs/roadmap/roadmap.md`](roadmap/roadmap.md).

Legacy labels (`F0..F10`, `Doc-Phase`, `V1` / `V1.5` / `V2`) are historical. They do not override the Owner roadmap.

> **Interpretation governance:** for the full phase/taxonomy hierarchy, ambiguity warnings, and crosswalk
> see [`docs/governance/project-phase-taxonomy.md`](governance/project-phase-taxonomy.md).
> Rule of thumb: a bare "Phase N" = Owner Roadmap; `C6` = a work package **inside** Owner Phase 2;
> Owner Roadmap Phase 9 = **Patient Portal (CLOSED / TECHNICALLY COMPLETE (BOUNDED) — formal closure by explicit Owner decision 2026-09-23; all bounded slices CLOSED via PRs #93 / #96 / #99 / #101 / #103 / #105 / #107 / #109)**, which is **not** the same as any
> internal/historical "Phase 9" reference.

---

## C. Current state

| Phase | Status |
|---|---|
| Phase 0 | CLOSED |
| Phase 0.5 | CLOSED |
| Phase 1A | CLOSED (`9bc6f7f`; OD-9 CLOSED) |
| Phase 1B | DEFERRED — scoped / object authorization (depends on Phase 2 + 3) |
| Phase 2 | **COMPLETED (TECHNICAL) — all recorded Phase 2 technical blockers closed on `main` at `35acced` (2026-09-15); this is NOT a release/V1/commercial claim. (Phase 3 is now COMPLETED/FROZEN — see its own row below.)** Subphase history preserved: **C6 CLOSED** + post-closure corrective integrated (PR #17); **C7 CLOSED** (remediation integrated via PR #20 at merge `a385d868`; formal Owner acceptance recorded 2026-09-11); **C8 / C9 CLOSED**; **C10 CLOSED (explicit Owner decision 2026-09-12; bounded evidence review only — no NFR/load/scalability claim)**. Internal queue C1..C10 complete. Final technical closures: **M-2 CLOSED** (all eight canonical jobs resolved on main), **M-4 CLOSED** (typed `NonRetryableJobFailure` semantics), Location = operational timezone truth for the reachable `appt.reminder`/`fu.reminder` quiet-hours paths (PR #47), post-M-4 fixture correction merged (PR #46); latest migration remains `0020` (no `0021`). The historical "End Gate (26 items)" label was never a defined acceptance checklist and is not revived here |
| C7 | **CLOSED — formally accepted by explicit Owner decision on 2026-09-11.** Technical closure was already evidenced (implementation merged into `main` via PR #20, merge `a385d868`, 2026-09-11T13:09:16Z; slices C7-0→C7-S6; all gates GREEN). The Owner acceptance covers the defined/completed C7 scope only — it is **not** a claim that all conceivable tenant isolation throughout the product is perfect, **not** commercial-readiness approval, and does not approve any deferred item, migration, or later phase — see `docs/phase-reports/c7-0-census.md` §۱۱ |
| C8 | **CLOSED as a Phase-2 Location-foundation evidence/documentation closure package (2026-09-11) — NO implementation work performed or authorized.** Reviewed evidence found no verified Phase-2 Location implementation gap. C8 closure means "the Phase-2 Location foundation is closed based on current evidence", not "all future Location/timezone behavior is complete" — see `docs/phase-reports/phase2-state.md` §C8 |
| Phase 3 | **COMPLETED / FROZEN** — implementation COMPLETE / technically accepted based on merged implementation (PRs #49, #50, #52, #54, #55, #56, #57) and exact-head evidence (19/19 checks success at `ebf8588f34be1da2ff18a152dea2c8badc472056`). Central Clinic-scoped `AuthorizationService` established; FROZEN. This is NOT a release/V1/commercial claim |
| Phase 4 | **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — the current live-main checkpoint above records merged PRs #59–#65 and their bounded scope. Deferred/open Master Data items remain deferred/open; no ServiceOffering, Specialty/Department/Room, Iran geography master data, or stale `clinic.phone` Patient Portal consumer fix is claimed |
| Phase 5 | **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — merged **Phase 5 Slice 1** (**PR #67**; merge `e063b42260cb5ab740acf48dcf73c6c67b11fef6`, 2026-09-17T15:36:54Z). Bounded scope: **Clinic-scoped base pricing sufficient for the current approved V1 flow** + the concrete Location-attribution fix (`invoice.location_id` derived from the validated Visit, never from payload). Not claimed: ServiceOffering, any `u_service_code` change, distinct per-Location tariffs for the *same* Service, `is_active` server-side invoice rejection as a product contract, tax/VAT policy (REQUIRES LEGAL VERIFICATION). Latest migration remains `0020` — no `0021`. This is NOT a release/V1/commercial claim |
| Phase 6 | **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — closed at the live-main checkpoint `bd5e6a1819a838648dbdcbc6914c0d8b24bba38b` through merged **PRs #69–#71 + #73–#76** (the range also contains the documentation-only **PR #72** — owner-requirement preservation, not a slice); **Slice 7 = CLOSED** (concurrency-safe multi-shift conflict enforcement, **PR #76** MERGED 2026-09-18T09:59:44Z; accepted VALID RED `fca63d3a…`; the earlier attempts `091fae1c…` and `c8b95920…` **remain INVALID RED**; post-merge on the merge SHA: four required workflows terminal-success + 19/19 checks success). Coverage preserved for `FR-3.1`–`FR-3.4` and `FR-3.6`–`FR-3.10` — **`FR-3.5` belongs to Phase 8 and is not claimed**. The Phase-6 deferred hardening items (ج: no-scope READ branch in `requireClinicianWithinTrustedClinic()`; د: `horizon_days` payload upper bound) have since been **implemented by the C7 hardening slice (PR #78**, merge `4b322d9dc8839e1efd66d712aedc06d9f22cd175`, MERGED 2026-09-18T15:07:23Z — no-scope READ paths fail closed; numeric payload `horizon_days` now shares the `1..365` bound, `> 365` fails closed/skips under the established invalid-horizon behavior); the remaining write-helper no-scope compatibility branches were reconstructed after PR #78 as **NO CHANGE JUSTIFIED** (production REST/wp-admin write callers establish explicit trusted scope; no verified compatibility consumer depends on the no-scope branch — not another required C7 patch); the inaccurate `ScheduleController.php` exception-route comment (404 vs the boundary's 403 `CLINIC_SCOPE_UNAVAILABLE`) remains comment-only debt for the next product slice touching that file (PR #78 did not touch that file). Recorded evidence exceptions: the Slice-7 process exception (GREEN before director authorization — non-blocking), the Slice-5 test-only SHA `bd9351e754` having no check runs at that exact SHA, and the previous main `fc0a598e` post-merge state of 18 success + 1 cancelled (**never a PASS**). Latest migration remains `0020` — no `0021`. This is NOT a release/V1/commercial claim |
| Phase 7 | **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — closed on the merged Phase 7 work: the existing `FR-4.x` booking primitives (slot holds with TTL + automatic expiry, idempotency, atomic claim — already present per the read-only Phase 7 scoping); **Slice 1 / FR-4.6** via **PR #79**; **Slice 2 — I-3 active-Visit protection (T5/T6/T7) + check-in serialization** via **PR #81**; **FR-5.3 staff/secretary reschedule (T7)** via **PR #83**; **FR-5.5 manual + automatic no-show completion (T8) + stale-pointer correction + concurrency/ER-06 compatibility** via **PR #85** (the FR-5.4 bounded non-claim is recorded at the end of this row). Closure evidence (live-verified 2026-09-19): **PR #83** MERGED 2026-09-19T07:19:56Z, merge `cf1ace137922d84b7042841e68629aedfb8334a8`, head `54612ab8…` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35429028253` · Real WP `35429028209` · Closure Gate `35429028230` · Pilot/Staging `35429028233` — + 19/19 checks success. **PR #85** MERGED 2026-09-19T12:04:04Z, merge `d7484ceddf698898483122cf52bb5abb55718744` = current main, head `2bbb6085…` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35441813653` · Real WP `35441813668` · Closure Gate `35441813683` · Pilot/Staging `35441813696` — + 19/19 checks success. **FR-5.3 (PR #83) scope:** shared reschedule core + REST `POST /appointments/{id}/reschedule` (established `clinic/v1` namespace) with `cpms_appt_reschedule` and trusted Clinic scope — confirmed → rescheduled + one replacement + two-way linkage; slot counters; missing authz / cross-Clinic fail-closed; `HAS_ACTIVE_VISIT`/409 vs stale pointer; Idempotency-Key required/replay; audit + internal notification + reschedule SMS. **Owner-issued product policy (NOT original SRS wording):** staff bypasses the patient 24h reschedule deadline and the patient destination min-lead restriction; a successful staff reschedule produces the internal patient notification + the existing reschedule SMS/change notification; patient B5 deadline/min-lead/ownership remain unchanged. **FR-5.5 (PR #85) scope:** manual staff surface REST `POST /appointments/{id}/no-show` (established `clinic/v1` namespace) through the established nonce → `cpms_appt_no_show` → trusted explicit Clinic scope (reason required; `no_show_at` written; reason preserved in the `APPOINTMENT_NO_SHOW` audit; fail-closed 403/404/403; `HAS_ACTIVE_VISIT`/409 vs genuinely active Visit; terminal repeat 409) + automatic completion by the existing sweep after the grace period (default 30 min after slot, configurable; Location timezone = operational time source) + **stale-pointer correction** (a stale `active_visit_id` pointing at an inactive/terminal Visit no longer blocks the sweep and is cleared on success; a genuinely active Visit still blocks — I-3) + **concurrency/ER-06 compatibility** (check-in can never bind a genuinely active Visit to a terminal `no_show` appointment). Evidence honesty: PR #85's title retains its pre-merge "DRAFT — TEST-ONLY RED" wording (Git history is not rewritten); the merged final state carries the GREEN product implementation (proven by the 19/19 check runs on the merge SHA). — **Slice 2** via **PR #81** MERGED 2026-09-19T03:08:27Z, merge `e60c62428e6109e7182d04d3266f0d15b12b0d2f`; post-merge on the merge SHA: four required workflows terminal-success (CI `35417703302` · Real WP `35417703278` · Closure Gate `35417703283` · Pilot/Staging `35417703280`) + 19/19 checks success; accepted final RED `29bd8c36…` (exact-head RED CI run `35398347887` = `completed/failure` at that head); GREEN product commit `6c16e296…`. Scope: enforcement of appointment invariant **I-3** while a **genuinely active Visit** exists — **T5 patient cancel**, **T6 staff cancel**, **T7 reschedule** rejected with **`HAS_ACTIVE_VISIT`** / **HTTP 409**; **check-in serializes against these appointment mutations using the existing appointment-row locking architecture**; a **stale `active_visit_id` pointer by itself is NOT treated as a genuinely active Visit**; **no migration/schema change was required** (latest migration remains `0020`). Evidence honesty (preserved, not rewritten): the earlier RED attempt `14319d0f…` carried **collateral failures caused by test queue pollution** — test/harness hygiene defects corrected before the accepted final RED; that earlier attempt is **not** recorded as clean evidence. **Slice 1** via **PR #79** MERGED 2026-09-18T18:12:54Z, merge `757d7424332d87cdd3d1894ee39f9f5f01bf0a33`; post-merge on the merge SHA: four required workflows terminal-success (CI `35378860137` · Closure `35378860056` · Real WP `35378860138` · Pilot/Staging `35378860185`) + 19/19 checks success; accepted VALID RED `81d06619…` (CI run `35370158310`, A/B/C); historical attempt `b690e6c5…` (CI run `35369425482`): B valid at that attempt, A/C **remain INVALID RED** (class D — fixture arithmetic, not rewritten); GREEN product commit `dfb37d41…` (`BookingService.php` only). Scope: **owner-issued product policy, distinct from the original SRS wording of FR-4.6** — additive `BookingException` data `nearby_slots` on `CLINIC_SLOT_TAKEN` race loss (up to 5 free alternatives, same local date, same losing Location, same Clinic + clinician from the persisted losing-slot context, established deterministic ordering, `[]` if no eligible alternative; existing code/message/HTTP 409 unchanged). Deterministic fixture B exercises the real `confirm()`/`atomicClaim` failure branch but is **not** a runtime reproduction of a genuine concurrency race. **Phase 7 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — the bounded closure claims exactly the merged work above (existing `FR-4.x` booking primitives + **PR #79** / **PR #81** / **PR #83** / **PR #85**); no further Phase 7 slice is claimed and no other phase is marked complete. **FR-5.4 bounded non-claim (recorded, not overstated):** SRS FR-5.4 uses `pending` with TTL and gives doctor-approval as an illustrative example; the current architecture does **not** persist appointment `pending` — unconfirmed booking capacity is represented by slot holds with TTL and automatic expiry, and staff create intentionally collapses create→pending→confirm in one operation (persisting `confirmed` directly); therefore Phase 7 does **not** claim a doctor-approval workflow (none is implemented, and none may be invented as a closure requirement without future owner-directed product scope), and **no persisted pending-appointment expiry job exists** (the existing expiry job is for slot holds, not for pending appointments). The read-only Phase 7 scoping found substantial booking/hold infrastructure already present — that scoping is not itself an implementation closure for every `FR-4.x` requirement. Latest migration remains `0020` — no `0021`. This is NOT a release/V1/commercial claim |
| Phase 8 | **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — bounded post-merge documentation closure at `9abd3379a02a320705cadac5229726bbd1b202cb` (= main at the Phase-8 closure checkpoint, since superseded as live main by the Phase 9 Slice 1 merge `a593958521386d459f76c5efa839d9962e1f67fc`; PR #90 MERGED 2026-09-20T17:04:39Z; re-verified live 2026-09-20). **Slice 1 = CLOSED (BOUNDED)** via **PR #87** (MERGED 2026-09-19T20:43:04Z, merge `4122539885cadb28d69f62e765ee3368b50b0509`, approved head `0643e2b59479d73021c20508d82e20ee5bde06b5` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35468244421` · Real WP `35468244416` · Closure Gate `35468244423` · Pilot/Staging `35468244448`) — anonymous read-only public booking **browse** surface (explicit `clinic_id` attribute only, fail-closed; no PHI; existing A1/A4 reused; no new REST routes; no migration). **Slice 2 = CLOSED / MERGED via PR #89** (MERGED 2026-09-20T09:24:19Z, merge `440ba7e…` = main at the Slices 1+2 closure checkpoint, approved head `70d033cc3121519e882e2b18d4ada8ac1a303f81` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35502216463` · Real WP `35502216468` · Closure Gate `35502216456` · Pilot/Staging `35502216452` — + 19/19 check runs success) — public booking **OTP → authenticated Hold → final confirm** with the **owner-issued** Hold-Timing and new-Patient minimum-identity policies (**NOT original SRS wording**; [`decisions/2026-09-20-phase8-slice2-owner-decisions.md`](decisions/2026-09-20-phase8-slice2-owner-decisions.md)); migration `2026_09_20_0021` (`cpms_otp_tokens.clinic_id`). **Slice 3 = CLOSED / MERGED via PR #90** (MERGED 2026-09-20T17:04:39Z, merge `9abd3379a02a320705cadac5229726bbd1b202cb` = main at that checkpoint, since superseded as live main by the Phase 9 Slice 1 merge `a593958521386d459f76c5efa839d9962e1f67fc`, accepted head `0f044eb518d1e9a3c91f48a49a05df4f5c62ba83` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success — CI `35524710717` · Real WP `35524710714` · Closure Gate `35524710737` · Pilot/Staging `35524710697` — + 19/19 check runs success) — linked-Patient booking-subject selection (PR title retains its pre-merge "test(red) … (RED)" wording — Git history is not rewritten; the merged final state carries the GREEN product implementation, proven by the 19/19 check runs on the merge SHA); migration `2026_09_20_0022_slot_holds_patient_binding.php` is now the latest migration on main (22 files `0001`..`0022`). PR #88 (merge `e00cec8d…`) remains a merged hardening fix — not a Phase 8 slice. No open Phase 8 product-code gap was found in the completed scoping pass; no further Phase 8 slice is claimed; no other phase is marked complete. This is NOT a release/go-live/V1/commercial claim |
| Phase 9 | **CLOSED / TECHNICALLY COMPLETE (BOUNDED) — formal Phase 9 closure approved by explicit Owner decision on 2026-09-23** (canonical decision record: [`decisions/2026-09-21-phase9-patient-portal-owner-policy.md`](decisions/2026-09-21-phase9-patient-portal-owner-policy.md) — بخش سوم) — closed at **`72bb3fdda418914d9b7b33eed207c3c1de1a951f`** = main at the Phase-9 closure checkpoint (merge of PR #110 — Phase 9 My Files bounded documentation closure; MERGED 2026-09-23T15:53:55Z; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35884923760` · Real WordPress Acceptance `35884923831` · Closure Gate `35884923751` · Pilot/Staging Readiness Gate `35884923752` — + 19/19 check runs success; re-verified live 2026-09-23) — the implemented Patient Portal sequence is complete (shell + notifications + Profile + Visits + Prescriptions + Files) per owner-policy ordering Profile/Visits/Prescriptions/Files and wireframe §7؛ no explicitly required Phase 9 capability remains pending و در زمانِ بستن هیچ قابلیتِ اضافیِ Phase 9 الزامی نبود (no additional Phase 9 capability was required at closure). *(Historical READY state preserved, not rewritten: at the My Files documentation-closure checkpoint `04d31fb1fb1b1a8e33fbf1bbf4e3803a6d3db3eb` (= main at that checkpoint, now superseded as live main by the PR #110 merge `72bb3fd…`; PR #109 MERGED 2026-09-23T15:05:34Z; re-verified live 2026-09-23) Phase 9 was READY FOR PHASE-CLOSURE DECISION.)* **Slice 1 = CLOSED** via **PR #93** (merge `a593958521386d459f76c5efa839d9962e1f67fc`; patient self-cancel from the existing patient-portal surface using the existing B4 backend — no new REST route, no schema change; post-merge: CI `35539966134` · Real WP `35539966131` · Closure Gate `35539966142` · Pilot/Staging `35539966158`). **Slice 2 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #96** (merge `c18c18f4a33e3690d5002f6b21d56ad60334cdde`; Internal Patient Portal notification list + unread badge + mark-all-read on the existing notification backend — no migration, no new REST route, no provider implementation; post-merge: CI `35581581186` · Real WP `35581581172` · Closure Gate `35581581149` · Pilot/Staging `35581581181`). **Slice 3 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #99** (merge `8a324e59341851f5ced01ed37ecd5e69c725f30a`; independent CPMS Patient Portal shell production code on main — no new REST endpoint, no new auth system, no migration; owner visual approval of shell = COMPLETE at accepted head; post-merge: CI `35615159555` · Real WP `35615159562` · Closure Gate `35615159508` · Pilot/Staging `35615159515`). **Slice 4 BACKEND FOUNDATION = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #101** (MERGED 2026-09-21T17:40:46Z; merge `28e7fbf61ec4e783f81dc3ecfd7ba92e6a2b8380` (main at the Slice-4-backend-foundation closure checkpoint, since superseded as live main by the PR #103 merge `9909ce184c973e700c838db1c74095eac5c8553c`); merge parents `3c1db8250f24cff509b7a544317c01043c1e1244` (merge of PR #100 — Slice 3 documentation closure) + `d0580bebccdeee66d492a4b1607c7c223c4b8ff2` (the exact accepted PR #101 head); post-merge on exact merge SHA: four required workflows terminal-success — CI `35633544036` · Real WordPress Acceptance `35633543948` · Closure Gate `35633543996` · Pilot/Staging Readiness Gate `35633544127` — all `push`→`main` bound to `28e7fbf…`, + 19/19 check runs success) — **Slice 4 product contract:** patient may select only active Clinic-level Patient records durably linked to the authenticated WP account (`GET /patient/my-records` C0); `link_id` is selector only, never authority; N>1 requires explicit selection (`422 CLINIC_SELECTION_REQUIRED`); no primary/first fallback; no cross-Clinic merge or automatic link creation; mobile/login identity remains read-only; National ID remains Clinic-scoped in current storage, validated, editable in Profile, and is NOT a booking requirement; Organization Identity synchronization remains outside Slice 4; **Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #103** (MERGED 2026-09-22T16:03:18Z; merge = `9909ce184c973e700c838db1c74095eac5c8553c` = main at that checkpoint; merge parents `cc45176ceeffade6ce9a87ec986dca15f6305714` (merge of PR #102 — Phase 9 Slice 4 backend-foundation documentation closure) + `db19e97a630f4900f9ac12f19563a71c6ea5518c` (the exact accepted PR #103 head); accepted head `db19e97a…` exact-head **28/28 check runs success**; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35751521914` · Real WP `35751521858` · Closure Gate `35751521808` · Pilot/Staging `35751521838` — all `push`→`main` bound to `9909ce1…`, + 19/19 check runs success on the merge SHA) — bounded scope: **the smallest complete Patient Profile section inside the approved independent CPMS Patient Portal shell** — Profile navigation + section (Clinic label + current profile data); profile-record selector over only server-linked active records (explicit choice required for N>1; reusing the Slice 4 backend-foundation C0/C1/C2 contracts — `422 CLINIC_SELECTION_REQUIRED` / `404 CLINIC_NOT_FOUND` / `400 CLINIC_VALIDATION_FAILED`; no backend selector rewrite); `ME_EDITABLE` field whitelist with read-only login mobile; save through the existing REST (`link_id` + `wp_rest` nonce) with Persian success/error regions; **no migration, no new REST route, no mobile-change flow, no SPA/router/framework, no shell redesign, no Staff Portal changes**; **owner visual approval for the Slice 4 Patient Profile UI = COMPLETE** (owner statement recorded by this closure — PR #103 carries no separate GitHub review artifact: 0 reviews; satisfies the owner-policy "one bounded visual review" for the Profile UI only). **PR #103 required no migration/schema change** — latest migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (22 files `0001`..`0022`, re-verified live on `9909ce1…`; no `0023`). **My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #105** (MERGED 2026-09-23T04:37:56Z; merge = `f09a81c132d545d1d1657029632a8b069515dd26` = main at that checkpoint; merge parents `ed7db7acfa56129035d762e8388c86fded8b7c24` (merge of **PR #104** — Phase 9 Slice 4 Patient Profile UI documentation closure) + `3e7d2103044ef6282953f04dea9f8c47bb18443f` (the exact accepted PR #105 head — the owner-requested Jalali/Shamsi display-correction head); accepted head `3e7d210…` exact-head **19/19 check runs success**; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35819115348` · Real WP `35819115341` · Closure Gate `35819115342` · Pilot/Staging `35819115349` — all `push`→`main` bound to `f09a81c…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA; **no queued / in_progress / cancelled run is counted as PASS**). Bounded scope: **the smallest read-only My Visits section (list + detail) inside the approved independent CPMS Patient Portal shell**, delivered on the existing C5 `GET /clinic/v1/visits` and C6 `GET /clinic/v1/visits/{id}` routes with an optional integer `link_id` patient-record selector — **no new REST route, no new authentication system, no migration/schema change, no write/edit/delete capability, no Prescriptions-list or Files UI, no framework/router, no shell redesign and no Staff Portal changes**. **Accepted patient-record selector semantics active for C5/C6:** `link_id` is **selector-only**, never tenant/patient authority; 0 => 404, 1 => auto-resolve, N>1 => 422, invalid/foreign/inactive => 404, no primary/first fallback; Jalali/Shamsi display for patient-visible dates with raw storage Gregorian unchanged. **PR #105 required no migration/schema change**. **My Prescriptions = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #107** (MERGED 2026-09-23T09:59:20Z; merge = `d0644706324be211e7fe68d1f9f2b8232357f704` = main at that checkpoint, superseded as live main by the PR #109 merge `04d31fb1fb1b1a8e33fbf1bbf4e3803a6d3db3eb`; merge parents `cfc13ab64938666e2587f1cbaf132c8d6d418307` (merge of **PR #106** — Phase 9 My Visits documentation closure) + `d0f390e997fb09e22aa00701170bc01161407153` (the exact accepted PR #107 head); accepted head `d0f390e…` exact-head **19/19 check runs success**; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35846142339` · Real WP `35846142325` · Closure Gate `35846142311` · Pilot/Staging `35846142296` — all `push`→`main` bound to `d064470…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA; **no queued / in_progress / cancelled run is counted as PASS**). Bounded scope: **the smallest read-only My Prescriptions section (list) inside the approved independent CPMS Patient Portal shell**, delivered on the existing C7 `GET /clinic/v1/prescriptions` route with an optional integer `link_id` patient-record selector — **no new REST route, no parallel endpoints, no new authentication system, no migration/schema change, no refill/mutation/printing/pharmacy action, no Files UI, no framework/router, no shell redesign and no Staff Portal changes**. **Accepted patient-record selector semantics now active for C7:** `link_id` is **selector-only**, never tenant/patient authority (the authenticated WordPress user remains the sole authority; client Clinic/Patient/Organization/role fields do not authorize access); **0 eligible records ⇒ canonical safe not-found `404 CLINIC_NOT_FOUND`; 1 eligible record ⇒ auto-resolution allowed; N>1 eligible records without an explicit selector ⇒ `422 CLINIC_SELECTION_REQUIRED`; invalid / foreign / inactive / cross-clinic selector ⇒ canonical non-enumerating `404 CLINIC_NOT_FOUND`** (same fingerprint as the zero-record case, never a fallback); **no primary/first fallback in C7**. Retained: server-side `is_patient_visible = 1` constraint and draft exclusion (`status = 'draft'`) strictly enforced; patient-visible prescriptions remain read-only. **Jalali/Shamsi display:** patient-visible prescription dates are displayed in **Jalali/Shamsi** form based on the trusted Location operational timezone (`created_at_jalali = ClinicCore\Domain\Time\Jalali::formatYmd(local_date)`) while **raw backend/storage datetime semantics remain Gregorian UTC `Y-m-d H:i:s.000` and unchanged** — no second calendar engine, no `Intl` dependency, no storage/timezone/Location/UTC change. UI display allowlist remains prescription number, safe status, generic and brand name, strength, form, dose, frequency, route, duration, instructions, and Jalali date; internal operational fields (`void_reason`, `is_patient_visible`, `drug_ref_id`, `correction_of_prescription_id`) are suppressed. **PR #107 required no migration/schema change** — latest migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (22 files `0001`..`0022`, re-verified live on `d064470…`; no `0023`). **My Files = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** via **PR #109** (MERGED 2026-09-23T15:05:34Z; merge = `04d31fb1fb1b1a8e33fbf1bbf4e3803a6d3db3eb` = main at that checkpoint (superseded as live main by the PR #110 merge `72bb3fdda418914d9b7b33eed207c3c1de1a951f`); merge parents `25718f43778904b15221908d51fc0c60d0f8e736` (merge of **PR #108** — Phase 9 My Prescriptions documentation closure) + `000a551d97b6396e6f8c9ac8428e32a074483e34` (the exact accepted PR #109 head); accepted head `000a551d…` exact-head **19/19 check runs success**; post-merge on the exact merge SHA: four required workflows terminal-success — CI `35879016172` · Real WordPress Acceptance `35879015943` · Closure Gate `35879015854` · Pilot/Staging Readiness Gate `35879016279` — all `push`→`main` bound to `04d31fb…`, all retrieved terminal-success live, + 19/19 check runs success on the merge SHA; **no queued / in_progress / cancelled run is counted as PASS**). Bounded scope: **the smallest complete My Files section (patient-visible list + protected download/stream + patient upload using existing C3) inside the approved independent CPMS Patient Portal shell**, delivered on the existing C3 `POST /patients/{patient_id}/files`, C4 `GET /patients/{patient_id}/files` and E17 `GET /files/{id}/stream` routes with an optional integer `link_id` patient-record selector — **no new REST route, no new authentication system, no migration/schema change, no delete/edit/replace/sharing/public-link capability, no framework/router and no shell redesign were introduced.** **Accepted multi-record patient-record selection semantics are now active on C3/C4:** `link_id` is **selector-only** and is never tenant or patient authority (the authenticated WordPress user remains the sole authority; client Clinic / Patient / Organization / role fields do not authorize access); **0 eligible records ⇒ canonical safe not-found `404 CLINIC_NOT_FOUND`; 1 eligible record ⇒ auto-resolution allowed; N>1 eligible records without an explicit selector ⇒ `422 CLINIC_SELECTION_REQUIRED`; invalid / foreign / inactive selector ⇒ canonical non-enumerating `404 CLINIC_NOT_FOUND`** (the same non-enumerating fingerprint as the zero-record case, never a fallback); and there is **no primary/first fallback**. **E17 patient stream authorizes by durable active linked Patient/Clinic membership and checks authorization before storage read** — a patient-visible, non-deleted file of any active Patient record durably linked to the authenticated WP user is downloadable without a client selector (membership establishes authority); foreign/unlinked/inactive/private/deleted files share one non-enumerating `404` fingerprint; denial happens before storage read; headers `Content-Disposition: attachment`, `nosniff`, private no-cache, realpath jail and missing-physical-file generic `404` remain enforced; no storage path leakage. **Patient upload preserves existing visibility/MIME/size/category/rate/storage protections** — forced `patient_visible`, `visit_id = NULL`, real MIME sniffing, extension↔MIME match, category allowlist, per-Clinic size ceiling, 10/hour per-user rate limit, randomized stored filename in protected out-of-webroot storage, `FILE_UPLOADED` audit preserved; no delete/edit/replace. **File dates use Location-derived Jalali only when trusted Location context exists; no date is fabricated when Location context is absent** — visit-linked files gain `created_at_jalali = Jalali::formatYmd(local_date)` via validated chain `file.visit_id → Visit → Location → IANA timezone`; visit-less patient uploads carry no Jalali date and no guessed date; raw backend/storage datetime semantics remain Gregorian UTC `Y-m-d H:i:s.000` and unchanged; no second calendar engine, no `Intl` dependency, no storage/timezone/Location/UTC change. UI display allowlist remains original_filename, mime_type, file_size, category and trusted Jalali date only; internal fields (storage_path, stored_filename, patient_id, clinic_id, visit_id, visibility, audit) are suppressed and file id rides only as technical selector for protected stream. **No migration/schema was required** — latest main migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (re-verified live on the `04d31fb…` tree: 22 migration files `0001`..`0022`, latest = `0022`; no `0023` exists or is reserved) and **PR #109 added no migration**. **Phase 9 is CLOSED / TECHNICALLY COMPLETE (BOUNDED) — formal closure by explicit Owner decision (2026-09-23)** — the implemented Patient Portal sequence (shell + notifications + Profile + Visits + Prescriptions + Files) is complete per owner-policy ordering Profile/Visits/Prescriptions/Files and wireframe §7; no explicitly required Phase 9 capability remains pending per live authoritative roadmap/SRS/owner-policy/current-state/wireframes/decisions و **no additional Phase 9 capability was required at closure**; all already-recorded bounded Phase 9 slices (Slice 1/2/3, Slice 4 BACKEND FOUNDATION, Slice 4 Patient Profile UI, My Visits, My Prescriptions, My Files) remain CLOSED and unchanged; latest migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (22 files `0001`..`0022`; no `0023`). The historical READY FOR PHASE-CLOSURE DECISION state (checkpoint `04d31fb1…` / PR #110) is preserved as historical evidence, not rewritten. This closure does NOT imply overall product completion, commercial readiness, production deployment, go-live, release/tag, Staff Portal completion, native mobile completion, future notification-provider completion, Organization Identity activation, or any speculative future feature; no future contract is invented; **Phase 10 was NOT started at that checkpoint** and its existing roadmap scope was untouched (*historical; Phase 10 is now **IN PROGRESS** with **Slice 1 CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — see the Phase 10 row directly below*). This is NOT a release/go-live/V1/commercial claim |
| Phase 10 | **IN PROGRESS — NOT CLOSED.** **Phase 10 delivered slices (SHAs/dates verified live via gh api):** Slice 1 shell + Today + Live Queue = PR #113 MERGED 2026-09-24T08:27:29Z merge `b49a777367a3bdf15f9ad944eab4e65ca70c5b26` + docs closure PR #116 MERGED 2026-09-24T10:43:38Z merge `e9177bcd80c80c59fc08153a6760fb24a3c6c493`; harness fix PR #115 MERGED 2026-09-24T09:33:05Z merge `84d664982517dd98c30678816e068e38c7d541fe` (D-class, NOT a slice); queue actions = PR #117 MERGED 2026-09-24T12:56:18Z merge `87625a03fd92e966de7b13930fc3fb9651138b9f` + docs closure PR #118 MERGED 2026-09-24T13:31:47Z merge `2dbb8d4a5e21b0b7ff6f4d1bc1c8b0332250bf55`; harness PR #119 MERGED 2026-09-24T14:09:35Z merge `911be1645c9f3eb1e518c514a3e0e433005bf1f3`; Visit Workspace = PR #120 MERGED 2026-09-24T22:17:29Z merge `972df97cebb23d3877515daf4c46e282ef37935a`; prescription write (RED + GREEN) = PR #121 MERGED 2026-09-25T11:44:07Z merge `4656a66096d8ec5287d67d5304161ce36138a7f9`; recommendations/follow-up = PR #122 MERGED 2026-09-25T14:54:52Z merge `5e202c663ae2304f9b04c69394d7bb80f958c5bf` + harness fix PR #123 MERGED 2026-09-25T15:28:46Z merge `705d14c041bd2ba9d7df5459c58525f2dc6d5690`; shared canonical Staff Portal shell + one-time legacy 302 = PR #124 MERGED 2026-09-25T21:31:34Z merge `aa42888c7ab641c86d1c45c200dfb887861e6ff1`; Visit Complete = PR #125 MERGED 2026-09-25T23:23:25Z merge `f1bb5d49bf92307005fe41af348a1341b1e99f28`; Medical Files in Visit Workspace = PR #126 MERGED 2026-09-26T02:22:56Z merge `fb861736d0b384ae8b5b54000822f0c2566b234f`. **Phase 10 IN PROGRESS — NOT CLOSED; handwriting/stylus is the NEXT capability and REQUIRED before Phase 10 closure; prescription print is NOT a closure requirement; Phase 11 NOT STARTED.** Latest migration remains `2026_09_20_0022_slot_holds_patient_binding.php` (22 files 0001..0022; no 0023). Owner authorized Phase 10 start via decision record [`decisions/2026-09-24-phase10-doctor-portal-owner-authorization.md`](decisions/2026-09-24-phase10-doctor-portal-owner-authorization.md). This is NOT a release/go-live/V1/commercial claim |

**Integrated main checkpoint:** `84d664982517dd98c30678816e068e38c7d541fe` (PR #115 MERGED 2026-09-24T09:33:05Z —
test-infrastructure / browser-harness correction only (a D-class harness fix; one non-product file `clinic-practice-management/bin/pilot-doctor-portal.py`) —
**no product scope change, no migration, no phase/slice churn; PR #115 is NOT a Phase slice**; this checkpoint is the current live main;
Phase 10 = IN PROGRESS; Phase 10 Slice 1 = CLOSED / TECHNICALLY COMPLETE (BOUNDED) via PR #113;
merge parents `b49a777367a3bdf15f9ad944eab4e65ca70c5b26` (main before the merge = the merge of PR #113 — Phase 10 Slice 1 product merge) + `4beaa186aa5fc4f8e5033e0710c376c925ce927e` (the exact accepted PR #115 head);
post-merge on the exact merge SHA: four required workflows terminal-success — CI `35981920078` · Real WP `35981920109` · Closure Gate `35981920090` · Pilot/Staging `35981920191` — + 19/19 check runs success;
no migration — `2026_09_20_0022_slot_holds_patient_binding.php` remains the latest (22 files `0001`..`0022`; no `0023`);
Phase 10 is NOT CLOSED; no release/go-live/V1/commercial/product-complete claim).
**Previous integrated main checkpoint (historical; Phase 10 Slice 1 product merge — Phase 10 = IN PROGRESS with Slice 1 CLOSED / TECHNICALLY COMPLETE (BOUNDED) at this checkpoint; superseded as live main by the PR #115 merge `84d664982517dd98c30678816e068e38c7d541fe`):** `b49a777367a3bdf15f9ad944eab4e65ca70c5b26` (PR #113 MERGED 2026-09-24T08:27:29Z —
Phase 10 Slice 1 = CLOSED / TECHNICALLY COMPLETE (BOUNDED) via PR #113; accepted head `0ad8b5d5841d75a7992cc53aa22bbe8907fe20cf`;
merge parents `ca08c885fee55a6dd83d8755e40b47b9a9277233` (main before the merge = the merge of PR #111 — Phase 9 formal closure documentation) + `0ad8b5d5841d75a7992cc53aa22bbe8907fe20cf` (the exact accepted PR #113 head);
post-merge on the exact merge SHA: four required workflows terminal-success — CI `35975354166` · Real WP `35975354115` · Closure Gate `35975354213` · Pilot/Staging `35975354133` — + 19/19 check runs success on the merge SHA and on the accepted head;
no migration — `2026_09_20_0022_slot_holds_patient_binding.php` remains the latest (22 files `0001`..`0022`; no `0023`; PR #113 added no migration);
PR #112 is historical / superseded — CLOSED / NOT MERGED; Phase 10 is NOT CLOSED; no release/go-live/V1/commercial/product-complete claim).
**Previous integrated main checkpoint (historical; Phase 9 FORMAL CLOSURE — Phase 10 was NOT STARTED at this checkpoint; preserved as historical evidence, not rewritten; superseded as live main by the PR #113 merge `b49a777367a3bdf15f9ad944eab4e65ca70c5b26` and then by the PR #115 merge `84d664982517dd98c30678816e068e38c7d541fe`):** `72bb3fdda418914d9b7b33eed207c3c1de1a951f` (PR #110 MERGED 2026-09-23T15:53:55Z —
Phase 9 FORMAL CLOSURE by explicit Owner decision 2026-09-23 — Phase 9 = CLOSED / TECHNICALLY COMPLETE (BOUNDED);
all bounded slices remain CLOSED (Slices 1–3, Slice 4 backend foundation, Slice 4 Patient Profile UI, My Visits, My Prescriptions, My Files);
no explicitly required Phase 9 capability remains pending; no additional Phase 9 capability was required at closure;
decision record: `decisions/2026-09-21-phase9-patient-portal-owner-policy.md` بخش سوم;
post-merge on the exact merge SHA: four required workflows terminal-success — CI `35884923760` · Real WP `35884923831` ·
Closure Gate `35884923751` · Pilot/Staging `35884923752` — + 19/19 check runs success;
migration `2026_09_20_0022_slot_holds_patient_binding.php` **remains** the latest on main (22 files `0001`..`0022`; no `0023`; PR #110 added no migration);
no release/go-live/V1/commercial/product-complete claim; no Staff Portal / native mobile / future notification-provider / Org Identity activation claim).
**Previous integrated main checkpoint (historical; Phase 9 = READY FOR PHASE-CLOSURE DECISION at that checkpoint — preserved as historical evidence, not rewritten; superseded as live main by the PR #110 merge `72bb3fdda418914d9b7b33eed207c3c1de1a951f`):** `04d31fb1fb1b1a8e33fbf1bbf4e3803a6d3db3eb` (PR #109 MERGED 2026-09-23T15:05:34Z —
Phase 9 MY FILES closure — Phase 9 = READY FOR PHASE-CLOSURE DECISION, Slices 1–3 = CLOSED, Slice 4 backend foundation =
CLOSED / TECHNICALLY COMPLETE (BOUNDED), Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED),
My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED), My Prescriptions = CLOSED / TECHNICALLY COMPLETE (BOUNDED),
My Files = CLOSED / TECHNICALLY COMPLETE (BOUNDED);
merge parents `25718f43778904b15221908d51fc0c60d0f8e736` (main before the merge = the merge of PR #108 — Phase 9 My Prescriptions bounded documentation closure)
+ `000a551d97b6396e6f8c9ac8428e32a074483e34` (the exact accepted PR #109 head); accepted head 19/19 check runs success;
post-merge on the exact merge SHA: four required workflows terminal-success — CI `35879016172` · Real WP `35879015943` ·
Closure Gate `35879015854` · Pilot/Staging `35879016279` — + 19/19 check runs success; **no migration/schema was required** —
migration `2026_09_20_0022_slot_holds_patient_binding.php` **remains** the latest on main (22 files `0001`..`0022`; no `0023`);
My Files bounded scope = patient-visible list + protected download/stream + patient upload using existing C3/C4/E17 with selector-only `link_id`
(0 ⇒ canonical 404; 1 ⇒ auto-resolve; N>1 without selector ⇒ 422 CLINIC_SELECTION_REQUIRED; invalid/foreign/inactive selector ⇒ non-enumerating 404; no primary/first fallback);
E17 membership auth before storage read; upload protections preserved; Jalali Location-derived only when trusted Location exists; raw Gregorian UTC unchanged;
Phase 9 READY FOR PHASE-CLOSURE DECISION, not automatically CLOSED; no future contract invented;
no release/go-live/V1/commercial claim). The immediately preceding mains: `d0644706324be211e7fe68d1f9f2b8232357f704`
(PR #107 MERGED 2026-09-23T09:59:20Z — Phase 9 My Prescriptions closure — Phase 9 = IN PROGRESS, Slices 1–3 = CLOSED, Slice 4 backend foundation =
CLOSED / TECHNICALLY COMPLETE (BOUNDED), Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED),
My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED), My Prescriptions = CLOSED / TECHNICALLY COMPLETE (BOUNDED);
merge parents `cfc13ab64938666e2587f1cbaf132c8d6d418307` (merge of PR #106 — Phase 9 My Visits documentation closure)
+ `d0f390e997fb09e22aa00701170bc01161407153` (the exact accepted PR #107 head); accepted head 19/19 check runs success;
post-merge on the exact merge SHA: four required workflows terminal-success — CI `35846142339` · Real WP `35846142325` ·
Closure Gate `35846142311` · Pilot/Staging `35846142296` — + 19/19 check runs success; **no migration/schema was required** —
migration `2026_09_20_0022_slot_holds_patient_binding.php` **remains** the latest on main (22 files `0001`..`0022`; no `0023`);
My Prescriptions bounded scope = read-only list in the approved portal shell on the existing C7 route with a selector-only `link_id`
(0 ⇒ canonical 404; 1 ⇒ auto-resolve; N>1 without selector ⇒ 422 CLINIC_SELECTION_REQUIRED; invalid/foreign/inactive selector ⇒ non-enumerating 404; no primary/first fallback);
server-side `is_patient_visible = 1` and draft exclusion enforced; Jalali Location-derived; raw Gregorian UTC unchanged;
Phase 9 IN PROGRESS, Patient Portal not claimed complete, next defined capability (**My Files**) NOT implemented/started/scoped, no future contract invented;
no release/go-live/V1/commercial claim), `cfc13ab64938666e2587f1cbaf132c8d6d418307`
(PR #106 MERGED 2026-09-23T08:54:34Z — Phase 9 My Visits documentation closure), `f09a81c132d545d1d1657029632a8b069515dd26`
(PR #105 MERGED 2026-09-23T04:37:56Z — Phase 9 My Visits closure — Phase 9 = IN PROGRESS, Slices 1–3 = CLOSED, Slice 4 backend foundation =
CLOSED / TECHNICALLY COMPLETE (BOUNDED), Slice 4 Patient Profile UI = CLOSED / TECHNICALLY COMPLETE (BOUNDED),
My Visits = CLOSED / TECHNICALLY COMPLETE (BOUNDED); merge parents `ed7db7acfa56129035d762e8388c86fded8b7c24`
(main before the merge = the merge of PR #104 — Phase 9 Slice 4 Patient Profile UI documentation closure)
+ `3e7d2103044ef6282953f04dea9f8c47bb18443f` (the exact accepted PR #105 head — the owner-requested Jalali/Shamsi display-correction head);
accepted head `3e7d210…` exact-head 19/19 checks success; post-merge on the exact merge SHA: four required workflows terminal-success —
CI `35819115348` · Real WP `35819115341` · Closure Gate `35819115342` · Pilot/Staging `35819115349` — + 19/19 check runs success;
**no migration/schema was required** — migration `2026_09_20_0022_slot_holds_patient_binding.php` **remains** the latest on main (22 files `0001`..`0022`; no `0023`);
My Visits bounded scope = read-only list/detail in the approved portal shell on the existing C5/C6 routes with a selector-only `link_id`
(0 ⇒ canonical 404; 1 ⇒ auto-resolve; N>1 without selector ⇒ 422 CLINIC_SELECTION_REQUIRED; invalid/foreign/inactive selector ⇒ non-enumerating 404; no primary/first fallback);
patient-visible visit dates displayed in Jalali/Shamsi while raw backend/storage `visit_date` semantics remain unchanged;
Phase 9 NOT complete, Patient Portal not claimed complete, next defined capability (**My Prescriptions**) NOT implemented/started/scoped, no future contract invented;
no release/go-live/V1/commercial claim), `ed7db7acfa56129035d762e8388c86fded8b7c24`
(PR #104 MERGED 2026-09-22T21:05:03Z — Phase 9 Slice 4 Patient Profile UI documentation closure), `9909ce184c973e700c838db1c74095eac5c8553c`
(PR #103 MERGED 2026-09-22T16:03:18Z — Phase 9 Slice 4 Patient Profile UI), `cc45176ceeffade6ce9a87ec986dca15f6305714`
(PR #102 MERGED 2026-09-21T18:06:20Z — Phase 9 Slice 4 backend-foundation documentation closure), `28e7fbf61ec4e783f81dc3ecfd7ba92e6a2b8380`
(PR #101 MERGED 2026-09-21T17:40:46Z — Phase 9 Slice 4 backend foundation: linked-Patient profile record selection + scoped profile update), `3c1db8250f24cff509b7a544317c01043c1e1244`
(PR #100 MERGED 2026-09-21T15:22:00Z — Phase 9 Slice 3 documentation closure), `8a324e59341851f5ced01ed37ecd5e69c725f30a`
(PR #99 MERGED 2026-09-21T14:53:05Z — Phase 9 Slice 3 independent CPMS Patient Portal shell), `352a0936…` (PR #98, MERGED
2026-09-21T12:14:14Z — architecture contract/proof, not a slice), `c18c18f4a33e3690d5002f6b21d56ad60334cdde`
(PR #96 MERGED 2026-09-21T09:08:17Z — Phase 9 Slice 2 closure: internal portal notification list + unread badge +
mark-all-read on the existing notification backend; no migration/REST route/provider; post-merge on that merge SHA:
CI `35581581186` · Real WP `35581581172` · Closure Gate `35581581149` · Pilot/Staging `35581581181` — + 19/19),
`28f51144…` (PR #95 — Phase 9 Slice 1 documentation closure), then
`a593958521386d459f76c5efa839d9962e1f67fc` (PR #93 MERGED 2026-09-20T21:52:03Z —
Phase 9 Slice 1 closure — Phase 9 = STARTED / IN PROGRESS, Slice 1 = CLOSED (TECHNICAL; BOUNDED): patient
self-cancel from the existing patient-portal surface using the existing B4 backend
(`POST clinic/v1/appointments/{id}/cancel`); merge parents `b6c34c11…` (merge of PR #94 — ci(wpcs) added-lines
collector fix + WPCS ratchet governance) + `9e7058cf…` (the exact accepted PR #93 head); accepted head `9e7058cf…`
exact-head 19/19 checks success; post-merge on the exact merge SHA: four required workflows terminal-success —
CI `35539966134` · Real WP `35539966131` · Closure Gate `35539966142` · Pilot/Staging `35539966158` — + 19/19
check runs success; **no new REST route; no schema change** — migration `2026_09_20_0022_slot_holds_patient_binding.php`
**remains** the latest on main (22 files `0001`..`0022`); Phase 9 NOT complete, Patient Portal not claimed complete,
no Phase 9 Slice 2 scoped; no release/go-live/V1/commercial claim). The immediately preceding main
`9abd3379a02a320705cadac5229726bbd1b202cb` (PR #90 MERGED 2026-09-20T17:04:39Z —
Phase 8 Slice 3 closure — Phase 8 = CLOSED / TECHNICALLY COMPLETE (BOUNDED): linked-Patient booking-subject
selection; merge parents `7873d46e…` + `0f044eb5…` (the exact accepted PR #90 head); approved head `0f044eb5…`
exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows terminal-success —
CI `35524710717` · Real WP `35524710714` · Closure Gate `35524710737` · Pilot/Staging `35524710697` — + 19/19
check runs success; migration `2026_09_20_0022_slot_holds_patient_binding.php` now the latest on main (22 files
`0001`..`0022`); no release/go-live/V1/commercial claim). The immediately preceding main
`440ba7e7d07579036128f288f502d390d27c98d3` (PR #89 MERGED 2026-09-20T09:24:19Z —
Phase 8 Slice 2 bounded closure: public booking OTP→authenticated Hold→final confirm; A2/A3 Clinic authority from
persisted selection / challenge rows; deterministic OTP patient-user reuse; idempotent B2 confirm replay; owner-issued
Hold-Timing + new-Patient minimum-identity policies (NOT original SRS wording —
`decisions/2026-09-20-phase8-slice2-owner-decisions.md`); migration `2026_09_20_0021` on main (`cpms_otp_tokens.clinic_id`);
approved head `70d033cc…` exact-head 19/19 checks success; post-merge on the merge SHA: four required workflows
terminal-success — CI `35502216463` · Real WP `35502216468` · Closure Gate `35502216456` · Pilot/Staging `35502216452` —
+ 19/19 check runs success; no release/V1/commercial claim). Before that:
`4122539885cadb28d69f62e765ee3368b50b0509` (PR #87 MERGED 2026-09-19T20:43:04Z — Phase 8 Slice 1: anonymous
read-only public browse surface; head `0643e2b5…` exact-head 19/19; post-merge CI `35468244421` · Real WP `35468244416`
· Closure Gate `35468244423` · Pilot/Staging `35468244448`). Before that:
`e00cec8dedd3245eb0c7dbfe772775c04e9af1f8` (PR #88 MERGED 2026-09-19T17:52:42Z — multi-Clinic-safe
REST-bootstrap/booking-settings hardening fix; not a Phase 8 slice) and
`97efdb450d3e6238a8380c541d71a5122a7daada` (PR #86 MERGED 2026-09-19T12:55:30Z — Phase 7 bounded documentation
closure; docs-only).
`d7484ceddf698898483122cf52bb5abb55718744` (PR #85 MERGED 2026-09-19T12:04:04Z —
Phase 7 bounded documentation closure on the merged work: FR-5.5 manual + automatic no-show completion (T8)
+ stale-pointer correction + concurrency/ER-06 compatibility (PR #85); FR-5.3 staff/secretary reschedule
(PR #83, MERGED 2026-09-19T07:19:56Z, merge `cf1ace13…`); I-3 active-Visit protection + check-in serialization
(PR #81); FR-4.6 nearby alternatives (PR #79); existing FR-4.x booking primitives; FR-5.4 bounded non-claim;
no migration/schema change; post-merge on the merge SHA: four required workflows terminal-success + 19/19
checks success; no release/V1/commercial claim). The immediately preceding main
`cf1ace137922d84b7042841e68629aedfb8334a8` (PR #83 MERGED 2026-09-19T07:19:56Z — FR-5.3 staff/secretary
reschedule product + integration suite; owner-issued staff policy: staff bypasses the patient 24h reschedule
deadline and destination min-lead; a successful staff reschedule produces the internal notification +
reschedule SMS; patient B5 policy unchanged; post-merge on the merge SHA: four required workflows
terminal-success + 19/19 checks success). Before that:
`8c05c831aea6806038521bfb11fb6bcc5aa49cce` (PR #84 MERGED 2026-09-19T06:54:45Z — ci(pilot-gate): pin
release-zip artifact download to current workflow run; CI/tooling only). Before that:
`e60c62428e6109e7182d04d3266f0d15b12b0d2f` (PR #81 MERGED 2026-09-19T03:08:27Z —
Phase 7 Slice 2 bounded technical closure: I-3 `HAS_ACTIVE_VISIT`/409 for T5/T6/T7 while a genuinely active
Visit exists + check-in serialization on the existing appointment-row locking architecture; no migration/schema
change; post-merge on the merge SHA: four required workflows terminal-success + 19/19 checks success;
no release/V1/commercial claim). Before that:
`757d7424332d87cdd3d1894ee39f9f5f01bf0a33` (PR #79 MERGED 2026-09-18T18:12:54Z —
Phase 7 Slice 1 / FR-4.6 bounded technical closure; post-merge on the merge SHA: four required workflows
terminal-success + 19/19 checks success; no release/V1/commercial claim). Before that:
`4b322d9dc8839e1efd66d712aedc06d9f22cd175` (PR #78 MERGED 2026-09-18T15:07:23Z — C7 hardening closure:
no-scope READ fail-closed + `horizon_days` payload `1..365` bound; post-merge on that merge SHA: four
required workflows terminal-success + 19/19 checks success).
Previous integrated checkpoint: `bd5e6a1819a838648dbdcbc6914c0d8b24bba38b` (PR #76 MERGED 2026-09-18T09:59:44Z —
bounded Phase 6 technical closure through PRs #69–#71 + #73–#76; Slice 7 concurrency-safe multi-shift conflict
enforcement; post-merge on the merge SHA: four required workflows terminal-success + 19/19 checks success;
no release/V1/commercial claim). Earlier: `e063b42` (PR #67 MERGED 2026-09-17 — bounded Phase 5 Slice 1
technical closure; Clinic-scoped base pricing + `invoice.location_id` derived from the validated Visit). Earlier:
`70ace204` (PR #65 MERGED 2026-09-17 — bounded Phase 4 technical
closure through PRs #59–#65). Earlier: `ebf8588` (PR #57 MERGED 2026-09-16 — Phase 3
End Gate; technically COMPLETED/FROZEN; gates recorded in the header block). Earlier:
`35acced` (PR #47 MERGED 2026-09-15 — final Phase 2 technical merge). Earlier: `bdb135e9` (PR #25 MERGED — C10 documentation
evidence package). Earlier: `b19930fe` (PR #21 MERGED 2026-09-11T14:27:14Z —
post-C7 documentation-only continuity sync; **Owner formal C7 acceptance + C8
closure package recorded in this documentation update's lineage**). Earlier:
`a385d868` (PR #20 MERGED 2026-09-11T13:09:16Z — C7
remediation integration). Earlier: `248ca10` (PR #17 MERGED
2026-09-10T20:55:57Z — post-closure C6 finance corrective). Earlier: `099b644`
(PR #14 MERGED 2026-09-10; parents `8087b42` + `a49b182`). **Pre-merge
implementation evidence (historical):** `3fc5a54`. Historical baseline:
`c2bff76` (tenant-hardening batch). All four post-merge main gates GREEN on
`b19930fe` (table below); the `a385d868`, `248ca10`, `099b644`, and `3fc5a54`
gate tables are retained below as historical evidence.



**Schema:** current version **`2026_09_20_0022`** (re-verified live 2026-09-23 on `72bb3fdda418914d9b7b33eed207c3c1de1a951f` and on `04d31fb1fb1b1a8e33fbf1bbf4e3803a6d3db3eb`; re-verified again live 2026-09-24 on current live main `84d664982517dd98c30678816e068e38c7d541fe` and on `b49a777367a3bdf15f9ad944eab4e65ca70c5b26` — latest remains `0022`, no `0023`): latest file is `src/Migrations/2026_09_20_0022_slot_holds_patient_binding.php`; 22 migration files `0001`..`0022`; previously re-verified on `f09a81c132d545d1d1657029632a8b069515dd26`, `9909ce184c973e700c838db1c74095eac5c8553c`, `28e7fbf61ec4e783f81dc3ecfd7ba92e6a2b8380`, `8a324e59341851f5ced01ed37ecd5e69c725f30a`, `c18c18f4a33e3690d5002f6b21d56ad60334cdde`, `a593958521386d459f76c5efa839d9962e1f67fc` and `9abd3379a02a320705cadac5229726bbd1b202cb`). **Phase 9 Slices 1–4 + My Visits + My Prescriptions + My Files (PRs #93 / #96 / #99 / #101 / #103 / #105 / #107 / #109) required no migration/schema change — `0022` remains the latest and no `0023` exists or is reserved; PR #107, PR #109 and PR #110 added no migration.** **Migration `0022` was merged by PR #90 within the accepted Phase 8 Slice 3 scope** (`cpms_slot_holds.patient_id` BIGINT UNSIGNED NULL, FK → `cpms_patients(id)` RESTRICT, no backfill). **Migration `0021` was approved by explicit Owner decision for Phase 8 Slice 2 and created and merged by PR #89** (canonical decision record: [`decisions/2026-09-20-phase8-slice2-owner-decisions.md`](decisions/2026-09-20-phase8-slice2-owner-decisions.md); durable `cpms_otp_tokens.clinic_id` → `cpms_clinics(id)` nullable FK binding; no backfill; reversible). *(Historical: at the Phase 8 Slices 1+2 checkpoint `440ba7e…`, file `0022` did not exist on main and existed only on draft PR #90's branch as that PR's own reservation — superseded by the PR #90 merge.)* Historical state preserved, not rewritten: *(at live main `70ace204d1524a8f5e83d33c67c1a09b7543e7a3` the current version was `2026_09_09_0020` and `0021` did **not** exist — **Migration 0021 was NOT approved and was NOT created** by the C8 documentation closure or the bounded C9 integration/closure — neither of those authorized a migration.)* If new schema is required: STOP and ask Owner. (Current migration state re-confirmed on live main `70ace204d1524a8f5e83d33c67c1a09b7543e7a3` — latest = `0020`, `0021` absent; **re-verified again on live main `bd5e6a1819a838648dbdcbc6914c0d8b24bba38b` (2026-09-18): 20 migration files `0001`..`0020`, latest = `2026_09_09_0020_idempotency_clinic_scope.php`, no `0021` file and no `0021` reserved by the Phase 6 closure; re-verified again on live main `757d7424332d87cdd3d1894ee39f9f5f01bf0a33` (2026-09-18, after the PR #78 + PR #79 merges): 20 migration files `0001`..`0020`, latest = `2026_09_09_0020_idempotency_clinic_scope.php`, no `0021` file and no `0021` reserved by the C7 hardening closure or by the Phase 7 Slice 1 / FR-4.6 closure; re-verified again on live main `e60c62428e6109e7182d04d3266f0d15b12b0d2f` (2026-09-19, after the PR #81 merge — Phase 7 Slice 2): 20 migration files `0001`..`0020`, latest = `2026_09_09_0020_idempotency_clinic_scope.php`, no `0021` file and no `0021` reserved — PR #81 required no migration/schema change; re-verified again on live main `d7484ceddf698898483122cf52bb5abb55718744` (2026-09-19, after the PR #83 + PR #85 merges — Phase 7 bounded closure): 20 migration files `0001`..`0020`, latest = `2026_09_09_0020_idempotency_clinic_scope.php`, no `0021` file and no `0021` reserved — PR #83 and PR #85 required no migration/schema change; re-verified again on live main `440ba7e7d07579036128f288f502d390d27c98d3` (2026-09-20, after the PR #87 + PR #88 + PR #89 merges — Phase 8 Slices 1+2): 21 migration files `0001`..`0021`, latest = `2026_09_20_0021_otp_tokens_clinic_binding.php` — `0021` created and merged by PR #89 under the owner-approved Slice-2 decision; `0022` is **not** on main (reserved only on draft PR #90's branch); re-verified again on live main `9abd3379a02a320705cadac5229726bbd1b202cb` (2026-09-20, after the PR #90 merge — Phase 8 Slice 3): 22 migration files `0001`..`0022`, latest = `2026_09_20_0022_slot_holds_patient_binding.php` — `0022` merged by PR #90 within the accepted Slice-3 scope))**

**Post-merge integration state (verified from live remote 2026-09-11)**

| | |
|---|---|
| `origin/main` | `7146d5bb4167d2ac333000188d404c2aa977b817` — "Merge pull request #23 from bia2on2on/arena/01a09182-doctor" — bounded C9 i18n remediation integrated (10 files: 2 REST controllers + 1 integration test + 7 docs) |
| PR #23 | [#23](https://github.com/bia2on2on/doctor/pull/23) — **MERGED** 2026-09-11T19:35:54Z — merge commit `7146d5bb`; parents `0fd5c27` (main) + `c92737bb` (approved head, branch `arena/01a09182-doctor`) — merged by `app/arena-ai-coding-agent` (bot). Post-merge gates on `7146d5bb` **all success**: CI `34639699703`, Real-WP `34639699751`, Pilot `34639699635`, Closure `34639699639` (19 check-runs, none pending). **Explicit Owner formal acceptance/closure of the bounded C9 scope was subsequently provided on 2026-09-11** (recorded in §K below). The historical PR title still reads "DRAFT — DO NOT MERGE"; that is pre-merge wording and Git history is deliberately **not** rewritten |
| PR #22 | [#22](https://github.com/bia2on2on/doctor/pull/22) — **MERGED** 2026-09-11T15:56:52Z — merge commit `0fd5c27`; parents `b19930fe` (main) + `c5f98ab9` (head `arena/01a0910a-doctor`) — documentation-only (8 docs files): recorded Owner C7 acceptance + C8 documentation-only closure |
| PR #21 | [#21](https://github.com/bia2on2on/doctor/pull/21) — **MERGED** 2026-09-11T14:27:14Z — merge commit `b19930fe`; parents `a385d868` (main) + `3589b15d` (head `arena/01a090a0-doctor`) — documentation-only (verified changed-file list: 8 docs files, no code/test/workflow/migration) |
| PR #20 | [#20](https://github.com/bia2on2on/doctor/pull/20) — **MERGED** 2026-09-11T13:09:16Z — merge commit `a385d868`; parents `4871f84` (main) + `6b438238` (head `arena/01a08f64-doctor`) — merged by `app/arena-ai-coding-agent` (bot); the merge act itself carried no recorded Owner approval at merge time, and **explicit Owner formal acceptance of C7 was subsequently provided on 2026-09-11** (recorded in this file + census §۱۱) |
| PR #19 | [#19](https://github.com/bia2on2on/doctor/pull/19) — **MERGED** 2026-09-11T07:10:36Z — docs/continuity sync to `248ca10` + C7-0 census open-question correction (merge parent of #20 via `4871f84`) |
| `origin/main` (previous, historical) | `a385d868` — "Merge pull request #20 from bia2on2on/arena/01a08f64-doctor" — C7 remediation integration |
| PR #17 | [#17](https://github.com/bia2on2on/doctor/pull/17) — **MERGED** 2026-09-10T20:55:57Z — merge commit `248ca10` — base `main` — head `arena/01a08b96-doctor` |
| PR #18 | [#18](https://github.com/bia2on2on/doctor/pull/18) — **CLOSED WITHOUT MERGE** 2026-09-11T06:00:12Z (`mergedAt` = `null`) — competing C6 corrective, superseded by merged #17 — branch `arena/01a08b72-doctor` (head `68cde82`) **retained** as historical evidence, deletion not authorized |
| PR #16 | **CLOSED** (historical; earlier competing corrective) — do not reopen |
| PR #14 | [#14](https://github.com/bia2on2on/doctor/pull/14) — **MERGED** 2026-09-10 — base `main` — head `a49b182` |
| PR #10 / #11 / #12 / #15 | **MERGED** (historical; superseded) — do not reopen |
| PR #13 | **OPEN + DRAFT** — C6 repair diagnostic (`arena/01a086b4-doctor`, head `09d505b`, base `arena/01a086ca-doctor`) — **do not touch, do not merge, do not close** (re-verified unchanged 2026-09-11). Ancestry relevance: `09d505b` **is an ancestor of `origin/main`** (its linear repair line was integrated through PR #14); that fact is reported only — cleanup is a later Owner decision. **Update 2026-09-15 (live re-verification): PR #13 is now CLOSED WITHOUT MERGE (2026-09-14T11:45:36Z; `mergedAt` = `null`, still draft at close).** Its lineage remains integrated through PR #14, so main is unaffected — nothing to do |
| Pre-merge branch (historical) | `arena/01a08828-doctor` — implementation `3fc5a54` — closure docs `becc82f` — integrated via #14 |

**Post-merge gates on `b19930fe` (`origin/main`, push event — all SUCCESS, verified live 2026-09-11; 19 check runs total, 0 pending, 0 failed):**

| Gate | Run | Attempt |
|---|---|---|
| CI | `34610213715` | 1 |
| Real WordPress Acceptance | `34610213751` | 1 |
| Pilot/Staging Readiness | `34610213717` | 1 |
| Closure Gate | `34610213674` | 1 |

**Historical post-merge gates on `a385d868` (previous checkpoint — all SUCCESS, attempt 1, verified live 2026-09-11):**

| Gate | Run | Attempt |
|---|---|---|
| CI | `34602712029` | 1 |
| Real WordPress Acceptance | `34602711983` | 1 |
| Pilot/Staging Readiness | `34602711956` | 1 |
| Closure Gate | `34602711962` | 1 |

**Historical post-merge gates on `248ca10` (previous checkpoint — all SUCCESS, verified live 2026-09-11):**

| Gate | Run | Attempt |
|---|---|---|
| CI | `34529280235` | 1 |
| Real WordPress Acceptance | `34529280196` | 1 |
| Closure Gate | `34529280176` | 1 |
| Pilot/Staging Readiness | `34529280164` | **2** |

Pilot attempt 1 on `248ca10` failed as a **Class C** (infrastructure / tooling
transient) event, not a product regression; attempt 2 re-ran the substantive
staging steps and succeeded. The Class C classification is recorded rather than
erased — the retry is the evidence, not a rewrite of the first attempt.

**Previous PRs — historical, integrated (do not reopen, rewrite, or close #13)**

Verified pre-merge ancestry (`merge-base --is-ancestor`): heads of #10 and #11
were ancestors of the #14 line; their histories are fully contained in the
merged main `099b644`. Do not rewrite those branches.

| PR | Branch | Head | Role | State |
|---|---|---|---|---|
| [#10](https://github.com/bia2on2on/doctor/pull/10) | `arena/01a0808c-doctor` | `79cce4b` | Phase 1A | MERGED |
| [#11](https://github.com/bia2on2on/doctor/pull/11) | `arena/01a082db-doctor` | `9e006b0` | OD-9 + Phase 2 through C6-E2 | MERGED |
| [#12](https://github.com/bia2on2on/doctor/pull/12) | `arena/01a086ca-doctor` | `f88fcdc` | C6 tenant hardcode removal (CI execution) | MERGED |
| [#13](https://github.com/bia2on2on/doctor/pull/13) | `arena/01a086b4-doctor` | `09d505b` | C6 repair diagnostic | OPEN + DRAFT — **untouched** |

**Historical gates on `099b644` (previous integrated checkpoint, push event — all SUCCESS):**

| Gate | Run |
|---|---|
| CI | `34460364222` |
| Real WordPress Acceptance | `34460364238` |
| Pilot/Staging Readiness | `34460364243` |
| Closure Gate | `34460364219` |

**Historical gates on `6e5d48c` (via PR #12)** — all SUCCESS:

| Gate | Run |
|---|---|
| CI (Unit×4 + PHPStan + WPCS + Integration) | `34375762362` |
| Real-WP (PR) | `34375762425` |
| Real-WP (push) | `34375756020` |
| Closure | `34375756075` |
| Pilot/Staging | `34375756043` |

**Historical gates on `3fc5a54` (pre-merge branch evidence)** — all SUCCESS:

| Gate | Run |
|---|---|
| CI (Tripwire + Unit×4 + PHPStan + WPCS + Integration 616 tests/0E/0F) | `34450386921` |
| Real-WP Acceptance (push) | `34450382616` |
| Real-WP Acceptance (PR) | `34450386918` |
| Pilot/Staging Readiness | `34450382549` |
| Closure Gate | `34450382522` |
| Pilot/Staging (Release Artifact + Responsive smoke + Upgrade path + Staging Gate) | `34406991520` |
| Closure Gate (GO‑LIVE evidence) | `34406991334` |

PHP was **not** on PATH in the audit sandbox; do not claim local PHPUnit/`php -l` there. Remote GitHub Actions is the executable evidence.

Lost commit `6000712`: **NOT RECOVERABLE**. Do not claim it exists.

---

## D. Architecture invariants

- Organization is mandatory (no `Organization = NULL` path).
- Every Clinic belongs to exactly one Organization.
- Every Clinic has at least one real Location (AD-15).
- A User may participate in multiple Clinics via **Membership / Assignment**.
- **One Clinician Professional Profile per WP User** (`u_clinician_user` stays). Multi-clinic doctor work is membership, not duplicate clinician rows. Do **not** plan to drop `u_clinician_user` in C7.
- Home/default Clinic (if ever UX) is not an authorization boundary.
- Patient Identity = Organization-level; Clinical Patient Record = Clinic-level.
- No implicit cross-clinic medical visibility.
- Location timezone is the operational source of truth (UTC storage).
- No implicit `clinic_id = 1` / `organization_id = 1` / `location_id = 1` (AD-13).
- No `current-user == Doctor` assumption.

**Context transport vs trust**

Client may *request* a Clinic context (any transport: param/header/route/selector/future app).  
Server must *establish/verify* it (membership/relationship).  
Never: `client clinic_id == trusted clinic_id` without verification.  
Application services stay transport-agnostic (`App::scope()` / domain relation / explicit clinic parameter).

```
Client-requested context
  → Authentication
  → Server-side Membership/Relationship Verification
  → Trusted ScopeContext
  → Application Service
  → Repository
```

At `6e5d48c` this pipe is **not** implemented on the REST boundary. `ScopeContext::set` in production exists only inside `ExportService` job bind. REST staff still falls through to `SystemClinicResolver` (exactly-one Clinic) or throws `CLINIC_SCOPE_REQUIRED`. *(Pinned to that historical SHA — C7 later added the trusted REST path `Rest/RestClinicContext.php` → `TrustedClinicEstablisher`; see §C7 below.)*

### D-1. Historical `54` tenant-default census vs. current runtime state (re-verified 2026-09-12 — documentation only)

**`54` is historical Phase‑0 provenance, not a current defect count.** The Phase‑0 census
(`docs/architecture/phase0.5-target-model.md` §الف‑۶, `docs/phase-reports/report-phase-0-reverification.md` C‑4)
measured **54 `clinic_id = 1` hardcodes in 23 files** (24 of them inside
`Infrastructure/Repository/`), plus **3 hidden default-parameters** (`?int $clinicId = 1` ×2,
`int $clinicId = 1` ×1) = extended census **57 in 26 files**. Those numbers stay **exactly as
recorded** in the historical reports — they are not rewritten here.

Current state, independently re-verified on this working tree (no code was changed to produce it):

| Measure (2026‑09‑12) | Value | Evidence |
|---|---|---|
| Tenant Tripwire hardcodes in **active production runtime** (`clinic-practice-management/src`, excluding `tests/`, `vendor/`, `Migrations/`) | **0** | `python3 bin/tenant-tripwire.py --allowlist bin/tenant-tripwire-allowlist.json` → `{"files_scanned": 173, "hardcodes": 0, "suspects": 1, "allowlist_entries": 0}`; `--test` → 59/59 self-tests pass |
| Tenant Tripwire **allowlist** | **`[]` (empty)** | `bin/tenant-tripwire-allowlist.json` → `{"entries": []}`; plugin copy `clinic-practice-management/bin/tenant-tripwire-allowlist.json` → `[]` |
| Tripwire **suspects** | **1 — sanctioned, not a tenant default** | `src/Application/Scope/SystemClinicResolver.php:52` (`LIMIT 1` *inside* the fail-closed exactly-one-Clinic resolution; sanctioned in `phase-reports/c6-census.md`, AD‑04) |
| Historical exact grep pattern over `src/` (`clinic_id\s*=\s*1\b\|'clinic_id'\s*=>\s*1\b`) | **7 textual matches, 0 runtime tenant-default resolutions** | 6 are prose/docblock references *to the prohibition itself* (`OtpService.php:323`, `PatientIdentityService.php:23`, `ClinicScope.php:12`, `ClinicianRepository.php:17`, `MembershipRepository.php:14`, `LoginRateLimiter.php:135`); 1 is inside already-applied Migration `0020` (guarded legacy backfill that **aborts** in a multi-Clinic install). Migrations are immutable history and are deliberately not edited |
| The 3 historical **default-parameters** (`?int $clinicId = 1` in `AuditLogger`/`Idempotency`, `int $clinicId = 1` in `Settings`) | **removed — no `= 1` tenant default remains anywhere in `src/`** | grep for tenant default-parameters in `src/` returns none. Now: `Settings::__construct` requires an explicit `int $clinicId` (`Settings.php:149`); `Idempotency::{check,complete,release}` require an explicit `int $clinicId`; `AuditLogger::log` takes `?int $clinicId = null` — it uses the caller's explicit Clinic, else the active `App::scope()`, and when scope is ambiguous/absent (`ScopeRequiredException`) records **NULL = system** (never `1`), per the Migration `0016` semantics (`AuditLogger.php:47-58`) |
| Schema-level `clinic_id … DEFAULT 1` | **removed** (3 tables) | Migration `0016` dropped `DEFAULT 1` from `cpms_sms_messages` (+ `INT`→`BIGINT`), `cpms_drug_reference`, `cpms_idempotency_keys` — the exact three tables listed in `phase-reports/final-pre-phase2-gate-report.md` §۴ and in the «🔴 تصحیح نهایی Pre-Phase-2 Gate» block of `architecture/phase0.5-target-model.md` (after ب‑۵) |

**Canonical wording to use from now on:** *historical Phase‑0 census = 54 (+3 default-parameters =
57/26 files); current active-runtime tenant-default violations = 0; tripwire hardcodes = 0;
tripwire allowlist = `[]`.* Never state "54 current hardcodes" and never delete the historical 54.

**Small verified future code-comment cleanup (recorded, NOT performed here — documentation-only task):**
`clinic-practice-management/src/Infrastructure/Repository/ClinicianRepository.php:17` still carries the
docblock line «همه کوئری‌ها clinic_id=1 (V1 تک-کلینیک — ADR-0003)». Independently verified **stale**:
the class takes an explicit `int $clinic_id` (`listAll(int $clinic_id, …)` at line 30,
`create(int $clinic_id, array $fields)` at line 76) and contains **no** tenant literal. This is a
**comment-only** cleanup (no behavior change, no test change, no tripwire impact) and is left for a
future code task because this task must not touch PHP.

The structurally tracked item was the background-job/tenant-context path. **Updated
2026-09-15 (final Phase 2 reconciliation):** the approved T/S/W scope-classification
**design is IMPLEMENTED**. The runtime truth is
`clinic-practice-management/src/Application/Jobs/JobScopeRegistry.php` (+
`JobScopeClass`): every one of the 15 registered job types carries an explicit
`T` (tenant) / `S` (installation) / `W` (installation-wide sweep) class, and
`JobsDispatcher` enforces it fail-closed (`JOB_SCOPE_UNCLASSIFIED` for any
unclassified type; permissive mode is test-only opt-in). The design rationale stays
recorded in
[`docs/architecture/phase2-tenant-context-remediation-design.md`](architecture/phase2-tenant-context-remediation-design.md).
Two boundaries are **preserved exactly**: (a) per the documented decision, **no
`clinic_id` column was added to `cpms_jobs`** — the "T ⇒ non-empty / S,W ⇒ NULL"
rule is enforced at the registry/contract + test level, not as persisted data; and
(b) the registry records **classification only** — scheduling truth remains
`App::RECURRING_JOBS` and handler truth remains `App::dispatcher()`. Stale claims that
"the registry does not exist" or "scope design is not implemented" are superseded.

---

## E. Security invariants

- Server-side authorization; UI hiding is not authorization.
- Fail-closed tenant isolation. Multi-clinic without explicit scope → `CLINIC_SCOPE_REQUIRED`.
- Private clinical storage outside DocumentRoot (OD-7).
- Active backup storage outside DocumentRoot; unsafe config is Fail-Closed + legacy read-only (OD-9).
- OTP `verify_mobile` does not provision users (OD-8).
- WP `administrator` has no blanket clinical-data bypass (AD-10/AD-11).

---

## F. Owner-approved UX direction

WordPress = hidden backend/platform. Operational users should not need to know it exists.  
`wp-admin` ≈ technical/system administration.

**Future (do not build in C6):**

- Staff Portal: Doctor / Reception / Finance / Clinic Manager workspaces
- Separate Patient Portal
- Common branded login may route by membership; a user may hold different roles in different Clinics

---

## G. Future clients

Core must stay client-agnostic for Web Staff Portal, Patient Portal, Android, iOS, PWA.  
Business logic must not be buried in wp-admin.  
Do not implement mobile auth/JWT now.

---

## H. Iran

Supports all provinces and cities of Iran. No Tehran-centric architecture.  
No `province_id=1` / `city_id=1` / Tehran-as-location-default.  
`Asia/Tehran` is only a timezone fallback.

- Iranian mobile normalization (`MobileValidator`); Persian/Arabic/Latin digits
- Unicode Persian data
- Geography foundation remains **deferred/open outside the bounded Phase 4 technical closure** —
  the C8 documentation closure (2026-09-11) and the current Phase 4 closure authorize **no** Iran
  province/city master-data dataset, nationwide geography seeding, or unclaimed master-data management
  UX. No specialty/geography decision is reversed here; the related deferred/open items remain governed
  by the roadmap and drift register.
- Jalali is UX/display; internal timestamps stay UTC

---

## I. Quality

WPCS (changed-lines) · PHPStan · Unit · Integration · Real-WP · Pilot · Closure · security · performance · compatibility · i18n.

CODE + TEST + DOC + MIGRATION together where relevant.

User-facing strings: WordPress i18n-ready. Machine `CLINIC_*` codes stay stable.

### I-1. Localization layering rule (C9 — forward architecture rule, permanent)

Recorded by the bounded C9 work package (PR #23 — **MERGED** into `7146d5bb`; base `0fd5c27`):

- **Domain** must not gain any NEW direct WordPress i18n/presentation dependency. New
  Domain code must not call `__()` or an equivalent WordPress i18n API.
- **Application business/service code** must not add new direct WordPress
  presentation/i18n coupling as the default pattern.
- **Human localization belongs at presentation/adapter boundaries** (REST controllers,
  wp-admin) — the already-established pattern of `RestClinicContext::toError()` (since C6)
  and `ClinicianAdminPage` (since C7-S4).
- **Stable machine-readable `code` / HTTP status / structured `data` remain authoritative**
  for clients. The bounded C9 change preserved all three exactly, and preserved the default
  Persian `message` byte-for-byte, so **no API-contract change was needed**
  (`api-contract.md` §0 and `ADR-0019` do not pin `message` bytes; `SRS` NFR-UI-4 asks for
  "i18n-ready, fa default").
- Existing direct Domain/Application `__()` usage is **tolerated historical debt, NOT
  precedent to expand**. Enumerated and deliberately left untouched: **13** Domain sites in
  **2 of 42** Domain files (`Domain/Membership/MembershipException.php` — 7,
  `Domain/Patients/PatientIdentityException.php` — 6) and **19** Application sites in 2 files
  (`Application/Membership/MembershipService.php` — 13,
  `Application/Patients/PatientIdentityService.php` — 6). No mass legacy cleanup was
  performed and none is claimed.
- The plugin still ships **no** `.po`/`.mo`/`.pot` catalog and calls **no**
  `load_plugin_textdomain()`. Because the `cpms` domain therefore resolves to WordPress'
  empty `NOOP_Translations`, `__($msgid, 'cpms')` returns the source string unchanged —
  which is exactly why default Persian output is preserved byte-for-byte while the two
  messages became translation-ready.
- **Guard decision:** no new CI guard tooling was created for this rule (no Domain i18n
  allowlist scanner, no new workflow/step) and WPCS configuration was not modified —
  `WordPress.WP.I18n` validates *how* `__()` is called and cannot express a layering rule or
  detect an unwrapped literal. The rule is therefore **documentation/review-based** for now,
  for both Domain and Application.
- **Bounded deferred debt (confirmed still present, out of the declared C9 REST scope):**
  `src/Admin/ClinicianAdminPage.php:436` and `:494` render the Schedule service message raw
  (`'خطا: ' . $e->getMessage()`).

> **Transitional technique — not the preferred long-term architecture.** The two literal
> msgids added at the REST boundary are byte-identical copies of the corresponding service
> source strings. This duplication is a **deliberately bounded TRANSITIONAL compatibility
> technique** for exactly these two C7-introduced messages, forced by an existing tooling
> constraint: `WordPress.WP.I18n.NonSingularStringLiteralText` (enforced on added lines
> against the WPCS baseline) reports a non-literal msgid — including
> `__($e->getMessage(), 'cpms')` and even a class constant — as an ERROR, so a literal msgid
> was the only way to keep the existing gate green with **no** `phpcs:ignore` and **no**
> weakening of WPCS. Duplication between Service and Controller must **not** be documented or
> treated as the preferred long-term architecture; the long-term architecture is the layering
> rule above, and future consolidation may replace this transitional duplication when
> justified by broader evidence. For this bounded task no new abstraction was created solely
> to eliminate two duplicated literals — the REST boundary already had enough information to
> distinguish the variants safely (static controller identity + stable code + empty `data`),
> so a generic error mapper or a `message_key` framework would have been disproportionate.
> Divergence is locked by an **external-behavior** assertion (the default-envelope tests
> compare the REST `message` with the real service exception's `getMessage()`), not by
> comparing two source literals.

---

## J. Git / recovery

- Atomic commits; safe frequent push of **this Arena branch only**
- No merge without Owner; no force push; no tag/release/version bump
- `main` untouched; `/tmp` and Arena sandbox disposable
- Do not touch PR #13 (OPEN + DRAFT diagnostic); PR #10/#11/#12/#14 are MERGED history — do not reopen

A future Agent must: (1) read this file (2) verify Git/remote/PR (3) verify linked docs (4) compare to the repo (5) **STOP** on material mismatch.

---

## K. Current C6 state

Canonical inventory: [`docs/phase-reports/c6-census.md`](phase-reports/c6-census.md)  
Phase 2 queue: [`docs/phase-reports/phase2-state.md`](phase-reports/phase2-state.md)

**C6 is CLOSED** (Owner/Architect decision; integrated via PR #14 into main
`099b644`; deferred boundaries recorded in [`c6-deferred-boundaries.md`](phase-reports/c6-deferred-boundaries.md)).
The checkpoint notes below are **historical pre-closure evidence** (pinned to
their SHAs), not current state.

**Verified at `6e5d48c` (local tripwire, empty allowlist — not a CI job):**

- Baseline (C6-A @ `4152387`): 68 production executable hits
- Production runtime tripwire at `6e5d48c`: **0** (7 comment-only, non-executable)
- Tripwire is **NOT** wired into CI
- Tripwire regex still misses some positional `VALUES (1, …)` INSERT clinic columns

**Trusted REST Clinic context — C6 boundary**

- Implementation checkpoint: `f88fcdc3fb6479782b140a2d7034a3205a52edad` (`TrustedClinicEstablisher` + `RestClinicContext` bound on `rest_request_before_callbacks` / `rest_request_after_callbacks`, `ScopeRequiredException` with explicit HTTP status).
- That checkpoint was **CI RED** (Integration + Real‑WP PR + Real‑WP push; WPCS/PHPStan/Unit/Pilot/Closure green). Evidence, not erased:
  - **Class D (test harness)** — `RestTrustedClinicContextTest::$locB` typed `int` read before initialization: two security tests never reached `rest_do_request`.
  - **Class A (product error contract)** — the boundary applied to *patient* traffic on `/clinic/v1/patients`, `/patients/search`, `/queue`, `/search`, turning the stable `CLINIC_PERMISSION_DENIED` into `CLINIC_SCOPE_UNAVAILABLE` (5 Integration failures).
  - **Class A (product/ops gap)** — Real‑WP `browser.no_console_errors`: 4 staff REST calls from wp‑admin returned 403 because `wp user create --role=…` staff actors never receive an active Membership (Migration 0012 seed runs before them).
  - **Class D (fixture contamination)** — `NotificationFlowTest::testInvoiceReadyNotifiesOtherSecretaries` (2 rows vs 1): the then-new global `set_user_role` membership fixture seeded the doctor, who holds `cpms_queue_read`, so the broadcast gained a recipient.
- Repair batch (this branch, on top of `f88fcdc`, linear — no rewrite, no force‑push):

| SHA | Scope |
|---|---|
| `adecd21` | test: `$locA`/`$locB` explicit init; **global membership injection removed**; explicit idempotent `cpms_test_seed_membership(user, clinic, roleKey)`; staff tests arrange their own Membership |
| `a23b509` | fix(rest): trusted scope applies to **authenticated staff use** only (narrow, via the existing `currentUserIsStaff()` predicate — no route added to / removed from the skip list) |
| `b7a3a6b` | test(acceptance): Real‑WP fixture seeds **active Membership on the Clinic actually resolved in that environment** (from the acceptance clinician link) — TEST INFRASTRUCTURE only |
| `da72e1c` | fix(rest): Scope restoration on the normal and error paths (LIFO pairs + conditional restore); `shutdown` safety net for filter‑level throws/fatals — after an executable test proved the leak. Wording refined after the lifecycle review on `4606b15`: WordPress converts an escaping handler exception to `WP_Error` **before** `rest_request_after_callbacks`, so the `shutdown` net is not what covers the handler‑exception path (executable evidence: `testErrorResponsePathStillRestoresScope`) |
| `4289d89` | test: corrected precondition of the new non‑member‑staff evidence test (Class D, its own assertion) |

- **Production automatic Membership provisioning was NOT introduced**: no `user_register`, no `set_user_role`, no global‑role→tenant mapping, no “there is only one Clinic”, no first/default Clinic. Staff onboarding remains an **OPEN architecture item** for an explicit, scope‑aware product workflow.
- Security invariant intact and now executed: authenticated staff REST access requires an **active verified Clinic Membership**; even in an exact‑one‑Clinic install no Membership means no staff Clinic access (`testNoMembershipDeniedEvenWhenExactlyOneClinic`, `testExactOneClinicIdNotOneStillRequiresMembership`, `testStaffActorWithoutActiveMembershipIsDeniedByBoundary`).
- Real‑WP acceptance fixture models Membership explicitly; the “staff **without** Membership is denied” invariant is proven by the focused Integration tests above (not by the fixture).
- **OPEN DECISION — route classification (deliberately NOT resolved in this repair):** `/prescriptions` skip, `/appointments/{id}/reschedule` vs `/cancel` asymmetry, `GET /visits{,/{id}}` skip, `/files/{id}/stream` + `/patients/{id}/files` ownership-only, `/config/services*`, `/sms/*` — needs the next architecture review.
- Gate evidence for the repair tip: recorded in [`phase2-state.md`](phase2-state.md) (exact Run IDs).

**Already done (do not redo):**

- C6-A census · C6-B Notifications/SMS/Jobs · C6-C Booking/Schedule
- C6-D Patients/Clinical/Visits/Files · C6-E1 Settings/Audit/Idempotency + **Migration 0020**
- C6-E2 repository writes (clinic-first) @ `9e006b0`
- **C6-E3 Reports** @ `1c82d26` (+ Class D test follow-up `a120a68`) — trusted clinic filter, not `clinic_id=1`; OWN = that Clinic + one Clinician profile (`u_clinician_user`)
- **C6 Export** @ `f2c0ca6` — same Clinic on request → job payload → storage → notification → list/download/purge (per-row clinic)
- **Pilot/bin** @ `6e5d48c` — resolve real Clinic/Location IDs; never encode Clinic ID == 1

**Remaining C6** (at `3fc5a54` — all below DONE)

1. Trusted REST context — **implemented and repaired**. Route-classification follow-ups deferred to Phase 3 policy.
2. Tripwire hardening + CI wiring — **DONE**. 34 self-tests PASS, 173 production files CLEAN, CI GREEN.
3. C6-F multi-tenant isolation suite — **DONE**. Matrix 45/45 VERIFIED_GREEN. Real handler runtime tests (MT-39). Non-1 clinic IDs (MT-45).
4. Keep docs in sync after each verified implementation SHA.
5. Route classification — tenant isolation verified; deferred to Phase 3.
6. Staff onboarding (UI/API) — domain primitives complete; deferred to LATER_STAFF_ADMIN_UX.
7. SMS resend key — KNOWN_MEDIUM_DEBT; Migration 0021 NOT APPROVED.

Internal task label in some reports: «Phase 9 §5» — legacy task‑taxonomy wording, **not**
roadmap Phase 9 (Patient Portal — still NOT STARTED).
*(🔴 historical — this whole "Remaining C6" list is scoped to the C6 checkpoint `3fc5a54`, exactly like the
"Migration 0021 NOT APPROVED" item above; preserved as history and not rewritten. Current live truth: Owner
Roadmap Phase 9 — Patient Portal = **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — formal closure by explicit Owner decision 2026-09-23 at checkpoint `72bb3fdda418914d9b7b33eed207c3c1de1a951f` (merge of PR #110) — see the top header block; **Slices 1–3 = CLOSED (BOUNDED)** via **PR #93** / **PR #96** / **PR #99**, **Slice 4 BACKEND FOUNDATION = CLOSED (BOUNDED)** via **PR #101**, **Slice 4 Patient Profile UI = CLOSED (BOUNDED)** via **PR #103**, **My Visits = CLOSED (BOUNDED)** via **PR #105**, **My Prescriptions = CLOSED (BOUNDED)** via **PR #107**, **My Files = CLOSED (BOUNDED)** via **PR #109**; no explicitly required Phase 9 capability remains pending and no additional Phase 9 capability was required at closure (the old live note "next defined capability **My Files** remains NOT IMPLEMENTED / pending; merge `d0644706…` = current main" was true only until the My Files merge and is superseded by the live-main chain recorded at the top) — see the top header block.)*

| SHA | Scope |
|---|---|
| `ddca8d7`…`d52d32d` | tests: `ClinicTenantIsolationTest` — 25 probes; lazy‑registry warm; FK‑safe purge; isolation witness; per‑test tag fixtures |
| `f6703dc` | **fix(files):** C6‑F per‑object tenant guards on `MedicalFileService` (stream/staffFiles/softDelete/store) + `ClinicalService::record()` visit/patient tenant guard; cross‑tenant ⇒ 404 before any disk read/write |
| `f5ebefd` | **fix(visits):** five `clinic_id = 1` literals removed from `VisitService` — trusted clinic via `queueClinicId()` (explicit Scope → exactly‑one resolver → `CLINIC_SCOPE_REQUIRED` 400); `queueScopeClinicianId()` clinic‑scoped; cross‑clinic `clinician_id` → 404 |
| `b7b8399` | **fix(files):** skip‑listed routes resolve trusted Clinic for staff through explicit Scope → system resolver → unique active membership (`TrustedClinicEstablisher`) — never “first Clinic”, never Clinic 1 |
| `bff690a` | **fix(files):** denial envelope byte‑identical to not‑found (existence non‑disclosure; reason only in Audit); fixture hygiene: no writes into `clinic_id=1` (reserved by other suites — see pollution below), `CLINIC_D=61004` + exact‑domain witness, `CPMS_FIXTURE_RESIDUE` tearDown guard |
| `c2bff76` | **test(harness):** cross‑class pollution root cause closed (details below). No product change |

**Pollution root cause (Class D — this class's harness; repaired here, not transferred):**
`App::boot()` is one‑shot and `rest_api_init` captures service instances into route closures
once per process (`App::otpService()` static, `self::$smsService`, `medicalFileService()`,
`exportService()`/`visitService()` as seen by the controllers — all built with the *ambient*
scope/`App::settings()` at first‑REST‑touch). Alphabetically `ClinicTenantIsolationTest` precedes
`ClinicalFlowTest`, so this class was the process's first REST toucher: warming under the fixture
scope pinned every later class to services bound to fake clinic 61001 → later writes failed FK
(clinic deleted; `sms_sent=false`, links `0`), later setting reads returned defaults (OTP cooldown
/`queue.max_recalls`/`sms.provider`), later downloads 404 (row clinic ≠ fresh scope). Fix:
neutral warm **before** fixture clinics exist ⇒ pin == baseline; plus disk‑artifact unlink in
purge (DB rollback never touches disk) and `makeUser` unique‑email fix (real fixture bug that
produced probe‑11's `(int) WP_Error` error).

- Evidence chain: `f5ebefd` CI `34403805829` (queue probes red→green — hardcode defect proven,
  then fixed) · `bff690a` CI `34404449199` (8/11 file probes green; remaining reds diagnosed) ·
  `c2bff76` CI `34406996627` — **all 7 jobs success** (Integration 602 tests, 0E/0F; every victim
  class green again) · Real‑WP `34406991487` · Pilot `34406991520` · Closure `34406991334`.
- Historical RED checkpoints preserved, not erased: `d5072ff` run `34403028668` (20 probe
  failures = defect evidence) and `bff690a` run `34404449199` (6 cross‑class failures = pollution
  evidence).
- No security test was hidden/skipped/quarantined/weakened; `RestClinicContext` skip list
  unchanged in both directions; no schema change; **no Migration 0021** (none required —
  `(id, clinic_id)` reads ride PRIMARY; no clinic‑leading index needed for the new predicates).
- Touched‑path census re‑verified at `c2bff76` (manual grep, production runtime): **0** semantic
  clinic‑1 / first‑clinic / current‑user‑as‑tenant hits in `VisitService`, `MedicalFileService`,
  `ClinicalService::record`, `QueueController`, `FilesController`, `VisitRepository`,
  `MedicalFileRepository`. CI tripwire wiring remains an untouched open item.

### Post‑closure C6 corrective — finance Clinic scope (PR #17 — **MERGED**, integrated at `248ca10`)

Historical truth preserved: **C6 was formally closed**; a finance‑scope omission
(plus detector blind spots) was discovered afterwards and corrected here.
Nothing below rewrites the C6 acceptance record — the omission happened, was
found after closure, and is recorded as such.

**Integration status:** PR #17 is **MERGED** (2026-09-10T20:55:57Z) and is
contained in `origin/main` at `248ca10`. The competing PR #18 carrying the same
corrective was **closed without merge** on 2026-09-11 as superseded; see the
integration-state table above. Scope of the merged corrective:

- seven finance Clinic-ID-1 defects corrected (explicit required Clinic contracts),
- Tenant Tripwire blind spots corrected (bound-parameter tenant literal + 3 suppression defects),
- explicit Clinic scope on all seven runtime paths, no fallback to Clinic 1,
- non-1 / two-Clinic regression protection,
- direct lock-target test (observes the real SQL of the numbering row lock).

- **Class B production defects (pre‑existing, blame F6 `ef59e0cb`):** 7 finance
  runtime paths pinned to Clinic ID 1 — `ServiceRepository::all`,
  `PaymentRepository::{revenueSummary, forRange, nextPaymentNumber}`,
  `InvoiceRepository::{openInvoices, nextInvoiceNumber}`,
  `FinanceService::lockClinic`. Fixed with explicit trusted `int $clinicId`
  contracts (reads via `trustedClinicId()` → `CLINIC_SCOPE_REQUIRED` 400
  fail‑closed; numbering/lock within the visit/invoice Clinic). Impact:
  cross‑Clinic tariff/revenue/payment/invoice reads incl. patient name+MRN,
  wrong per‑Clinic INV/PAY sequences (duplicate‑key failures), wrong‑scope
  row lock.
- **Class D detector defects:** Tenant Tripwire missed bound‑parameter tenant
  literals and had 3 suppression defects (`select_first_clinic` shadowing,
  qualified‑ID swallowing, same‑line benign exemption). Hardened in
  `bin/tenant-tripwire.py` (59 self‑tests, tenant‑aware, empty allowlist).
  Scan now reports **0 hardcodes** (+1 legitimate first‑clinic *suspect* under
  review: `SystemClinicResolver` single‑install resolution, AD‑04).
- **Insert‑failure safety:** the stale‑nonzero‑`insert_id` corruption theory was
  contradicted for standard wpdb (helper resets + clear‑on‑failure, verified
  WP 6.3–6.7+trunk); deterministic sabotaged‑INSERT regression proves current
  behavior already rolls back with no cross‑invoice attach and no payment
  effects — **no insert‑handling product change included**. (Minor robustness
  debt recorded: ignored `CpmsDb::insert` bools + misleading 404 mapping on
  the invoice path — small, deferred.)
- **Tests added:** `tests/Integration/FinanceClinicIsolationTest.php` (18 tests:
  Clinic‑1 compat, non‑1 Clinic, two‑Clinic coexistence, 4 read isolations,
  name/MRN non‑crossing, per‑Clinic INV+PAY numbering, absent‑row operation,
  3 fail‑closed, 4 insert‑safety). Proven meaningful: 13 red‑on‑old
  (run `34487855456`), then green with the fix.
- **Pre-merge branch evidence:** `ac1404f` — CI `34488982706` (all 8 jobs incl.
  Integration 634 tests + tripwire), Real‑WP `34488982705`+`34488978486`,
  Closure `34488978510`, Pilot `34488978503` — **all success**. No test
  weakened/skipped/quarantined.
- **Final post-merge evidence on `248ca10`:** CI `34529280235`, Real‑WP
  `34529280196`, Closure `34529280176`, Pilot `34529280164` **attempt 2** — all
  **success**. Pilot attempt 1 was a **Class C** tooling/environment transient;
  attempt 2 executed the substantive staging steps successfully. Attempt 1 is
  recorded, not erased.
- **No migration** (count unchanged; 0021 neither approved nor created).
  **C7 remediation was implemented on DRAFT PR #20 and subsequently merged
  (PR #20 MERGED 2026-09-11T13:09:16Z, merge `a385d868` — see the C7 section
  below).** Location authorization untouched.

**At the time of this C6 corrective (2026-09-10), the instruction was not to start C8 *implementation*, Phase 4, portals, or mobile auth/JWT.** This is historical; the current Phase 4 status is the bounded technical closure recorded in the current-state row above. *(Phase 3 was a "do not start" item when this C6 corrective was written; it is now COMPLETED/FROZEN — see the Phase 3 status row. The C8 closure status below is unchanged.)*
**C8 closure status (Owner-authorized documentation-only closure, 2026-09-11):**
the existence of the internal/canonical `C8` label did **not** by itself prove
that C8 required implementation work; reviewed evidence verifies **no verified
Phase-2 Location implementation gap**, so C8 is **CLOSED as a
Location-foundation evidence/documentation closure package** — it must **not**
be turned into implementation work merely because its queue label exists. Iran
province/city master-data datasets and their management remain deferred/open outside the bounded Phase 4 closure; final scoped authorization remains Phase 3; operational
Location-timezone consumption remains a recorded boundary/deferred
reconciliation item, not authorization to implement scheduling/reminder/
timezone changes here. Do not reopen merged history
(PR #10/#11/#12/#14/#17/#19/#20/#21); do not touch PR #13.

### C7-0 — evidence foundation (no product change)

A read-only census/characterization pass was opened to decide which remaining
repository/service isolation candidates are **real executable defects** before
any contract change. It changes **no production behavior**, adds **no
migration**, and does **not** start C7 implementation.

Full matrix and per-path evidence: [`docs/phase-reports/c7-0-census.md`](phase-reports/c7-0-census.md).

Headline: a repository method lacking a Clinic parameter is a **candidate**, not
a defect. Several such methods are safe because the calling service enforces
ownership fail-closed (e.g. `MedicalFileService::assertStaffClinic`,
`ClinicalService::assertVisitInActiveClinic`, `PrescriptionRepository::
findForUpdateForClinic`). Others are **confirmed defects by deterministic code
path** and are listed with severity and a proposed implementation slice in the
census. **No C7 fix is applied in this pass.**

The census records **open questions as open**, not as decisions. In particular it
does **not** assert that C7 requires a JobQueue tenant column, that Migration
0021 is required, that prescription numbering must become per-Clinic, or that any
S2/S3 architecture has been approved. Those remain unresolved design/domain
questions pending Owner decision and further evidence. **No migration is
approved or proposed; `0021` does not exist.**

### C7 — remediation state (MERGED into `main` via PR #20 at `a385d868`)

Full slice-by-slice RED→GREEN history with run IDs:
[`docs/phase-reports/c7-0-census.md` §۱۱](phase-reports/c7-0-census.md).

- **Integration status (verified live 2026-09-11):** PR #20 **MERGED**
  2026-09-11T13:09:16Z by `app/arena-ai-coding-agent` (bot). Merge commit
  `a385d868`; parents `4871f84` (main) + `6b438238` (PR head). `origin/main`
  has since advanced **only** via documentation-only PR #21 (merge `b19930fe`,
  head `3589b15d`). The PR title/body retained its original "DO NOT MERGE /
  EXPECTED RED" C7-0 wording while the head had become fully GREEN; at merge
  time **Owner approval for the merge was not evidenced in the repository**
  (historical governance fact, preserved); **the Owner has since provided
  explicit formal acceptance of C7 on 2026-09-11** (see the closure bullet
  below).
- **Scope completed (evidence-driven, all from confirmed cross-Clinic
  characterization defects):** finance object-ID isolation on the seven
  ID-based finance operations; mandatory trusted Clinic context on those
  seven (fail-closed `CLINIC_SCOPE_REQUIRED` 400 when missing);
  `issueInvoice` Visit ownership + invoice-item Service/Tariff ownership;
  Service/Tariff update/deactivate ownership (404 parity, no silent no-op
  success); Schedule update/delete/deleteException isolation; Schedule
  create + createException Clinician ownership; wp-admin boundary
  (ClinicianAdminPage) establishing trusted Clinic context from the
  approved Membership primitives (no/ambiguous membership ⇒ fail closed;
  multi-clinic selection UX not built).
- **Final pre-merge head:** `6b438238` — GREEN on all five canonical
  workflows (attempt 1): CI `34598981613` (PR), Real-WP `34598981627` (PR) +
  `34598978084` (push), Pilot `34598978102`, Closure `34598978147`. Preserved
  final RED evidence: CI `34598629321` at `f2786c5` (Integration annotation:
  Tests 668, Assertions 4504, Failures 1 — job-log body not retrievable in the
  audit sandbox; the fix commit immediately after, `6b438238`, addresses the
  wp-admin `createException` trusted-scope boundary), plus the earlier RED runs
  listed in the census §۱۱.
- **Post-merge gates on `a385d868`:** CI `34602712029`, Real-WP
  `34602711983`, Pilot `34602711956`, Closure `34602711962` — all
  **success** (attempt 1, push event, verified live 2026-09-11).
- **What the merge contains:** product code (`FinanceService`,
  `ScheduleService`, `ScheduleRepository`, `ClinicianAdminPage`), Integration
  characterization/regression tests, pilot tooling (`bin/pilot-smoke.php`),
  two workflow files (`closure-gate.yml`, `real-wp-acceptance.yml` — explicit
  trusted scope around synthetic probe operations required by the new
  fail-closed contracts; no assertion removed, no gate weakened), and two
  documentation files. **No migration, no schema change.**
- **Permanent trust rule (enforced in every changed path):** a
  client/attacker-selected object row (Invoice/Payment/Visit/Schedule/
  Exception/Clinician/Service) is **never** a source of tenant trust; its
  `clinic_id` is only compared against the independently established
  trusted Clinic context. No Clinic-ID-1 fallback.
- **Canonical error semantics:** missing trusted scope ⇒
  `CLINIC_SCOPE_REQUIRED` 400; valid scope + foreign object ⇒
  non-disclosing `CLINIC_NOT_FOUND` 404 byte-parity with a nonexistent id.
- **No Migration** (`0021` does not exist; no schema change). **Phase 3
  NOT STARTED at C7 merge time** (no `AuthorizationService`, no Location policy at that
  point) — *historical: records the C7-merge state (2026-09-11); superseded by Phase 3
  completion (COMPLETED/FROZEN at `ebf8588`; the central Clinic-scoped `AuthorizationService`
  is now established)*. Deferred items remain deferred and are NOT silently approved (S2
  JobQueue tenant-context, S3 prescription numbering, Jobs/SMS/timezone, wp-admin
  multi-clinic selection UX).
- **Defect statement (precise):** the completed C7 scope recorded here has
  **no known open Critical/High defect based on current evidence**. This is
  neither a claim of mathematically bug-free software nor a claim that all
  conceivable repository/service isolation in the entire product is perfect;
  unresolved/deferred design questions stay unresolved.
- **Formal closure status (updated 2026-09-11):** technical C7 closure was
  already evidenced (RED→GREEN chain + all gates GREEN + integration into
  `main`). **C7 is now formally accepted/closed by an explicit Owner decision
  on 2026-09-11.** The acceptance covers the defined/completed C7 scope only:
  it is **not** a claim that all conceivable tenant isolation throughout the
  product is perfect, **not** commercial-readiness approval, and existing
  deferred items (S2 JobQueue tenant-context, S3 prescription numbering,
  Jobs/SMS/timezone, wp-admin multi-clinic selection UX) remain deferred
  unless separately authorized. It does not approve a migration or any
  later-phase implementation.
- **Next queue item (Phase 2):** **C8 is CLOSED** (2026-09-11) as a Phase-2
  Location-foundation evidence/documentation closure package — reviewed
  evidence found **no verified Phase-2 Location implementation gap**; no
  implementation was performed or authorized (see `phase2-state.md` §C8 for
  the closure boundary). The internal queue label order continues with C9
  (i18n audit) and C10 (Performance review), but **neither is authorized for
  implementation by this closure**: C9's canonical acceptance scope is not yet
  sufficiently defined and requires a **bounded evidence/scope determination
  before any implementation** (known previous scan findings about
  Persian/i18n strings are evidence to be reviewed, not authorization for
  mass remediation); C10 has no newly approved implementation scope and
  **performance remains NOT MEASURED** unless actual benchmark evidence
  exists. *(Subsequent development, recorded below and **not** a rewrite of the
  C8 boundary: the C9 bounded evidence/scope determination **was** performed, the
  bounded scope was implemented, integrated through PR #23, and C9 was then formally
  closed by explicit Owner decision. The C8 closure itself still authorized none of that.)*
  The historical "End Gate (26 items)" queue label is **not** a
  defined 26-item acceptance checklist — no such canonical definition exists
  in the repository. After the C10 closure decision (the only remaining queue item),
  Phase 2 End Gate → STOP.
- **C9 bounded implementation (2026-09-11 — historical record of what was delivered):** the required
  bounded evidence/scope determination was performed and the approved bounded scope was then
  implemented and delivered on **PR #23 (base `0fd5c27`)**. The scope was exactly the
  **two A/Low findings that C7 itself introduced** — `ScheduleService.php:389` (C7-S5,
  `04a7a79e`) and `FinanceService.php:1111` (C7-S3, `c4cf9024`), both
  `CLINIC_SCOPE_REQUIRED` / HTTP 400 / `data: []` — made translation-ready at the existing
  REST presentation boundary (`ScheduleController::wrap()`, `FinanceController::staff()`),
  with code/status/data and the default Persian message preserved byte-for-byte. No
  Domain/Application production file changed, no migration/schema change, no generic mapper or
  `message_key` framework, no translator port, no Domain/Application error-architecture
  refactor, no mass legacy i18n cleanup, and no new CI guard tooling. See §I-1 for the forward
  layering rule, the tolerated historical debt (13 Domain / 19 Application sites), and the
  explicitly **transitional** nature of the boundary literal duplication.
  **Historical pre-merge state (preserved, not rewritten):** before integration, the maximum
  permitted state was *"C9 bounded implementation complete / READY FOR ARCHITECT MERGE
  REVIEW"* and **C9 was NOT closed** — formal closure was explicitly reserved as a
  **post-merge continuity decision** to be based on the actual integrated `main` SHA and
  successful post-merge gates on that SHA. No document in this repository claimed C9 closure
  before the Owner decision recorded next.
- **C9 formal closure (2026-09-11 — explicit Owner decision, after successful integration):
  C9 is CLOSED.** The conditions the pre-merge record required were then satisfied and
  independently re-verified: **PR #23 is MERGED** (2026-09-11T19:35:54Z) and the bounded C9
  implementation is **integrated into `main`**; the **integrated checkpoint is the verified
  merge SHA `7146d5bb4167d2ac333000188d404c2aa977b817`**, whose merge parents are exactly
  `0fd5c2790e33a3d233a0b6ecb5c997b85fa5db54` (main before merge) + `c92737bb0a30fbdf13d804a8dcdffa522fd556ae`
  (approved PR #23 head); and **all four post-merge gates on that exact SHA succeeded**
  (CI `34639699703`, Real WordPress Acceptance `34639699751`, Pilot/Staging Readiness Gate
  `34639699635`, Closure Gate `34639699639`; 19 check-runs total on `7146d5bb`, all
  `conclusion: success`, none pending). **On that basis the Owner explicitly approved and
  formally accepted/closed C9.** The acceptance covers **only** the bounded scope already
  integrated through PR #23: (1) the two verified C7-introduced A/Low i18n findings;
  (2) the REST/presentation-boundary remediation; (3) preservation of stable machine-readable
  code/status/data and of the default Persian behavior; (4) the accepted forward
  localization-layering rule (§I-1). It is **not** a claim that all legacy i18n debt is fixed,
  that full English support exists, that globalization is complete, that all wp-admin strings
  are translation-ready, or that Domain/Application historical i18n debt is fixed — and it
  does **not** close Phase 2. The exact-source-message match used for the two C7 messages
  remains a bounded **transitional** compatibility technique, **not** preferred long-term
  architecture. C10 implementation remained NOT STARTED / not authorized at that moment;
  its subsequent documentation-only evidence package is recorded below.
- **Performance evidence status (corrected 2026-09-11 — the flat "NOT MEASURED" wording used
  elsewhere in this closure record was inaccurate):** performance has **limited real staging
  benchmark evidence**, but **commercial / reference-environment performance remains
  NOT MEASURED / not validated**. Verified repository evidence: an executable `ab` benchmark
  step ("Performance benchmark — ab (p50/p95/p99 + RPS + error rate)") lives inside the
  `staging-gate` job of `.github/workflows/pilot-gate.yml`, and executed results are recorded in
  `docs/phase-reports/report-pilot-gate.md` §8 with actual p50/p95/p99/RPS/error-rate figures.
  That staging environment is **not** the canonical commercial/reference performance
  environment, and that report **explicitly did not claim** the approved thresholds were
  satisfied there (§8: «اهداف مصوب (هیچ‌کدام پاس‌شده اعلام نمی‌شوند)»; performance quality-gate
  adjudication is deferred to a reference-server benchmark — Runbook §12.3,
  BLOCKED_BY_ENVIRONMENT). Still **NOT MEASURED**: reference-environment performance against the
  intended NFR thresholds; the public-page/plugin overhead target (p95 < 100ms);
  runtime query-count/N+1 measurement; memory; and meaningful multi-Clinic load scale.
  **No staging number is evidence of production performance**, and this correction does not
  start or authorize C10.
- **C10 evidence/closure package (2026-09-11 — documentation-only; at that time C10 was
  technically eligible for closure with Owner formal closure pending — superseded by the
  Owner closure decision of 2026-09-12 recorded below):** the bounded C10 performance
  evidence review is complete and recorded in
  `docs/phase-reports/c10-performance-evidence.md` (+ `phase2-state.md` §C10). C10 is
  an internal LEVEL-2 Phase-2 performance review/evidence package limited to the
  Multi-Clinic foundation; it is **not** Owner Roadmap Phase 17 (Performance — NOT
  STARTED), not broad commercial performance optimization, and grants no permission
  for speculative optimization, for weakening tenant isolation/security, or for a
  schema migration. Recorded findings: (1) existing executed staging benchmark evidence
  is real (`performance-baseline.md`; `report-pilot-gate.md` §8; the executable `ab`
  step in the `staging-gate` job of `pilot-gate.yml`), but the recorded §8 numeric
  results are **historical (2026-09-06 runs) and pre-date the major Phase-2
  Multi-Clinic C4..C7 changes** — they establish that benchmark tooling existed and
  executed, **not** current Multi-Clinic performance; successful current integrated
  Pilot/Staging gates (latest verified on `bd2634a`, run `34651627290`) establish
  **successful execution/gate behavior only** — the current staging benchmark step
  completed successfully without triggering its configured non-2xx failure condition;
  exact current benchmark metrics (including any numeric error-rate value) were not
  retrievable from Arena and are recorded as NOT MEASURED / NOT RETRIEVED, and
  workflow success does **not** mean NFR latency thresholds passed (the step enforces
  no latency threshold); no
  current reference-environment/commercial performance validation exists; (2) the
  not-measured list (reference-environment NFR thresholds; public-page/plugin overhead
  p95 < 100ms; runtime query-count/N+1; memory; meaningful multi-Clinic load scale) is
  recorded as **future performance/release evidence aligned primarily with Owner
  Phase 17 / release validation — not as C10 blockers**; (3) the bounded Phase-2
  static review found **no VERIFIED performance defect requiring remediation** — code
  inspection is not measured performance, and no global absence of N+1 or all-product
  performance perfection is claimed; (4) for the historical "End Gate (26 items)" label, **no canonical defined
  26-item Phase-2 End Gate checklist was found in the current repository or inspected
  tracked Git history**; the origin of the number is not recorded as provenance fact. **C10 status (historical at that
  point): evidence review complete / technically eligible for closure — owner formal
  closure pending.** Phase 2 remains IN PROGRESS *(as of that C10 checkpoint; superseded
  by the 2026-09-15 reconciliation — Phase 2 is now COMPLETED technically, Phase 3 NOT
  STARTED)*; the Phase 2 End Gate
  and Phase 3 are NOT started. No new reference-server/load-test campaign is required
  merely to close the internal C10 evidence package; broad commercial performance
  engineering remains Owner Roadmap Phase 17.
- **C10 — FORMALLY CLOSED by explicit Owner decision on 2026-09-12.** The C10 evidence
  package was integrated into `origin/main` at **`bdb135e9b3eff9db9fbe104c33bc6c30850c5263`**
  (PR #25 MERGED 2026-09-12T06:42:07Z; parents `bd2634a` + approved head `e2d9ce74`); all
  four post-merge gates on that SHA succeeded (CI `34678813474` · Real WordPress Acceptance
  `34678813477` · Pilot/Staging `34678813488` · Closure Gate `34678813479`). **Scope of this
  closure: only the bounded Phase-2 performance evidence/review.** It does **not** establish
  current latency-NFR compliance, current post-Multi-Clinic load performance, scalability,
  commercial performance readiness, Phase 17 completion, or Phase 2 completion. The
  historical 2026-09-06 §8 benchmark numbers remain historical (pre-C4..C7); workflow
  success remains non-numeric evidence; NOT RETRIEVED / NOT MEASURED items remain exactly
  as recorded in `c10-performance-evidence.md` §3. **Phase 2 remains IN PROGRESS; Phase 3
  and Phase 17 remain NOT STARTED; the Phase 2 End Gate is not defined/passed.** *(Recorded
  at the 2026-09-12 C10 closure; superseded for Phase-2 status only by the 2026-09-15
  reconciliation: Phase 2 = COMPLETED technically, Phase 3 / Phase 17 still NOT STARTED
  at that point — and that note is itself superseded by the Phase 3 completion at `ebf8588`;
  see the top block.)*


---

## Linked canonical docs

- [`docs/roadmap/roadmap.md`](roadmap/roadmap.md)
- [`docs/adr/ADR-0031-organization-clinic-location-scoped-authorization.md`](adr/ADR-0031-organization-clinic-location-scoped-authorization.md)
- [`docs/architecture/phase0.5-target-model.md`](architecture/phase0.5-target-model.md)
- [`docs/security/phase1b-deferred-register.md`](security/phase1b-deferred-register.md)
- [`docs/drift-register.md`](drift-register.md)
- [`docs/phase-reports/c6-census.md`](phase-reports/c6-census.md)
- [`docs/phase-reports/c6-isolation-matrix.md`](phase-reports/c6-isolation-matrix.md)
- [`docs/phase-reports/c7-0-census.md`](phase-reports/c7-0-census.md) — C7-0 evidence foundation (census only, no product change)
- [`docs/architecture/phase2-tenant-context-remediation-design.md`](architecture/phase2-tenant-context-remediation-design.md) — **🟢 SCOPE-CLASSIFICATION DESIGN IMPLEMENTED (2026-09-15 reconciliation)**: the T/S/W job classification (§A-3) is now enforced at runtime by `JobScopeRegistry`/`JobScopeClass` + `JobsDispatcher` (fail-closed). The document body's earlier "NOT YET IMPLEMENTED" wording is a **historical snapshot** of the pre-implementation state and is preserved, not re-edited. Scope: tenant context for background jobs, system-wide job classification, Location operational timezone, per-Clinic SMS configuration resolution, operational-log tenant attribution, legacy-job backfill rules, the **6 pre-implementation design questions** (recorded against the Owner's 2026-09-12 decisions; this document **reserves no migration number**), and the **14-item RED test specification RT-1..RT-14**. Independent read-only architecture review 2026-09-12 → Owner verdict **`B — NEEDS SMALL DOCUMENTATION CORRECTIONS`**; corrections **C-1..C-9** applied (notably: SMS credentials ARE stored per-Clinic in `cpms_settings.sms.auth` as sealed AES-256-GCM material per ADR-0025, and the installation-level vault key means cryptography alone does not enforce Clinic isolation)
- Tripwire: `bin/tenant-tripwire.py` (CI-wired, 59 self-tests)
- [`docs/handoff/phase2-c6-to-next-agent.md`](handoff/phase2-c6-to-next-agent.md)
