---
version: alpha
name: ProjectSend SFTP transfers
description: Staff backup transfers and separate destination administration.
colors:
  primary: "#4c2ab6"
  background: "#ffffff"
  foreground: "#171717"
  danger: "#b42318"
  success: "#166534"
typography:
  sans:
    fontFamily: "ui-sans-serif, system-ui, sans-serif"
  mono:
    fontFamily: "ui-monospace, monospace"
rounded:
  DEFAULT: "0.5rem"
spacing:
  section-gap: "1.25rem"
  page-max: "76rem"
components:
  button: {}
  field: {}
  panel: {}
  dialog: {}
---

# SFTP module design

## Product context

Staff choose permitted backups and approved destinations, then track their own
transfers. Administrators configure destinations separately. PLAN.md,
TransferSource, and the `sftp.transfer` / `sftp.manage-destinations` gates own
scope and access. UX-CONTRACT.md records the workflow rules. This is an English-first,
in-house workflow; no Japan-market rules or content are involved.
All owned labels use Laravel translation lookup with English fallback.

## Visual direction and token ownership

Follow ProjectSend's settings pages: purple actions, neutral panels, restrained
borders, half-rem corners, and a left-hand section explanation beside fields.
Technical connection values use monospace. Existing `resources/css/app.css` in
the host establishes the brand; the package's `resources/views/styles.blade.php`
is the runtime adapter for this independently rendered Blade surface. The
frontmatter records its baseline values; it does not generate CSS. Preserve
these values together when intentionally changing package tokens.

Use system fonts instead of downloading fonts or depending on the host's asset
manifest. This keeps installation independent of the host frontend build.
No marketing artwork, animations, third-party requests, or new brand treatment.
Read the host appearance preference from localStorage before first paint, with
system/cookie fallback when storage is unavailable. Support forced colours.
The module writes no appearance settings, drafts, credentials, or backup bytes
to browser storage.

## Layout and shared owners

`layout.blade.php` owns header, same-tab navigation, notifications, and the
unsaved-change dialog. Transfer pages show only My transfers and Send backup;
Destinations navigation appears only within destination administration, including
for administrators. The separate authorised ProjectSend sidebar entry owns
entry to destination administration. `field.blade.php` owns labels, hints, errors,
and secret presence behaviour. `styles.blade.php` owns tokens, focus, scrollbar styling,
responsive form stacking, and horizontally scrollable tables. `field.blade.php`
also owns the native destination select trigger. Browser/OS ownership of its
popup, locale, geometry, scrolling, and keyboard behaviour is intentional; no
authored popup styling is required. There are no date controls or bulk actions. Deletion uses a dedicated
confirmation page naming the destination, with initial focus on Keep destination.
Native file selection is deliberate; the operating system owns its dialog.

Both destination administration and transfer selection use ordinary web forms
with CSRF and server-side
validation. No browser storage retains drafts or credentials. Private keys are
selected as files and never displayed. Passphrases are masked by default with
a labelled show/hide button. Successful writes return to the list with status
feedback. Errors preserve non-secret input and require credential re-entry.

## Behaviour and verification

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
| --- | --- | --- | --- | --- |
| Form | resources/views/field.blade.php | SaveDestination / TransferController validation | destination create/edit, transfer review | tests/http.php, tests/workflow.php and browser fixtures |
| Select/Listbox | native | Enabled destination IDs/names | one destination, empty placeholder | tests/workflow-browser.mjs, OS popup ownership |
| Table | resources/views/pagination.blade.php and styles.blade.php | TransferSource / requester history scope | search, paging, one Choose link per row | tests/workflow.php, tests/workflow-browser.mjs |
| Navigation | resources/views/layout.blade.php | UX-CONTRACT.md access rules | transfer links, separate authorised destination administration | tests/workflow.php, tests/workflow-browser.mjs |
| Progress | resources/views/scripts.blade.php | TransferController status whitelist | active, terminal, paused/session change | tests/workflow-browser.mjs |
| Scrollbar | resources/views/styles.blade.php | Runtime CSS tokens | document, table region | narrow browser viewport |
| Toast | resources/views/layout.blade.php | Server session status | success, inline validation error | tests/http.php |
| CRUD | src/DestinationController.php | PLAN.md and SaveDestination | list, create, edit, disable, confirmed delete | tests/http.php |

`SaveDestination` owns validation and credential handling. Configuration pages
require the administration gate, including direct URLs. The Windows ACL policy
uses the destination form's native checkbox pattern, associated help/error text,
and existing section layout. It defaults off and remains visible on create/edit
without new JavaScript, tokens, or a separate settings flow. Its warning and
server-side validation share ownership with the other destination fields.
The Inertia sidebar receives a 409 location handoff to the package's full-page response.

Form edits get revision tokens; stale edits fail without overwriting a newer
configuration. Submissions show a stable-width busy button and block repeats.
In-app cancellation uses a native accessible dialog; browser unload protection
is limited to dirty forms. No custom modal or toast framework is introduced.

The document scrolls vertically; wide connection tables scroll horizontally
inside their own labelled region. At 700px, forms stack. Labels and error text
stay associated with fields; keyboard focus remains visible. Reduced motion
requires no adaptation because the package has no animations.

Run the HTTP flow checks, host integration checks, and browser fixture described
in README.md. These prove behaviour; the premium audit is static evidence only.

My transfers shows six calendar months of requests plus older unresolved work,
with the filter owned by TransferController and applied before pagination.
Operator cancellation has no staff UI control; its successful removal makes the
record absent from history and direct detail/status access. UX-CONTRACT.md owns
those behavior rules; README.md owns the operator commands.

Staff search uses explicit GET submission, with IME composition protection. Query
and page are URL state; Clear resets the page and returns focus to the search
field. Polling updates only status/progress, preserves focus, and stops on errors,
session changes or terminal states. An independent manual Refresh remains usable
without JavaScript. See UX-CONTRACT.md for the bounded polling policy.

`TransferController::summary` owns server duration calculation and formatting;
`transfers/duration.blade.php` renders it in Delivery status and finished history
rows. Shared scripts own the detail clock and its existing poll lifecycle. The
shared `.transfer-duration` style uses tabular digits and reserves one line in
the detail panel to keep layout stable when execution begins. Duration remains
secondary help text, outside the live status announcement. No theme tokens change.
