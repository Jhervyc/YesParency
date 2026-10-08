# Implementation Plan

- [ ] 1. Write bug condition exploration test
  - **Property 1: Bug Condition** - POST Goes to Clean URL After replaceState
  - **CRITICAL**: This test MUST FAIL on unfixed code — failure confirms the bug exists
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: This test encodes the expected behavior — it will validate the fix when it passes after implementation
  - **GOAL**: Surface counterexamples demonstrating that `form.action` resolves to the clean URL (no `.php`) after `history.replaceState`, causing the POST to miss the PHP handler
  - **Scoped PBT Approach**: For deterministic reproduction, scope the property to the three concrete failing cases (approve form, resend form, reject modal form), each tested after simulating `history.replaceState` to the clean URL
  - **Setup**: For each `data-post-inplace` form (`approve`, `resend`, `#rejectForm`), mock `history.replaceState` to set `location.href` to `/YesParency/admin/invitation_requests` (no `.php`)
  - **Test assertions** (from Expected Behavior in design):
    - Intercept the `fetch` call and assert the URL passed ends with `.php` (e.g. `.../invitation_requests.php`)
    - On unfixed code — the URL will NOT end with `.php`, confirming the bug
  - Run test on UNFIXED code
  - **EXPECTED OUTCOME**: Test FAILS — `fetch` is called with `http://localhost/YesParency/admin/invitation_requests` (clean URL, no `.php`), not `.../invitation_requests.php`
  - Document counterexamples found (e.g., "approve form posts to `…/invitation_requests` instead of `…/invitation_requests.php` after replaceState to clean URL")
  - Mark task complete when test is written, run, and failure is documented
  - _Requirements: 2.1, 2.2, 2.3_

