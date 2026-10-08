# Invitation Requests Action Buttons Broken — Bugfix Design

## Overview

After converting the invitation requests list to use `AjaxList`, the Approve, Resend, and Reject action buttons silently fail. The bug is caused by `data-post-inplace` forms with `action=""` — after `AjaxList` calls `history.replaceState`, the DOM property `form.action` resolves the empty attribute against the **current** `location.href`, which is now the clean URL (e.g. `/YesParency/admin/invitation_requests`) rather than the original PHP file. The `.htaccess` only rewrites GET/HEAD clean URLs to `.php`, so the POST never reaches the PHP handler. The server returns a redirect, `fetch(redirect:'manual')` swallows it, and `location.replace` fires — giving the false appearance of success while no action was actually taken.

The fix uses two complementary layers:
1. **Option B (primary)**: Set `action="invitation_requests.php"` explicitly on all three `data-post-inplace` forms so `form.action` always resolves to the correct PHP URL regardless of `replaceState`.
2. **Option A (defensive)**: Patch the `data-post-inplace` handler in `ajax-list.js` to resolve the POST target from `form.getAttribute('action')` (the raw attribute) rather than `form.action` (the reflected DOM property), and ensure the resolved path ends with `.php` when the raw attribute is empty or extension-less.

---

## Glossary

- **Bug_Condition (C)**: The condition under which the action buttons silently fail — when a `data-post-inplace` form has `action=""` and `history.replaceState` has been called, causing `form.action` to resolve to a clean URL without a `.php` extension.
- **Property (P)**: The desired behavior — the POST reaches the PHP handler, the action executes, and the page reflects the result after reload.
- **Preservation**: All existing behaviors not involving `data-post-inplace` form submission (AjaxList filter/search/pagination, modal open/close, GET form submissions, toast display) must remain unchanged.
- **`form.action`**: The reflected DOM property — always returns a fully-resolved absolute URL based on the current `document.URL` / `location.href`. For `action=""`, this is identical to `location.href` at the time of submission.
- **`form.getAttribute('action')`**: The raw HTML attribute value — returns the literal string in the markup (`""` or `"invitation_requests.php"`). Not affected by `history.replaceState`.
- **`history.replaceState`**: Browser API used by `AjaxList` to sync the address bar with the current filter state. Changes `location.href` but does NOT change the true page origin or reload the page.
- **PRG pattern**: Post-Redirect-Get. PHP handlers redirect after POST using `header("Location: ...")`. The `data-post-inplace` handler uses `redirect:'manual'` to suppress the fetch following that redirect, then manually calls `location.replace`.
- **`redirect:'manual'`**: A `fetch()` option that returns an opaque redirect response without following it. Required here so the PHP session flash messages survive to the reload.

---

## Bug Details

### Bug Condition

The bug manifests when a user clicks Approve, Resend, or Reject on the invitation requests page **after** `AjaxList` has performed at least one filter/search/pagination fetch (i.e. after `history.replaceState` has been called). The `data-post-inplace` submit handler in `ajax-list.js` reads `form.action` — the DOM property — which by this point resolves `action=""` to the replaceState'd clean URL. The POST goes to the clean URL, `.htaccess` does not rewrite it for POST, and the PHP handler never receives the request.

**Formal Specification:**
```
FUNCTION isBugCondition(form, context)
  INPUT:
    form    — an HTMLFormElement with [data-post-inplace] and method="POST"
    context — the browser navigation state at time of submit
  OUTPUT: boolean

  cleanUrl  := context.locationHref has no file extension in the final path segment
  emptyAttr := form.getAttribute('action') === '' OR form.getAttribute('action') is null
  postInplace := form.hasAttribute('data-post-inplace')

  RETURN postInplace
         AND emptyAttr
         AND cleanUrl
END FUNCTION
```

### Examples

