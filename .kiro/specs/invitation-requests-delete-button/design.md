# Invitation Requests Delete Button — Bugfix Design

## Overview

The invitation requests management page (`admin/invitation_requests.php`) currently provides
Approve, Reject, and Resend Invite actions, but has no way to permanently remove a request
record. Records in `rejected` status display "No actions" and are effectively stranded in
the database forever. This missing capability is the bug: there is no delete action in the
UI or on the server.

The fix adds a Delete button visible for **all statuses** (pending, approved, rejected),
backed by a transactional POST handler that removes both the `invitation_requests` row and
any linked `user_invitations` token for that email, then writes an audit log entry.

---

## Glossary

- **Bug_Condition (C)**: The condition that reveals the missing capability — an admin visits the
  invitation requests page and wants to permanently remove a request record, but no Delete
  button or server handler exists.
- **Property (P)**: The desired behavior once fixed — a Delete button is present for every
  request row regardless of status, and submitting it removes the record (plus any linked
  invitation token) atomically, logs the deletion, and shows a flash message.
- **Preservation**: All existing approve, resend, and reject flows, button styles, mobile card
  layout, AJAX list refresh, and audit log patterns must remain byte-for-byte identical in
  behavior after the fix.
- **`invitation_requests`**: The MySQL table that stores company access requests with fields
  `id`, `company_name`, `email`, `contact_person`, `status`, `reviewed_by`, `reviewed_at`, etc.
- **`user_invitations`**: The MySQL table that stores pending invitation tokens keyed by
  `email`. Deleting a request should also invalidate any outstanding token for that email.
- **`inline-form`**: The `<form>` pattern used by all action buttons on this page:
  `<form method="POST" action="invitation_requests.php" class="inline-form" data-post-inplace>`.
- **`data-post-inplace`**: HTML attribute consumed by `js/ajax-list.js` to submit the form
  via fetch and refresh `#requestListRoot` without a full page reload.
- **`audit_log()`**: The shared helper function called after every write operation. Signature:
  `audit_log($conn, $event, $table, $row_id, $description, $old_data, $new_data)`.

---

## Bug Details

### Bug Condition

The bug manifests when an admin navigates to `admin/invitation_requests.php` and needs to
permanently delete a request record. The page renders rows for every request, but no Delete
button is rendered in the `actions-cell` for any status, and the server has no POST handler
for `action=delete`. Consequently the action is impossible to perform through the UI.

**Formal Specification:**
```
FUNCTION isBugCondition(context)
  INPUT: context — the current state of the invitation_requests page
  OUTPUT: boolean

  RETURN deleteButtonExistsInActionsCell(context)  = FALSE
         OR serverHandlesAction(context, 'delete')  = FALSE
END FUNCTION
```

### Examples

- **Rejected request (most common)**: Admin views a rejected request. The actions cell shows
  "No actions" text. Expected: a Delete button appears; clicking it removes the row.
- **Pending request**: Admin wants to discard an obvious spam/invalid request without going
  through the Approve → Reject cycle. Expected: Delete button removes the row immediately.
- **Approved request whose invitee never registered**: Admin wants to clean up stale records.
  Expected: Delete button removes both the `invitation_requests` row and the pending token in
  `user_invitations` so the token can no longer be used.
- **Edge case — invalid `req_id`**: A forged or stale POST sends a non-existent `req_id`.
  Expected: server returns an error flash and redirects without crashing.

---

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- Approve action for pending requests must continue to work exactly as before — generates a
  token, inserts into `user_invitations`, updates status to `approved`, sends email.
- Resend invite action for approved requests must continue to work exactly as before —
  deletes old pending token, inserts fresh token, sends email.
- Reject action (modal + optional notes) must continue to work exactly as before — updates
  status to `rejected`, sends rejection email.
- Button styling for `.req-btn.approve`, `.req-btn.reject`, `.req-btn.resend` must be
  unchanged — no CSS specificity collisions introduced by the new `.req-btn.delete` rule.
- Mobile card layout (`@media (max-width: 639px)`) flex-row in `.actions-cell` must continue
  to show all buttons correctly, including the new Delete button.
- AJAX list refresh via `data-post-inplace` and `js/ajax-list.js` must continue to work for
  all action forms including the new Delete form.
- Audit log entries for approve, resend, and reject events must be unaffected.

**Scope:**
All requests that an admin does NOT attempt to delete are completely unaffected by this fix.
The only new code path exercised is `$_POST['action'] === 'delete'`.

---

## Hypothesized Root Cause

This is a missing-capability bug, not a regression. The root causes are:

1. **No server-side handler**: `admin/invitation_requests.php` has handlers for `approve`,
   `resend`, and `reject`, but no handler block for `action=delete`. Any POST to that
   endpoint with `action=delete` falls through to the stats/filter section silently.

2. **No Delete button in the partial**: `admin/partials/invitation-requests-list.php` renders
   status-conditional buttons. The `rejected` branch shows only "No actions" text; the
   `pending` and `approved` branches do not include a Delete button either.