- [ ] 2. Write preservation property tests (BEFORE implementing fix)
  - **Property 2: Preservation** - Non-POST and Explicit-Action Behavior Unchanged
  - **IMPORTANT**: Follow observation-first methodology
  - **Setup**: Run the UNFIXED code and observe behavior for all non-bug-condition interactions
  - **Observe on unfixed code**:
    - AjaxList filter chip click triggers GET fetch with `?ajax=1` and calls `replaceState` correctly
    - AjaxList search input triggers GET fetch with `?ajax=1&search=…` and calls `replaceState`
    - AjaxList pagination links trigger GET fetch correctly
    - Modal open/close (`openRejectModal` / `closeRejectModal`) works without side effects
    - A `data-post-inplace` form with an explicit non-empty `action="invitation_requests.php"` resolves the POST target to that `.php` URL (unaffected by `replaceState` state)
    - A `data-post-inplace` form on a page where `replaceState` has NOT been called posts to the correct URL (bug is intermittent — only triggers after `replaceState`)
  - **Write property-based tests** capturing these observed behaviors from Preservation Requirements in design:
    - For all `rawAction` values that are non-empty strings: `resolvePostTarget(form)` returns `new URL(rawAction, document.baseURI).href` (identical to the original `form.action` when no `replaceState` has fired and action is explicit)
    - For all GET interactions: AjaxList fetch URL always contains `?ajax=1` and never `.php` is stripped by the fix
    - For all modal interactions: no fetch is intercepted by the `data-post-inplace` listener (buttons are `type="button"`, not submit)
  - Run all preservation tests on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS — confirms baseline behavior to preserve after the fix
  - Mark task complete when tests are written, run, and passing on unfixed code
  - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [ ] 3. Fix invitation requests action buttons

  - [ ] 3.1 Add explicit action attribute to approve and resend forms in the list partial
    - File: `admin/partials/invitation-requests-list.php`
    - Change `action=""` → `action="invitation_requests.php"` on the approve form (~line 78)
    - Change `action=""` → `action="invitation_requests.php"` on the resend form (~line 87)
    - Both forms carry `data-post-inplace` and `method="POST"`
    - _Bug_Condition: isBugCondition(form, context) where form.getAttribute('action') === '' AND location.href is clean URL (no .php)_
    - _Expected_Behavior: POST target resolves to a URL ending in `.php`, reaching the PHP handler_
    - _Preservation: Only these two forms are modified; AjaxList GET behavior and all other forms are untouched_
    - _Requirements: 2.1, 2.2_

  - [ ] 3.2 Add explicit action attribute to the reject modal form
    - File: `admin/invitation_requests.php`
    - Change `action=""` → `action="invitation_requests.php"` on `<form method="POST" action="" id="rejectForm" data-post-inplace>`
    - _Bug_Condition: isBugCondition(form, context) where form.getAttribute('action') === '' AND location.href is clean URL_
    - _Expected_Behavior: Reject POST reaches PHP handler; DB row status updated to rejected; admin_notes stored_
    - _Preservation: Modal open/close lifecycle (openRejectModal / closeRejectModal) is untouched_
    - _Requirements: 2.1, 2.3_

  - [ ] 3.3 Patch data-post-inplace URL resolution in ajax-list.js (defensive fix)
    - File: `js/ajax-list.js`
    - Locate the `data-post-inplace` document-level submit listener
    - Replace `fetch(form.action, { ... })` with the defensive resolution:
      ```javascript
      const rawAction = form.getAttribute('action');
      const target = rawAction
          ? new URL(rawAction, document.baseURI).href
          : (location.origin + location.pathname.replace(/(?:\.php)?$/, '.php') + location.search);
      fetch(target, { ... })
      ```
    - `document.baseURI` is set at page load and is NOT affected by `history.replaceState`, making it a safe base for resolving relative action attributes
    - The `replace(/(?:\.php)?$/, '.php')` regex ensures no double `.php` suffix is ever produced
    - This is a belt-and-suspenders fix that covers any other page using `data-post-inplace` forms with empty/extension-less actions
    - _Bug_Condition: isBugCondition(form, context) — emptyAttr AND cleanUrl AND postInplace_
    - _Expected_Behavior: resolvePostTarget(form) always returns a URL ending in .php when rawAction is empty_
    - _Preservation: When rawAction is non-empty, resolves via new URL(rawAction, document.baseURI) — identical to original behavior for pages unaffected by replaceState_
    - _Requirements: 2.1, 2.2, 2.3, 3.1, 3.2, 3.3, 3.4_

  - [ ] 3.4 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - POST Goes to .php URL After replaceState
    - **IMPORTANT**: Re-run the SAME test from task 1 — do NOT write a new test
    - The test from task 1 asserts `fetch` is called with a URL ending in `.php` — this now encodes the expected behavior
    - Re-run all three scoped cases (approve, resend, reject) after simulating `replaceState` to the clean URL
    - **EXPECTED OUTCOME**: Test PASSES — confirms the bug is fixed across all three action buttons
    - _Requirements: Expected Behavior Properties from design (2.1, 2.2, 2.3)_

  - [ ] 3.5 Verify preservation tests still pass
    - **Property 2: Preservation** - Non-POST and Explicit-Action Behavior Unchanged
    - **IMPORTANT**: Re-run the SAME tests from task 2 — do NOT write new tests
    - Run all preservation property tests from step 2 against the fixed code
    - **EXPECTED OUTCOME**: Tests PASS — confirms no regressions in AjaxList GET fetches, modal behavior, explicit-action forms, and page interactions where replaceState has not fired
    - Confirm all tests still pass after fix (no regressions)

- [ ] 4. Checkpoint — Ensure all tests pass
  - Run the full test suite (exploration test + preservation tests)
  - Verify Property 1 (bug condition) PASSES on fixed code
  - Verify Property 2 (preservation) PASSES on fixed code
  - Manually smoke-test the full flow end-to-end: load invitation requests page → click a filter tab → Approve a pending row → verify page reloads to same filter state with row showing Approved status
  - Manually smoke-test reject flow: load page → filter → click Reject → fill notes → submit → verify Rejected status and admin notes saved
  - Manually smoke-test resend flow: load page → search → click Resend → verify success toast and updated timestamp
  - Verify AjaxList filter/search/pagination still works after the `ajax-list.js` patch
  - Ensure all tests pass; ask the user if questions arise