- **Bug triggers — Approve after filter**: User clicks the "Pending" filter tab (triggers `replaceState` to `/YesParency/admin/invitation_requests?status=pending`), then clicks Approve. `form.action` resolves to `http://localhost/YesParency/admin/invitation_requests?status=pending`. POST goes there. `.htaccess` does not match POST. PHP never executes. `location.replace` fires and page reloads — **no action taken, no feedback**.
- **Bug triggers — Reject on fresh page load if replaceState has fired**: Even on the very first load, if the user clicks any filter chip before clicking Reject (opening the modal), the reject form's POST will go to the clean URL.
- **Bug triggers — Resend after search**: User searches for a company, AjaxList fires and calls `replaceState`. User clicks Resend. Same failure.
- **Bug does NOT trigger on fresh page load (no filter interaction)**: If the user clicks an action button without ever triggering `AjaxList` (no filter/search/pagination clicks), `location.href` still contains `.php`, so `form.action` resolves correctly. The bug is intermittent, which explains why it may pass cursory testing.

---

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- AjaxList filter, search, and pagination must continue to work exactly as before (GET requests with `?ajax=1`, `replaceState` URL sync).
- The reject modal open/close lifecycle (`openRejectModal` / `closeRejectModal` JS functions) must remain unchanged.
- The GET filter form (`#requestFilterForm`) submits via AjaxList — not affected by this fix (it is not `data-post-inplace`).
- Toast alerts (success/error) rendered from `$_SESSION` flash messages must continue to display correctly after action submission.
- All other pages using `data-post-inplace` (if any) must continue to work — the `ajax-list.js` fix must be backward-compatible.
- Stat card counts (Total, Pending, Approved, Rejected) must update correctly on page reload after an action.

**Scope:**
All inputs that do NOT involve `data-post-inplace` POST submissions should be completely unaffected by this fix. This includes:
- AjaxList GET fetches (filter chips, search input, pagination links)
- Mouse interactions with the modal backdrop
- Any page that uses `AjaxList.init` without `data-post-inplace` forms

---

## Hypothesized Root Cause

Based on the bug description and code inspection, the confirmed root cause chain is:

1. **`form.action` DOM property reflects `location.href`, not the markup attribute**: The HTML spec defines `form.action` as returning a fully resolved URL based on the document's current base. When `action=""`, this equals `location.href` at read time. This is correct browser behavior — the bug is in how the handler consumes it.

2. **`history.replaceState` changes `location.href` without reloading**: `AjaxList.load()` calls `history.replaceState(...)` after every successful fetch. The pathname after this call lacks the `.php` extension (e.g. `/YesParency/admin/invitation_requests`) because `.htaccess` 301-redirected the original `.php` URL to the clean URL on page load.

3. **`.htaccess` rewrite rule is limited to GET/HEAD**: The rewrite condition likely uses `RewriteCond %{REQUEST_METHOD} GET [OR]` or similar, intentionally excluding POST to avoid rewriting API endpoints. This is correct practice but means clean-URL POSTs fall through to a 404 or default handler.

4. **`fetch(redirect:'manual')` suppresses the error signal**: Even if the server returns a 301/302 (or a 404), `redirect:'manual'` causes `fetch` to resolve rather than reject, so the `.catch()` fallback never fires. The `.then(() => location.replace(...))` executes regardless of whether the action succeeded, giving a false success appearance.

5. **Root forms have `action=""` instead of an explicit path**: The three affected forms in `invitation-requests-list.php` (approve, resend) and `invitation_requests.php` (reject modal) all omit a meaningful `action` attribute, relying on implicit self-submission — which is safe for full-page navigation but unsafe when combined with `history.replaceState`.

---

## Correctness Properties

Property 1: Bug Condition — POST Reaches PHP Handler

_For any_ `data-post-inplace` form submission where the bug condition holds (`isBugCondition` returns true — empty `action` attribute, clean `location.href`, POST method), the fixed code SHALL resolve the POST target to a URL whose path ends with `.php`, ensuring the request reaches the PHP action handler and the server executes the intended action (approve / resend / reject).

**Validates: Requirements 2.1, 2.2, 2.3**

Property 2: Preservation — Non-POST Behavior Unchanged

_For any_ interaction where the bug condition does NOT hold — including AjaxList GET fetches, non-`data-post-inplace` form submissions, modal open/close interactions, and `data-post-inplace` forms that already carry an explicit `.php` action — the fixed code SHALL produce exactly the same behavior as the original code, preserving all existing AjaxList filter/search/pagination functionality and UI state.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4**