3. **No CSS rule for `.req-btn.delete`**: `css/pages/admin-invitation-requests.css` defines
   `.req-btn.approve`, `.req-btn.reject`, and `.req-btn.resend` but not `.req-btn.delete`.
   Without a rule the button would render without the destructive-red visual treatment that
   distinguishes it from other actions.

---

## Correctness Properties

Property 1: Bug Condition — Delete Button Removes Request Record

_For any_ invitation request row (regardless of status) where an admin submits the Delete
action with a valid `req_id`, the fixed `action=delete` handler SHALL atomically delete the
`invitation_requests` row and any linked `user_invitations` rows for that email, write an
`INVITATION_REQUEST_DELETED` audit log entry, set a success flash message, and redirect back
to the same filtered page — leaving no orphaned data.

**Validates: Requirements 2.1, 2.2, 2.3**

Property 2: Preservation — Existing Actions Unaffected

_For any_ admin action that is NOT `action=delete` (i.e., approve, resend, reject, or page
view), the fixed code SHALL produce exactly the same behavior as the original code, preserving
all existing database mutations, email sending, audit log entries, button rendering, mobile
layout, and AJAX refresh behavior.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4**

---

## Fix Implementation

### Changes Required

**File 1**: `admin/invitation_requests.php`

**Where**: Before the `// ── Stats ──` section (after the existing `reject` handler block).

**Specific Changes**:

1. **Add `action=delete` handler block**:
   ```php
   // ── Delete request ────────────────────────────────────────────────────────────
   if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
       $req_id = intval($_POST['req_id'] ?? 0);

       $row = $conn->prepare("SELECT * FROM invitation_requests WHERE id = ? LIMIT 1");
       $row->bind_param("i", $req_id);
       $row->execute();
       $req = $row->get_result()->fetch_assoc();
       $row->close();

       if (!$req) {
           $_SESSION['alert_error'] = "Request not found.";
           header("Location: invitation_requests.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); exit();
       }

       $conn->begin_transaction();
       try {
           $del_inv = $conn->prepare("DELETE FROM user_invitations WHERE email = ?");
           $del_inv->bind_param("s", $req['email']);
           $del_inv->execute();
           $del_inv->close();

           $del_req = $conn->prepare("DELETE FROM invitation_requests WHERE id = ?");
           $del_req->bind_param("i", $req_id);
           $del_req->execute();
           $del_req->close();

           $conn->commit();
       } catch (Exception $e) {
           $conn->rollback();
           $_SESSION['alert_error'] = "Failed to delete request: " . $e->getMessage();
           header("Location: invitation_requests.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); exit();
       }

       audit_log($conn, 'INVITATION_REQUEST_DELETED', 'invitation_requests', $req_id,
           "Deleted invitation request from {$req['company_name']} ({$req['email']})",
           $req,
           []
       );

       $_SESSION['alert_success'] = "Request from {$req['company_name']} deleted.";
       header("Location: invitation_requests.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); exit();
   }
   ```

---

**File 2**: `admin/partials/invitation-requests-list.php`

**Where**: Inside the `<td class="actions-cell">` block, after each status-conditional button
group. The Delete form is added at the end of all status branches so it always appears last.

**Specific Changes**:

2. **Replace the `rejected` "No actions" span and append Delete to all branches**:
   - Remove `<span class="no-actions-text">No actions</span>` from the `else` (rejected) branch.
   - After the closing `<?php endif; ?>` of the status conditional, add the Delete form for
     all statuses:
   ```php
   <!-- Delete — shown for all statuses -->
   <form method="POST" action="invitation_requests.php" class="inline-form" data-post-inplace>
       <input type="hidden" name="action"  value="delete">
       <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
       <button type="submit" class="req-btn delete"
               onclick="return confirm('Permanently delete the request from <?= htmlspecialchars(addslashes($req['company_name'])) ?>? This cannot be undone.')">
           <i class="bi bi-trash3-fill"></i> Delete
       </button>
   </form>
   ```

---

**File 3**: `css/pages/admin-invitation-requests.css`

**Where**: After the existing `.req-btn.resend:hover` rule in the "Action buttons" section.

**Specific Changes**:

3. **Add `.req-btn.delete` rule**:
   ```css
   .req-btn.delete { background: #b91c1c; color: #fff; }
   .req-btn.delete:hover { background: #991b1b; }
   ```
   This uses a solid red background (destructive, prominent) — distinct from `.req-btn.reject`
   which uses white background with red border.

---

## Testing Strategy

### Validation Approach

The testing strategy follows a two-phase approach: first, surface counterexamples that
demonstrate the missing capability on unfixed code, then verify the fix works correctly
and that all existing actions are preserved.

---

### Exploratory Bug Condition Checking

**Goal**: Confirm that the delete action is truly absent before implementing the fix.
Surface counterexamples that demonstrate the bug on unfixed code. If anything unexpected
is found (e.g., a hidden handler), revise the root cause analysis.

**Test Plan**: Submit a POST request with `action=delete` and a valid `req_id` against the
unfixed code. Inspect the server response and database state. Also inspect the rendered HTML
of a request row to confirm no Delete button exists.

