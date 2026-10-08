# Bugfix Requirements Document

## Introduction

The Approve, Reject, and Resend Invite action buttons on `/admin/invitation_requests.php` stopped working after the page was converted to AJAX-based list rendering. Clicking any of these buttons appears to do nothing: the page reloads but no action is processed and no success/error toast is shown. No status changes are persisted to the database.

The root cause is a URL resolution mismatch introduced by the AJAX conversion. The `data-post-inplace` handler in `ajax-list.js` submits forms by posting to `form.action`, which resolves an empty `action=""` attribute to the current `location.href`. After `AjaxList` calls `history.replaceState`, `location.href` is the clean URL (e.g. `/YesParency/admin/invitation_requests`) — without the `.php` extension. The `.htaccess` only rewrites GET/HEAD requests from clean URLs back to `.php` files; POST requests to the clean URL are not rewritten. The server cannot locate the handler and returns a 301 redirect (or a non-PHP response). Because `fetch` is called with `redirect: 'manual'`, it receives an opaque response, the `.then(() => location.replace(...))` still runs and reloads the page, but PHP never processed the POST — so no action is taken and no session flash is set.

---

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN the user clicks Approve, Reject, or Resend Invite after `AjaxList` has performed at least one AJAX filter/paginate operation (causing `history.replaceState` to set `location.href` to a clean URL without `.php`) THEN the system submits the POST to the clean URL (e.g. `/admin/invitation_requests`) which is not processed by PHP, resulting in no status change and no toast notification.

1.2 WHEN `form.action` is an empty string (`action=""`) and `location.href` has been rewritten by `history.replaceState` to a clean path THEN the system resolves `form.action` to the clean URL, bypassing the PHP action handler.

1.3 WHEN a POST is sent to the clean URL by `fetch` with `redirect: 'manual'` THEN the system receives an opaque/non-2xx response but still calls `location.replace(location.href)`, giving the user the false appearance of a successful page reload while no server-side action was executed.

### Expected Behavior (Correct)

2.1 WHEN the user clicks Approve, Reject, or Resend Invite at any point — regardless of whether `AjaxList` has called `history.replaceState` — THEN the system SHALL POST to the correct PHP endpoint (`invitation_requests.php`), process the action server-side, and reload the page showing the appropriate success or error toast.

2.2 WHEN `form.action` is an empty string and `location.href` has been changed by `replaceState` to a clean URL THEN the system SHALL resolve the POST target to the canonical `.php` file URL, not the replaceState'd clean URL.

2.3 WHEN the `data-post-inplace` handler sends the POST fetch request THEN the system SHALL receive a successful (opaque redirect or 2xx) response confirming the PHP handler ran, then reload the page to display the flash message.

### Unchanged Behavior (Regression Prevention)

3.1 WHEN the user accesses the page without any prior AJAX interaction (i.e. `location.href` is still the original `.php` URL) THEN the system SHALL CONTINUE TO process Approve, Reject, and Resend Invite actions correctly via the `data-post-inplace` handler.

3.2 WHEN the user interacts with the filter or search form (tabs, text search, pagination) THEN the system SHALL CONTINUE TO load results via AJAX without full page reloads, and the URL shall continue to be updated via `history.replaceState`.

3.3 WHEN the user submits the Reject modal form (`#rejectForm`) THEN the system SHALL CONTINUE TO process the rejection action and show a success toast, since the modal is outside `#requestListRoot` and is not affected by innerHTML swaps.

3.4 WHEN the confirm dialog on an Approve or Resend button is cancelled by the user THEN the system SHALL CONTINUE TO prevent form submission and take no action.

3.5 WHEN a POST action (Approve, Reject, Resend) is processed successfully THEN the system SHALL CONTINUE TO preserve the current filter and pagination state in the reloaded URL (i.e. the page reloads to the same filtered view, not the unfiltered default).