---

## Fix Implementation

### Changes Required

**File 1**: `admin/partials/invitation-requests-list.php`

**Affected forms**: Approve form (~line 78), Resend form (~line 87)

**Specific Changes**:
1. **Approve form**: Change `action=""` → `action="invitation_requests.php"` on the pending-status approve form.
2. **Resend form**: Change `action=""` → `action="invitation_requests.php"` on the approved-status resend form.

These are the only two `data-post-inplace` forms in the partial. (The reject button in the partial is a `<button type="button">` that opens a modal — it has no form and is not affected.)

---

**File 2**: `admin/invitation_requests.php`

**Affected form**: `#rejectForm` in the reject modal

**Specific Changes**:
3. **Reject form**: Change `action=""` → `action="invitation_requests.php"` on `<form method="POST" action="" id="rejectForm" data-post-inplace>`.

---

**File 3**: `js/ajax-list.js`

**Affected section**: The `data-post-inplace` document-level submit listener

**Specific Changes**:
4. **Defensive URL resolution**: Replace the direct use of `form.action` (DOM property) with a resolution that uses `form.getAttribute('action')` (raw attribute) and resolves it against the **original page URL** (captured before any `replaceState`). Concretely: if the resolved path has no file extension, append `.php`. This is a belt-and-suspenders fix that covers any other `data-post-inplace` form on any page that might have an empty or extension-less action.

**Pseudocode for the defensive fix in `ajax-list.js`:**
```
FUNCTION resolvePostTarget(form)
  rawAction := form.getAttribute('action')   // "" or null or "foo.php"
  IF rawAction is empty or null THEN
    // Fall back to the current path, but ensure .php extension
    path := window.location.pathname
    IF path does NOT end with '.php' THEN
      path := path + '.php'
    END IF
    RETURN origin + path + window.location.search
  ELSE
    // Resolve relative action against the original document URL (not replaceState'd href)
    RETURN new URL(rawAction, document.baseURI).href
  END IF
END FUNCTION
```

The actual implementation replaces:
```javascript
fetch(form.action, { ... })
```
with:
```javascript
const rawAction = form.getAttribute('action');
const target = rawAction
    ? new URL(rawAction, document.baseURI).href
    : (location.origin + location.pathname.replace(/(?:\.php)?$/, '.php') + location.search);
fetch(target, { ... })
```

Note: `document.baseURI` is set at page load time from the real document URL and is NOT affected by `history.replaceState`, making it the correct base for resolving relative `action` attributes.

---

## Testing Strategy

### Validation Approach

Testing follows a two-phase approach:
1. **Exploratory**: Run tests against the **unfixed** code to surface counterexamples and confirm the root cause.
2. **Fix + Preservation**: After applying the fix, verify that all bug-condition inputs now succeed AND that all non-bug inputs behave identically to before.

---

### Exploratory Bug Condition Checking

**Goal**: Surface counterexamples demonstrating the bug on unfixed code. Confirm that `form.action` resolves to the clean URL after `replaceState`, and that the PHP handler never fires.

**Test Plan**: Simulate the `replaceState` side effect (set `location.href` to the clean URL via a `history.replaceState` call in the test setup), then programmatically submit each `data-post-inplace` form and observe whether the POST goes to the `.php` URL or the clean URL.

**Test Cases**:
1. **Approve after filter (replaceState fired)**: Simulate `history.replaceState` to `/YesParency/admin/invitation_requests`, then submit the approve form. Assert that `fetch` was called with a URL ending in `.php`. *(Will fail on unfixed code — fetch is called with the clean URL.)*
2. **Resend after search (replaceState fired)**: Same setup, submit the resend form. *(Will fail on unfixed code.)*
3. **Reject modal after filter (replaceState fired)**: Open reject modal, simulate `replaceState`, submit `#rejectForm`. *(Will fail on unfixed code.)*
4. **Approve on fresh page (no replaceState)**: Submit the approve form immediately without any filter interaction. *(May pass on unfixed code — confirms the bug is intermittent.)*