**Test Cases**:
1. **Missing button test**: Load `invitation_requests.php` for a rejected request — confirm
   no Delete button appears in the `actions-cell` (will confirm on unfixed code).
2. **Missing button for pending**: Load the page for a pending request — confirm no Delete
   button appears (will confirm on unfixed code).
3. **POST falls through**: Submit `action=delete` with a valid `req_id` via cURL or a
   browser devtools fetch — confirm the row is NOT deleted and no success flash is shown
   (will confirm on unfixed code).
4. **Invalid `req_id` edge case**: Submit `action=delete` with `req_id=0` — confirm no
   crash occurs on either unfixed or fixed code.

**Expected Counterexamples**:
- The `invitation_requests` row is not deleted when `action=delete` is POSTed.
- No `INVITATION_REQUEST_DELETED` audit log entry is created.
- Possible cause: no handler block exists; the request falls through to the page render.

---

### Fix Checking

**Goal**: Verify that for all inputs where the bug condition holds (an admin tries to delete
a request), the fixed handler produces the expected behavior.

**Pseudocode:**
```
FOR ALL req_id WHERE invitation_requests.id = req_id DO
  POST action=delete, req_id TO invitation_requests.php
  ASSERT invitation_requests row with id=req_id no longer exists
  ASSERT user_invitations rows with email=req.email no longer exist
  ASSERT audit_log entry with event='INVITATION_REQUEST_DELETED' and row_id=req_id exists
  ASSERT HTTP response redirects to invitation_requests.php (with query string preserved)
  ASSERT $_SESSION['alert_success'] contains company_name
END FOR
```

---

### Preservation Checking

**Goal**: Verify that for all inputs where the bug condition does NOT hold (any action other
than delete), the fixed code produces exactly the same behavior as the original.

**Pseudocode:**
```
FOR ALL action IN ['approve', 'resend', 'reject', 'page_view'] DO
  ASSERT fixedBehavior(action) = originalBehavior(action)
END FOR
```

**Testing Approach**: Property-based testing is recommended for preservation checking because:
- It generates many combinations of request status × action automatically.
- It catches any accidental CSS specificity conflict or PHP variable shadowing introduced
  near the new handler block.
- It provides strong guarantees that no existing row is accidentally deleted.

**Test Plan**: Observe baseline behavior on unfixed code for each existing action, then run
the same suite after the fix to verify outputs are identical.

**Test Cases**:
1. **Approve preservation**: Submit `action=approve` for a pending request on fixed code —
   confirm token is created, status updated to `approved`, email queued, audit log written.
2. **Resend preservation**: Submit `action=resend` for an approved request on fixed code —
   confirm token is regenerated, email queued, audit log written.
3. **Reject preservation**: Submit `action=reject` for a pending request on fixed code —
   confirm status updated to `rejected`, admin_notes saved, email queued, audit log written.
4. **Button rendering preservation**: Load the page after the fix — confirm `.req-btn.approve`
   and `.req-btn.resend` and `.req-btn.reject` still appear for their respective statuses.
5. **CSS no-collision preservation**: Inspect computed styles for `.req-btn.approve`,
   `.req-btn.reject`, `.req-btn.resend` after adding `.req-btn.delete` rule — confirm no
   specificity override or unintended color bleed.
6. **Mobile layout preservation**: At ≤ 639 px viewport width, confirm the actions flex row
   still lays out correctly with the additional Delete button included.

---

### Unit Tests

- Test `action=delete` with a valid pending `req_id` → row deleted, token deleted, redirect.
- Test `action=delete` with a valid approved `req_id` → row deleted, token deleted, redirect.
- Test `action=delete` with a valid rejected `req_id` → row deleted, no token exists (no
  error), redirect with success flash.
- Test `action=delete` with `req_id=0` (non-existent) → error flash set, redirect, no crash.
- Test `action=delete` where `user_invitations` has no matching email → transaction still
  succeeds (zero rows deleted from `user_invitations` is not an error).
- Test that POSTing `action=approve` still works after the new handler is inserted (handler
  order: approve → resend → reject → delete; none share a variable name).

### Property-Based Tests

- Generate random sets of `invitation_requests` rows with varying statuses; assert that after
  deleting one, all others remain untouched.
- Generate random `req_id` values (including out-of-range integers) and assert the handler
  never produces a PHP fatal error or unhandled exception.
- Assert that for any non-delete POST action, the `invitation_requests` row count does not
  decrease (i.e., delete handler is not inadvertently triggered).

### Integration Tests

- Full flow: create a request, approve it (token appears in `user_invitations`), then delete
  it — confirm both rows are gone and audit log shows approve + delete events.
- Full flow: create a request, reject it, then delete it — confirm row is gone, no orphaned
  token exists, audit log shows reject + delete events.
- UI flow: verify the Delete button appears in the rendered table for all three statuses
  (pending, approved, rejected) after the partial template change.
- UI flow: verify the mobile card layout at ≤ 639 px still renders all action buttons in a
  single flex row including Delete.
- UI flow: verify that submitting the Delete form via the `data-post-inplace` / AJAX path
  refreshes `#requestListRoot` and the deleted row is no longer visible without a full reload.
