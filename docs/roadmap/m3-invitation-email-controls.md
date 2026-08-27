# M3 — Invitation email controls (generic core freeze)

**Status:** Frozen at `upr-invitation-email-controls-freeze`. Implementation on branch `feat/m3-invitation-email-controls` (target minor `v0.3.0`; release tag deferred).  
**Freeze branch:** `docs/m3-invitation-email-controls-freeze`  
**Freeze tag:** `upr-invitation-email-controls-freeze` (annotated; points at the documentation merge commit).  
**Baseline:** `main` @ UPR `v0.2.2`; M2 invitations frozen at [`M2-invitations.md`](../milestones/M2-invitations.md); productization boundary [ADR-0002](../decisions/ADR-0002-productization-boundary.md).  
**Target implementation release:** minor **`v0.3.0`** (deliberate fail-closed behaviour change). Release tag, GitHub Release, and ZIP are **not** authorised by this freeze.

**Production / DEV rollout:** Not authorised by this document. No site activation, bind-mount deploy, real email send, or merchant setting mutation is part of freeze or implementation PRs.

---

## 1. Scope summary

Ship in **generic UPR core only**:

1. Merchant master control: **Enable review invitation emails** (default disabled).
2. Merchant emergency control: **Emergency pause invitations** (default false; revokes outstanding invitation access).
3. Smallest stable public extension contract for **host invitation send authorisation**.
4. Fail-closed enforcement across scheduling, send, sent-state/success-audit, reconciliation, and reminders.
5. Audit / observability / admin settings / CLI status surfaces consistent with UPR conventions.
6. Focused automated coverage (unit + invitation-lifecycle integration) per §10.

### Out of scope (host / later)

- Any host-, site-, product-, or provider-named policy, allowlist, delivery logic, support logic, or deployment code.
- Production or DEV host integration, bind mounts, activation, or pilot rollout.
- A second invitation pipeline; changes to M2 table schema beyond optional non-breaking audit/status usage already supported.
- GitHub Release / ZIP / `v0.3.0` tag until a separate post-implementation acceptance step.

### Constraints preserved

- Portable sellable core ([ADR-0002](../decisions/ADR-0002-productization-boundary.md)).
- No host/theme/fulfillment/support-desk/mail-provider/site/product names in UPR code, docs, APIs, fixtures, or tests.
- Do not gate mail by wrapping transport **after** UPR has decided to mark a send successful.
- Native comment-route / M2 guest-auth behaviour from M1/M2/B1 must not regress.

---

## 2. Exact settings

Capability for all admin controls: **`manage_woocommerce`**.  
UI: generic UPR admin settings page under WooCommerce (Settings API + capability/nonce via `options.php` / `settings_fields`). UPR currently has delay/TTL options without an admin page; this work **adds** the settings page and registers the new booleans (existing delay/TTL keys may be surfaced on the same page for discoverability, without changing their defaults).

| UI label | Option key | Type | Default | Notes |
|----------|------------|------|---------|-------|
| Enable review invitation emails | `upr_invitation_emails_enabled` | bool (`yes`/`no` or `1`/`0` via WP options) | **false / absent** | Fresh installs and upgrades without an explicit stored value are **disabled**. |
| Emergency pause invitations | `upr_invitation_emergency_pause` | bool | **false / absent** | Emergency stop; UI must warn that pause revokes outstanding invitation access. |

### Accessors (normative names)

```text
Options::invitation_emails_enabled(): bool   // false unless explicitly enabled
Options::invitation_emergency_pause(): bool  // true only when explicitly paused
```

### Migration / default decision (locked)

| Situation | Behaviour |
|-----------|-----------|
| Fresh install | Both controls absent → emails **disabled**, pause **off**. |
| Upgrade from ≤ `v0.2.2` with no stored keys | Same fail-closed defaults (**disabled**, pause **off**). Deliberate behaviour change: sites that previously sent whenever eligible must explicitly enable. |
| Explicit stored `true` / `false` | Honoured. |
| Re-enable after disable, or unpause after pause | Does **not** retroactively bulk-send work denied/paused while controls blocked it. Enabling and unpausing each refresh `upr_invitation_scheduling_boundary_at` to “now”; only source events at/after that boundary may schedule/send via normal paths (including reconciliation). Explicit host-pilot invite of a pre-boundary order requires a deliberate operator action that supplies a post-boundary source timestamp — never an accidental reconcile backfill. |