**Expected Counterexamples**:
- `fetch` is called with `http://localhost/YesParency/admin/invitation_requests` (no `.php`) instead of `http://localhost/YesParency/admin/invitation_requests.php`.
- Server returns a non-2xx response or opaque redirect; no PHP action executes; row status unchanged in the database.

---

### Fix Checking

**Goal**: Verify that for all inputs where the bug condition holds, the fixed code POSTs to the correct `.php` URL and the action succeeds.

**Pseudocode:**
```
FOR ALL form WHERE isBugCondition(form, context) DO
  target := resolvePostTarget_fixed(form)
  ASSERT target.pathname ENDS WITH '.php'
  result := POST target WITH form data
  ASSERT result IS success (row updated in DB, session flash set)
END FOR
```

**Test Cases**:
1. **Approve after replaceState**: Submit approve form after `replaceState` — assert DB row status changes to `approved` and invitation email is queued.
2. **Resend after replaceState**: Submit resend form — assert new invitation token is generated.
3. **Reject via modal after replaceState**: Submit reject form — assert DB row status changes to `rejected` and `admin_notes` are stored.
4. **Action with query string preserved**: `location.href` includes `?status=pending&page=2` — assert POST still goes to `.php` path, and `location.replace` restores the same filter state.

---

### Preservation Checking

**Goal**: Verify that for all inputs where the bug condition does NOT hold, the fixed code behaves identically to the original.

**Pseudocode:**
```
FOR ALL interaction WHERE NOT isBugCondition(form, context) DO
  ASSERT behavior_fixed(interaction) = behavior_original(interaction)
END FOR
```

**Testing Approach**: Property-based testing is recommended for the `ajax-list.js` fix because:
- It can generate many combinations of `rawAction` values and `pathname` values to verify the URL resolution logic is correct across the full input domain.
- It catches edge cases (double `.php`, already-absolute actions, paths with `.` in directory names) that manual unit tests might miss.

**Test Cases**:
1. **AjaxList GET fetch preservation**: Trigger each filter chip and search input after fix — assert AjaxList still loads the list fragment with `?ajax=1` and calls `replaceState` correctly.
2. **Explicit action forms (not empty)**: Any form where `action="foo.php"` (non-empty raw attribute) — assert `resolvePostTarget` returns the same URL as before the fix.
3. **Modal open/close**: Click Reject button, open modal, cancel — assert no regression in modal behavior.
4. **Toast display**: After a successful action, assert the session flash message renders as a toast on reload.
5. **Pagination after fix**: Click pagination links inside the list root — assert AjaxList pagination still works.

---

### Unit Tests

- Test `resolvePostTarget` in isolation: given various `rawAction` values (`""`, `null`, `"invitation_requests.php"`, `"../foo.php"`) and various `location.pathname` values (`.php`, clean, with subdirectory), assert correct absolute URL output.
- Test that a `data-post-inplace` form with an explicit `action="invitation_requests.php"` correctly POSTs to that URL regardless of `replaceState` state.
- Test edge case: `location.pathname` already ends with `.php` (page loaded without `.htaccess` redirect) — assert no double `.php` suffix.
- Test edge case: `location.pathname` ends with `.php.php` is never produced.

### Property-Based Tests

- Generate random `(rawAction, pathname)` pairs and verify `resolvePostTarget` always returns a URL ending in `.php` when `rawAction` is empty.
- Generate random `rawAction` values that are non-empty relative paths and verify the resolved URL matches `new URL(rawAction, document.baseURI)` — i.e. the fix does not alter explicit action attributes.
- Generate random filter states (search string, status filter, page number) and verify that the POST target is always `.php`-suffixed regardless of what `replaceState` has set.

### Integration Tests

- Full flow: Load invitation requests page → click "Pending" filter → click Approve on a pending row → verify page reloads to the same filter state → verify the row now shows "Approved" status and stat card counts are updated.
- Full flow: Load page → search for a company → click Resend on an approved row → verify success toast appears and row reflects updated timestamp.
- Full flow: Load page → click Reject button → fill in notes → submit → verify row status is "Rejected" and admin notes are displayed.
- Regression: Load page → perform several filter/pagination operations → verify AjaxList still works correctly after the `ajax-list.js` patch.
