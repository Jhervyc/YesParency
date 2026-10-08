# Implementation Plan

- [ ] 1. Add delete handler to `admin/invitation_requests.php`
  - Insert the `action=delete` POST handler block before the `// ── Stats ──` section
  - Fetch the request row by `req_id`; redirect with error flash if not found
  - Begin a transaction: DELETE from `user_invitations` WHERE email matches, then DELETE from `invitation_requests` WHERE id matches
  - Rollback and redirect with error flash on any exception
  - Commit transaction, call `audit_log()` with event `INVITATION_REQUEST_DELETED`, set success flash, redirect (preserving query string)
  - _Bug_Condition: isBugCondition(context) where serverHandlesAction(context, 'delete') = FALSE_
  - _Expected_Behavior: atomic delete of invitation_requests row + user_invitations rows, INVITATION_REQUEST_DELETED audit log entry, alert_success flash, redirect with query string preserved_
  - _Preservation: handler is inserted after existing reject block; no shared variable names with approve/resend/reject handlers_
  - _Requirements: 2.1, 2.2, 2.3_

- [ ] 2. Add Delete button to `admin/partials/invitation-requests-list.php`
  - Remove `<span class="no-actions-text">No actions</span>` from the `else` (rejected) branch inside `<td class="actions-cell">`
  - After the closing `<?php endif; ?>` of the status conditional, add the Delete `<form>` for ALL statuses
  - Form uses `method="POST"`, `action="invitation_requests.php"`, `class="inline-form"`, `data-post-inplace` (matches existing inline-form pattern)
  - Button uses `class="req-btn delete"` with `onclick` confirm dialog and `htmlspecialchars(addslashes($req['company_name']))` for safe output
  - _Bug_Condition: isBugCondition(context) where deleteButtonExistsInActionsCell(context) = FALSE_
  - _Expected_Behavior: Delete button rendered for pending, approved, and rejected statuses_
  - _Preservation: existing approve/resend/reject conditional buttons are untouched; only the rejected "No actions" span is removed_
  - _Requirements: 2.1, 2.3_

- [ ] 3. Add `.req-btn.delete` CSS rule to `css/pages/admin-invitation-requests.css`
  - Insert after the existing `.req-btn.resend:hover` rule in the "Action buttons" section
  - Add `.req-btn.delete { background: #b91c1c; color: #fff; }` (solid red — distinct from reject's white+red-border style)
  - Add `.req-btn.delete:hover { background: #991b1b; }`
  - _Preservation: existing `.req-btn.approve`, `.req-btn.reject`, `.req-btn.resend` rules are unchanged; no specificity collision introduced_
  - _Requirements: 2.3, 3.4_

- [ ] 4. Checkpoint — verify all three changes work end-to-end
  - Load `admin/invitation_requests.php` and confirm a Delete button appears for rows of every status (pending, approved, rejected)
  - Click Delete on a rejected request — confirm the row disappears from the list (AJAX refresh via `data-post-inplace`) and a success flash appears
  - Click Delete on a pending request that has a linked `user_invitations` token — confirm both the `invitation_requests` row and the `user_invitations` row are gone
  - Attempt a delete with an invalid/non-existent `req_id` (e.g., edit the hidden field in devtools) — confirm an error flash is shown and no crash occurs
  - Confirm approve, resend, and reject still work as before (no regressions)
  - Confirm mobile layout (≤ 639 px) still renders all action buttons in a single flex row
  - Ensure all tests pass, ask the user if questions arise.