No schema migration is required for these options. No activation-time email or invitation storm.

---

## 3. Authority / precedence matrix

Evaluate in this order at every gate (§5). First matching deny wins and is final.

| Priority | Control | Decision vocabulary | Host filter may override? |
|----------|---------|---------------------|---------------------------|
| 1 | Emergency pause = on | `paused` | **No** — host cannot convert to `allow` |
| 2 | Master enable = off | `email_disabled` | **No** |
| 3 | Core provisional allow | `allow` | Host may further restrict only |
| 4 | Host authorisation filter | `allow` or `not_authorised` | Host **must not** invent `paused` / `email_disabled`; if it does, core maps unknown deny-like values to `not_authorised` and still never upgrades a core deny |

**Rule:** Core controls are authoritative. The host filter is applied **only** when core would otherwise `allow`. Any filtered result that attempts to upgrade `email_disabled` or `paused` to `allow` is discarded; core decision stands.

---

## 4. Generic authorisation contract

### Filter

```php
/**
 * Invitation send / schedule authorisation.
 *
 * Core supplies the provisional decision after applying master enable + emergency pause.
 * Hosts may further restrict an `allow` decision to `not_authorised`.
 * Hosts must never upgrade `email_disabled` or `paused` to `allow` (core enforces).
 *
 * @param array{
 *   decision: 'allow'|'email_disabled'|'paused'|'not_authorised',
 *   reason_code?: string
 * } $decision
 * @param array{
 *   order_id: int,
 *   order_item_id: int,
 *   product_id: int,
 *   operation: 'schedule'|'initial_send'|'reminder_send'
 * } $context
 * @return array{
 *   decision: 'allow'|'email_disabled'|'paused'|'not_authorised',
 *   reason_code?: string
 * }
 */
apply_filters( 'upr_invitation_send_authorisation', $decision, $context );
```

### Decision vocabulary (locked)

| Decision | Meaning |
|----------|---------|
| `allow` | Authorised to proceed with the named `operation` |
| `email_disabled` | Master invitation-email control is off |
| `paused` | Emergency pause is on |
| `not_authorised` | Host (or future core) policy denied an otherwise allowed operation |

### Helper (recommended public seam)

```text
InvitationAuthorisation::evaluate( array $context ): array{decision, reason_code?}
InvitationAuthorisation::is_allowed( array $context ): bool
```

`evaluate()`:

1. Build safe `$context` (generic IDs only; no email, no raw tokens).
2. If pause → `{ decision: 'paused' }` (skip host filter for upgrade attempts; optionally still call filter for observability but **ignore** upgrades).
3. Else if master disabled → `{ decision: 'email_disabled' }`.
4. Else provisional `{ decision: 'allow' }`, apply filter, clamp: if provisional was not `allow`, keep provisional; if provisional was `allow` and filter returns non-`allow`, coerce to `not_authorised` unless filter already returned a known deny vocabulary value of `not_authorised` (preferred) or `allow`.

Locked simplification for implementers: **do not call the host filter** when core has already decided `paused` or `email_disabled`. Call the host filter only on provisional `allow`.

---

## 5. Enforcement points and race behaviour

Authorisation is evaluated:

1. **Before invite/item scheduling** for every eligible source: delivery-confirmed, completed-status fallback, reconciliation-driven schedule, and any path that would enqueue `upr_schedule_order_items` / upsert+schedule send work.
2. **Immediately before every scheduled email send** (`upr_send_initial_bundle` / `BundleSender`, `upr_send_reminder_item` / `ReminderSender`) — after claim acquisition attempts must still no-op without transport if authorisation fails (prefer check **before** claim when practical; if claim already taken, release/fail claim without `mark_*_sent` and without success audit).
3. **Before any sent-state / success-audit transition** (`mark_initial_sent`, `mark_reminder_sent`, `email.sent`).

