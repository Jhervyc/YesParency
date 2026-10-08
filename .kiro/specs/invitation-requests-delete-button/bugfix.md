# Bugfix Requirements Document

## Introduction

Admins have no way to permanently remove an invitation request record from the
`invitation_requests` table through the UI. The actions cell in
`admin/invitation_requests.php` only offers status-specific actions (Approve,
Reject, Resend Invite), leaving rejected rows and stale approved rows with no
cleanup path. Additionally, when an approved request is deleted, the outstanding
`user_invitations` token for that email must be invalidated to prevent the invite
link from remaining usable.

A secondary behavior — auto-deleting the invitation request on successful account
creation via an invite link — is already implemented in `accept_invitation.php`
and requires no change.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN an admin views any invitation request row (pending, approved, or rejected) THEN the system does not display a Delete button in the actions cell, making permanent deletion impossible from the UI.

1.2 WHEN an admin wants to remove a stale approved request whose invitation link has expired THEN the system provides no mechanism to delete the record or invalidate the outstanding `user_invitations` token.

1.3 WHEN a POST request is submitted with `action=delete` and a valid `req_id` to `invitation_requests.php` THEN the system has no handler for this action and silently ignores it.

### Expected Behavior (Correct)

2.1 WHEN an admin clicks Delete on any invitation request row and confirms the dialog THEN the system SHALL permanently delete the row from `invitation_requests`, delete any matching `user_invitations` token for that email, record an audit log entry, and redirect back with a success toast.

2.2 WHEN a POST request is submitted with `action=delete` and a valid `req_id` THEN the system SHALL delete the corresponding `invitation_requests` row and any associated `user_invitations` rows for that email, regardless of the request's current status (pending, approved, or rejected).

2.3 WHEN a POST request is submitted with `action=delete` and an invalid or missing `req_id` THEN the system SHALL redirect back with an error toast and make no database changes.

2.4 WHEN a Delete action is processed successfully THEN the system SHALL call `audit_log()` with event `INVITATION_REQUEST_DELETED`, table `invitation_requests`, the row's ID, a descriptive message including company name and email, the old row state, and an empty new state.

### Unchanged Behavior (Regression Prevention)

3.1 WHEN an admin approves a pending invitation request THEN the system SHALL CONTINUE TO create a `user_invitations` token, update the request status to approved, send the invitation email, and log the `INVITATION_APPROVED` audit event.

3.2 WHEN an admin rejects a pending invitation request THEN the system SHALL CONTINUE TO update the request status to rejected, store admin notes, send the rejection email, and log the `INVITATION_REJECTED` audit event.

3.3 WHEN an admin resends an invite for an approved request THEN the system SHALL CONTINUE TO regenerate the token, extend the expiry, resend the email, and log the `INVITATION_RESENT` audit event.

3.4 WHEN a bidder successfully creates an account via an invite link THEN the system SHALL CONTINUE TO delete the `user_invitations` token and the corresponding `invitation_requests` row (where `status = 'approved'`) as implemented in `accept_invitation.php`.

3.5 WHEN an admin views the invitation requests list with any active search or filter THEN the system SHALL CONTINUE TO return the correct filtered and paginated results after a delete action redirects back.

---

## Bug Condition Pseudocode

**Bug Condition Function** — identifies the missing capability:

```pascal
FUNCTION isBugCondition(X)
  INPUT: X of type AdminUIAction
  OUTPUT: boolean

  // Bug is present when the admin tries to delete any invitation request
  // and no delete button or POST handler exists
  RETURN X.action = 'delete' AND X.target = 'invitation_requests'
END FUNCTION
```

**Property: Fix Checking**

```pascal
FOR ALL X WHERE isBugCondition(X) DO
  result ← handlePostAction'(X)
  ASSERT row_deleted_from_invitation_requests(result)
    AND token_invalidated_for_email(result)
    AND audit_entry_created(result)
    AND success_toast_shown(result)
END FOR
```

**Property: Preservation Checking**

```pascal
FOR ALL X WHERE NOT isBugCondition(X) DO
  ASSERT handlePostAction(X) = handlePostAction'(X)
END FOR
```