| Path | When denied (`email_disabled` / `paused` / `not_authorised`) |
|------|---------------------------------------------------------------|
| Delivery confirmed / completed fallback | Do not enqueue schedule work that creates new sendable invitation email work; safe no-op |
| `InvitationScheduler::schedule_order` | Do not upsert new email-bound schedule progression toward send; do not enqueue initial bundle |
| Reconciliation | Do not create broad denied work; do not schedule sends; do not per-item authorisation audit storm (§7) |
| Initial / reminder send handler | No mail transport invocation; no `*_sent_at` / success audit; claim released or failed without success |
| Reminder scheduling after initial | Only when initial truly sent under an allowed path |

**Races:** Pending Action Scheduler actions that fire after controls flip must **always no-op** safely (re-check authorisation in handlers). Where possible on emergency pause, cancel pending UPR invitation actions in group `upr` (`upr_send_initial_bundle`, `upr_send_reminder_item`, and avoid leaving new `upr_schedule_order_items` that would schedule sends). Cancellation is best-effort; handler no-op is mandatory.

**Do not** implement denial by dropping mail inside a transport wrapper after UPR would mark sent.

---

## 6. Normal disable vs emergency pause

### A. Master enable off (`email_disabled`)

- No new invitation email scheduling.
- Scheduled handlers must not call mail transport.
- No initial/reminder sent-state or successful-send audit.
- Existing **approved** (and other completed) reviews unchanged.
- Already-issued invite tokens / form sessions **remain valid**.
- Re-enabling refreshes `upr_invitation_scheduling_boundary_at` to the enable instant. Delivery/completion timestamps recorded while disabled remain stored, but reconcile/schedule/send must not create invitation email work for source events before that boundary.

### B. Emergency pause on (`paused`) — takes precedence

- Same send/schedule/sent-state blocks as disable.
- Outstanding invite tokens and form sessions are **revoked/invalidated** (reuse `TokenRepository::revoke_*` patterns; site-wide outstanding non-redeemed invite + session tokens).
- Pending UPR invitation actions cancelled where possible; handlers always no-op if raced.
- Existing completed reviews untouched.
- Unpausing refreshes the scheduling boundary to “now” and does not retroactively send previously denied/paused work (including reminders whose `initial_sent_at` is before the new boundary).
- UI warning: emergency stop that **revokes outstanding invitation access**.

### Scheduling boundary (locked)

| Option | Behaviour |
|--------|-----------|
| `upr_invitation_scheduling_boundary_at` | Unix timestamp persisted on **enable** (disabled→enabled) and **unpause** (paused→unpaused). Absent/0 is fail-closed. |
| Source for schedule / initial send | Delivery or completed-fallback event unix (`source_event_at`) |
| Source for reminder send | `initial_sent_at` unix |
| Decision when source &lt; boundary | `not_authorised` + `reason_code=outside_scheduling_boundary` (host filter not invoked) |

Host pilot allowlists may further restrict post-boundary work. Inviting a pre-boundary cohort must be an **explicit** operator path that supplies a post-boundary source timestamp — never nightly reconciliation alone.

---

## 7. Audit and privacy

### Events (normative)

| Event | When | Payload (no PII secrets) |
|-------|------|---------------------------|
| `invite.emergency_pause` | Pause enabled | `actor_id`, `reason` (sanitized short string), `timestamp` (unix or GMT) |
| `invite.emergency_unpause` | Pause cleared | `actor_id`, `reason`, `timestamp` |
| `invite.authorisation_denied` | Schedule/send gate denied outside reconcile bulk | `decision`, `operation`, optional `reason_code` |
| Existing `email.sent` / `email.failed` / `invite.scheduled` | Unchanged semantics | Must not be written for successful send when authorisation denied |

Actor type for pause toggles: prefer `hook` or extend usage to record `actor_id` in payload with `actor_type` `system`/`cli`/`hook` as fits existing `varchar(16)` column; user ID lives in **payload**, not as a new column.

### Privacy

- Never log raw invite/session tokens, billing emails, or review bodies in these events.
- Context IDs only: `order_id`, `order_item_id`, `product_id`, `operation`, `decision`, `reason_code`.

### Reconciliation dedupe (locked)

- Reconciliation **must not** write per-item `invite.authorisation_denied` rows for every scanned order.
- Include aggregate counters on `reconcile.completed` (e.g. `authorisation_denied_skipped`) when useful.
- Per-item deny audits are reserved for live schedule/send gate paths, and should be idempotent enough to avoid storms (at most one deny audit per `(order_item_id, operation, decision)` per control epoch is recommended; exact storage may use invite-row meta or last-audit check — implementation choice, behaviour locked).

---

## 8. Admin / CLI / status

- Settings page: WooCommerce submenu, `manage_woocommerce`, Settings API sanitize callbacks.
- Emergency pause field: clear warning copy about revocation.
- Optional CLI/status: if extending `wp upr …`, expose enable/pause flags and last pause actor/reason from options/audit without printing PII. Not required to invent a large new CLI surface if a small status addition fits existing `Commands.php` style.

Pause reason storage: option `upr_invitation_emergency_pause_meta` (array: `reason`, `actor_id`, `changed_at`) updated when pause toggles — keeps CLI/status available without scanning audit.

---

## 9. Host-policy boundary

| Concern | Owner |
|---------|-------|
| Master enable, emergency pause, enforcement, contract definition, audits | **This repository** |
| Temporary pilot allowlists, merchant-specific send authorisation, host UI/CLI for that policy | **External host companion** only |
| Delivery / support / mail transport adapters | External (existing contracts) |

Hosts implement `upr_invitation_send_authorisation` only. They must **not** duplicate master enable, emergency pause, native submission guards, or the invitation state machine.

---

## 10. Test matrix (required automated coverage)

| # | Requirement |
|---|-------------|
| T1 | Fresh/default installation is fail-closed for invitation email (`invitation_emails_enabled` false) |
| T2 | Admin setting registration and `manage_woocommerce` capability boundary |
| T3 | Disabled master control blocks: delivery scheduling, completed fallback, reconciliation send/schedule progression, initial send, reminder send, sent-state and success audit |
| T4 | No host filter result can override `email_disabled` or `paused` to allow |
| T5 | Host-style `not_authorised` blocks scheduling/send without mail transport or sent-state |
| T6 | Emergency pause: blocks scheduling and raced action execution; invalidates outstanding token/session access; preserves completed reviews; prevents later retro-send after unpause (including reminder); records actor/reason audit; pending UPR actions cancel or safely no-op |
| T6b | Delivery/completion while disabled → enable → reconcile → no invite/action/mail/sent-state |
| T6c | Delivery/completion while paused → unpause → reconcile → no invite/action/mail/sent-state |
| T6d | Genuinely new eligible source event after enable/unpause → normal allowed schedule/send |
| T7 | Existing allowed flow still sends through test/logging transport and reaches correct pending-moderation review lifecycle |
| T8 | Reconciliation remains idempotent; no broad denied work or audit storms |
| T9 | No native comment-route / M2 guest-auth regression |

Validation tiers: lint/policy → targeted unit → targeted invitation lifecycle/security integration → full PR CI as final gate.

---

## 11. Rollback and non-goals

### Rollback

- Revert to `v0.2.2` behaviour by releasing/deploying prior version, or disable pause and leave master disabled (fail-closed).
- No destructive schema migration tied to this feature.
- Tokens revoked during pause are not automatically restored on unpause (customers need a new invite send when controls allow — by design).

### Non-goals

- Production/DEV rollout, real email, GitHub Release/ZIP, or `v0.3.0` tag as part of freeze/implementation PR merge gates.
- Host pilot allowlist inside UPR.
- Changing global WordPress/WooCommerce review options.
- Transport-level “drop after mark sent”.

---

## 12. Implementation work package (post-freeze)

Branch: `feat/m3-invitation-email-controls`  
Expected touch areas: `Options`, new admin settings, `InvitationAuthorisation`, `InvitationScheduler`, `Jobs`, `BundleSender`, `ReminderSender`, `ReconciliationService`, token revoke-on-pause, `AuditLogger` usage, CLI/status, version/`CHANGELOG`/`adapters.md`, tests, CI version pin to `0.3.0`.

Open PR; **do not merge** until review. **Do not** tag `v0.3.0` until separate acceptance.

---

## 13. Explicit non-authorisation

**No production rollout is authorised by this freeze.**  
**No DEV deployment, real email send, GitHub Release, ZIP packaging, or release version tag is authorised in the documentation or implementation PR phases described here.**

---

## Related

- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)
- [`../integration/adapters.md`](../integration/adapters.md)
- [`../decisions/ADR-0002-productization-boundary.md`](../decisions/ADR-0002-productization-boundary.md)
- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md)
