# M10 O9′ — Encrypted OpenAI credential in Controls (authoritative amendment)

**Status:** Frozen M10 **O9 supersession** (product and implementation specification for one secret). Authorises **documentation freeze** and **subsequent implementation on this freeze**. Does **not** authorise Calibration GO, production enablement, DEV/production WordPress access, entering a real OpenAI key, external-AI enablement, real provider calls, test-connection against a live key, email, host-specific code, GitHub Release, ZIP, plugin SemVer / version tag, or movement of `v0.8.0`.  
**Baseline:** Universal Product Reviews `main` @ **`4ac164ca242106b29163a0f31f85f760789e7b09`**. Runtime remains **`0.8.0`**.  
**Freeze tag:** `m10-o9-encrypted-openai-credential-freeze` (annotated; peels to the merge commit of this document).

**Related:** [`m10-external-ai-advisory-assessments.md`](m10-external-ai-advisory-assessments.md), [`m10-external-ai-advisory-assessments-closure.md`](m10-external-ai-advisory-assessments-closure.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).

**Supersedes:** M10 freeze **O9** (host-only; no option/DB storage) and the host-only / “never store API keys in options” sentences in ADR-0004 §4 and `ARCHITECTURE.md` AI secrets bullets. Historical M10 freeze text remains intact except for an explicit superseded-by pointer on O9.

**Does not supersede:** M10 O1–O8, O10–O20; M11–M13; SupportExport `upr-support-export/v1`; C19; defaults (external **off**, provider **local**); live-enablement GO.

**Does not amend M14:** M14 implementation has **merged**. M14 **closure is a separate pending step**. This amendment must not describe M14 as closed, must not link to or name a M14 closure document, and must not edit the M14 freeze or any M14 runtime.

Generic core only: no host-, brand-, theme-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. No generic secret-storage framework. No multi-provider credential abstraction. **One secret only:** the OpenAI API key.

---

## 1. Problem

M10 O9 required host-only credentials (`UPR_OPENAI_API_KEY` constant then environment) and forbade option/DB storage. Operators without host-file access cannot configure OpenAI from UPR Controls. This amendment reverses **only** that storage prohibition, under an authenticated-encryption and fail-closed UI/status model equivalent in posture to Support Chat’s vault — without sharing code or adding a platform vault.

---

## 2. Locked decisions (O9′)

| ID | Locked value |
|----|----------------|
| **O9′.1** | Operator with `manage_woocommerce` may **enter, replace, and explicitly clear** one OpenAI API key from UPR Controls. |
| **O9′.2** | Store **only** versioned AES-256-GCM ciphertext in `wp_options` key `upr_openai_api_key_ciphertext`, created and kept with **`autoload=false`**. Never plaintext at rest. Never Settings API / REST registration. Implementation **must prove** first save (option previously absent) writes `autoload` as non-autoload (`no` / `off`) and that `wp_load_alloptions()` does not contain the option; a subsequent replace must not flip autoload on. |
| **O9′.3** | Resolution: (1) nonempty PHP constant `UPR_OPENAI_API_KEY`; (2) nonempty env `UPR_OPENAI_API_KEY`; (3) decrypt stored ciphertext. Constant/env **always win**; saving a DB key while override is active is allowed and unused until override is removed. |
| **O9′.4** | Surfaces may show **missing \| configured** plus **source** `constant` \| `environment` \| `stored` \| `missing` only. Never plaintext, ciphertext, prefixes, suffixes, fingerprints, or key length. When override is active, UI **must** state that it overrides any stored credential and must not reveal either value. |
| **O9′.5** | Cryptography and stored form: AES-256-GCM as specified in §4. AAD is `"upr.openai.api_key" \|\| key_source` (18-byte purpose string plus the payload source byte). Key material = WordPress salts/keys only. **No** `UPR_CREDENTIAL_KEY` constant. |
| **O9′.6** | Decrypt failure / salt rotation: AVAILABLE / INVALIDATED / UNAVAILABLE; **never auto-delete or rewrite** ciphertext on a read/decrypt path. OpenAI path fail-closed as existing `credential_missing` (no new failure-code taxonomy). Explicit **clear** is optional and removes the option without exposing it. |
| **O9′.7** | Mutations via dedicated `admin-post` (`upr_openai_credential`), not the Settings API save of the Controls form. Require `manage_woocommerce`, nonce, and **server-side confirmation**. Save and clear are mutually exclusive; both in one request → no write. Empty save → no-op (keep stored). A confirmed **save/replace atomically overwrites** any existing stored envelope — including INVALIDATED / undecryptable ciphertext — with one `update_option`. **Clear is not a prerequisite for replace.** |
| **O9′.8** | No generic vault, no provider abstraction, no extra secrets, no REST, no WP-CLI get/set of the key, no SupportExport field, no schema/`DB_VERSION` bump. |
| **O9′.9** | Uninstall **deletes** this option (secret must not survive uninstall even though UPR otherwise retains tables). |
| **O9′.10** | External AI remains default **disabled**, provider default **local**. This freeze does not authorise real API calls, test-connection against a live key, or enablement. |
| **O9′.11** | Submitted key handling: the **narrow input contract** in §5. Never `sanitize_text_field` / `sanitize_textarea_field` / `sanitize_key` / `wp_strip_all_tags` on the secret. Unset the submitted key from `$_POST` / `$_REQUEST` before notices or redirect on every save, reject, no-op, or clear path. |

---

## 3. Controls UI (locked)

Dedicated form on Controls (password input **always empty**, `autocomplete="new-password"`):

- Status strip: **configured (source)** or **missing**.
- If constant or env is active: notice that host override is in use and any stored credential is unused.
- If stored ciphertext exists but is undecryptable and no usable host secret: status **missing**, plus operational copy that a stored credential cannot be decrypted (e.g. after salt rotation). Operator **may save/replace immediately** (atomic overwrite) or optionally **clear**. Still no values.
- Confirm checkbox to save/replace; separate confirm to clear.
- Update existing “Requires host credential `UPR_OPENAI_API_KEY`” help text to mention encrypted stored credential as third source.

Redirect / notice codes are allowlisted only: `saved` \| `replaced` \| `cleared` \| `rejected` \| `forbidden`. Never put the key, envelope, or key length in query args.

---

## 4. Ciphertext envelope (normative)

Implementation must match this section byte-for-byte. Support Chat’s `usc1:` + standard base64 is the **crypto posture** (AES-256-GCM, AAD, source byte, fail-closed); UPR’s stored string is **this** format, not a copy of `usc1:`.

**Stored option value:** a UTF-8 / ASCII string:

```text
upr1: || BASE64URL_NOPAD( payload )
```

- Prefix is exactly the five ASCII characters `upr1:` (lowercase, version 1). No other version is accepted by this freeze.
- There is no whitespace anywhere in the stored string.

**Payload binary layout** (concatenation, no length prefixes, no separators):

| Offset | Length | Field |
|--------|--------|--------|
| 0 | 1 | `key_source` uint8 |
| 1 | 12 | nonce (AES-256-GCM IV) |
| 13 | 16 | GCM authentication tag |
| 29 | N | ciphertext (`OPENSSL_RAW_DATA`; N equals plaintext length) |

- `key_source` **1** = SHA-256 binary digest of `AUTH_KEY \|\| SECURE_AUTH_KEY \|\| LOGGED_IN_KEY \|\| NONCE_KEY` when all four are defined strings, nonempty, and none contain the WordPress placeholder `put your unique phrase here`.
- `key_source` **2** = SHA-256 binary digest of `wp_salt('auth')` (used only when source 1 cannot be resolved at encrypt time).
- Any other `key_source` byte → **INVALIDATED**.

**Encoding:** RFC 4648 §5 **base64url**, **unpadded**. Alphabet `A-Za-z0-9-_`. Forbidden in the remainder: `+`, `/`, `=`, whitespace, other octets. Decoding is **strict and canonical**: the remainder must match `^[A-Za-z0-9_-]+$`; decode must succeed; re-encoding the payload must equal the remainder exactly (reject non-canonical encodings).

**AAD (authenticated additional data):** the **18** ASCII bytes `upr.openai.api_key` immediately followed by the single `key_source` octet from the payload (no delimiter, no length prefix):

```text
AAD = "upr.openai.api_key" || key_source
```

Encrypt and decrypt must use this exact concatenation. Decrypt binds AAD to the `key_source` byte **parsed from the envelope** (the same byte used to select key material). Flipping only that byte (including `1` → `2`, both otherwise-valid sources) must make GCM authentication fail → **INVALIDATED**. The source byte is therefore authenticated; it is not unauthenticated envelope metadata.

**Cipher:** AES-256-GCM, 32-byte key, 12-byte nonce from `random_bytes(12)`, 16-byte tag. OpenSSL encrypt/decrypt with `OPENSSL_RAW_DATA` and tag as the separate GCM tag parameter (tag is **not** appended inside the ciphertext field).

**Length bounds:**

- Plaintext (submitted key after the input contract): **1–512 octets**.
- Valid v1 payload: **30–541 bytes** (29 + N, N in 1–512).
- Valid v1 stored envelope: **45–727 characters** (`upr1:` + unpadded base64url of 30–541 bytes).
- Hard read cap: stored option string **> 1024** characters → **INVALIDATED** without decoding (DoS bound). Never log the value.

**Decrypt / parse outcomes** (never throw to the admin; never auto-delete; never `error_log` / admin notice / WP_Error containing envelope, plaintext, nonce, tag, or source byte):

| Condition | State |
|-----------|--------|
| Option absent, not a string, or empty | stored **absent** (not INVALIDATED) |
| Length > 1024 | INVALIDATED |
| Prefix not exactly `upr1:` (including `upr2:`, `UPR1:`, `usc1:`, missing colon) | INVALIDATED |
| Remainder empty, invalid alphabet, `+` `/` `=`, whitespace, or non-canonical base64url | INVALIDATED |
| Decoded payload shorter than 30 bytes (truncated nonce/tag/ciphertext) | INVALIDATED |
| Decoded payload longer than 541 bytes | INVALIDATED |
| Unknown `key_source` | INVALIDATED |
| Recorded key-source material unavailable (e.g. salts now placeholders) | UNAVAILABLE |
| GCM tag fail, wrong AAD, `key_source` byte flipped, truncated/tampered ciphertext, salt rotation | INVALIDATED |
| Decrypt succeeds but plaintext length not in 1–512 or contains NUL/C0/DEL | INVALIDATED; do not use |

OpenAI `require_secret()` treats absent, INVALIDATED, and UNAVAILABLE as existing `credential_missing`.

**Required invalid-form tests** (each fail-closed; assert no notices and no log of the fixture): empty remainder after `upr1:`; standard base64 with `+` `/` or padding `=`; embedded whitespace; `upr2:` / `UPR1:` / `usc1:`; truncated payload (< 30 decoded bytes); payload with nonce or tag truncated; unknown `key_source` `0` / `3` / `255`; **changing only `key_source` `1` → `2` (AAD mismatch) → INVALIDATED**; non-canonical base64url; envelope > 1024 chars; wrong AAD; tampered tag; salt-rotation INVALIDATED without modifying stored bytes.

---

## 5. Narrow input contract (normative)

Applies only to the admin-post save/replace field (illustrative name `upr_openai_api_key`). **Not** used on decrypt.

1. If the field is missing or not a PHP string → reject save (allowlisted notice code only; no write).
2. `wp_unslash` only — no `sanitize_text_field`, `sanitize_textarea_field`, `sanitize_key`, `wp_strip_all_tags`, `esc_*`, or regex that folds case / strips tags.
3. If length is **0** after unslash → **no-op** (keep stored). Do not treat as clear.
4. If length **> 512** → reject save (no write).
5. If any octet is NUL (`0x00`), C0 control (`0x01–0x1F`), or DEL (`0x7F`) → reject save (no write).
6. Otherwise **preserve bytes** (including spaces). Do not trim.
7. Encrypt that byte string; `update_option` the new envelope with `autoload=false` (overwrites INVALIDATED blobs).
8. **Then** `unset( $_POST['upr_openai_api_key'], $_REQUEST['upr_openai_api_key'] )` before admin notices or `wp_safe_redirect`. Do not put the key, envelope, or length of the key in query args. Allowlisted redirect codes only (`saved` / `replaced` / `cleared` / `rejected` / `forbidden`).

Reject, no-op, and clear paths must also unset the field from `$_POST` / `$_REQUEST` before notices/redirect when the field was present.

---

## 6. Security review

**Threats in scope:** stolen HTML/JS, REST option dump, Settings API round-trip, Site Health debug, SupportExport, `wp upr ai-status`, exception/log interpolation, audit JSON, debug plugins dumping `$_POST` after persist, salt rotation, CSRF, `moderate_comments` users, object-cache dump of autoloaded options.

**Controls:**

- Authenticated encryption + purpose-binding AAD (`"upr.openai.api_key" || key_source`) so ciphertext cannot be transplanted as another secret **and** the envelope `key_source` byte cannot be swapped without GCM failure.
- Strict versioned envelope (§4): unknown/malformed/truncated/non-canonical → INVALIDATED, no plaintext fallback, no notices/logs from parse/decrypt.
- WP salts as key material only; placeholder salts rejected for the four-KEY tier.
- Fail closed: cannot decrypt → OpenAI cannot run; blob remains on the **read** path. A confirmed save **overwrites** an INVALIDATED blob atomically; clear is optional, never required before replace.
- Dedicated POST so the Controls Settings API save cannot persist or echo the field.
- Narrow input contract; generic WP text sanitizers forbidden on the secret; POST/REQUEST key unset before notices/redirect.
- `show_in_rest` never; option name is obviously ciphertext; **first save must persist `autoload=false`**, proven by integration test (not documentation alone).
- Capability `manage_woocommerce` (same bar as external enable / OpenAI re-analysis).
- Never interpolate secret, envelope, or `$_POST` key into exceptions, redirects (`?upr_cred=saved` codes only), or admin notices.
- Test connection remains existing synthetic path; this freeze does not turn it on.

**Residual risk (state honestly):** plugin limits are not a defence against a compromised `manage_woocommerce` admin (M10 O16 unchanged). Encrypted-at-rest is not host-HSM. A DB + `wp-config.php` steal still yields the key. Generic `wp option get upr_openai_api_key_ciphertext` may show **ciphertext**; UPR-owned surfaces must not print it. Object cache may hold ciphertext; that is acceptable.

**Out of policy if implementation later:** CBC/unauthenticated crypto; AAD that is only `upr.openai.api_key` without the `key_source` byte; standard (non-url) base64 or padded envelopes; returning the key to the password `value`; REST; CLI get/set of the key; SupportExport v2; shared `Core\Security\CredentialVault`; encrypting other secrets; auto-delete on decrypt fail; requiring clear before replace; `sanitize_text_field` on the secret; enabling external AI; real OpenAI calls in tests.

---

## 7. Work packages (implementation after freeze)

| WP | Deliverable |
|----|-------------|
| **WP1** | Narrow AES-256-GCM cipher + store under `src/Ai/OpenAi/` matching §4–§5 |
| **WP2** | Extend `CredentialResolver` with `SOURCE_STORED` and fail-closed INVALIDATED/UNAVAILABLE → `credential_missing`; preserve test seam and host override precedence |
| **WP3** | Controls UI + dedicated `admin-post` handler (`upr_openai_credential`); confirm/nonce/`manage_woocommerce` |
| **WP4** | Uninstall deletes option; D17 / Site Health status tokens only (`stored` / `undecryptable` allowed as source/status tokens — never values) |
| **WP5** | Required tests (§8) + runbooks (`ai-outage.md`, `operator-controls.md`, `moderation-capabilities.md`) |

Optional: privacy-safe audit `ai.openai_credential` payload `{action: saved|replaced|cleared}` only — never option value.

---

## 8. Acceptance criteria (implementation)

- [ ] Envelope round-trip under source 1 and source 2
- [ ] Every malformed-envelope case in §4 fails closed without notices/logs
- [ ] Changing only `key_source` fails due to AAD binding
- [ ] Salt rotation fails closed without changing stored ciphertext
- [ ] First save proves `autoload=false` and absence from `wp_load_alloptions()`
- [ ] Replacement preserves non-autoloading and overwrites INVALIDATED ciphertext without prior clear
- [ ] Precedence constant → environment → stored
- [ ] Save/clear confirmation, nonce, and capability denials
- [ ] Input-contract rejects and `$_POST`/`$_REQUEST` scrubbing on all paths
- [ ] No secret/ciphertext in HTML, REST, CLI, diagnostics, Site Health, audit, SupportExport, logs, redirects, or exceptions
- [ ] M10 regression policy tests remain green; SupportExport schema unchanged; SemVer remains `0.8.0`

---

## 9. Explicit non-goals

DEV/production WordPress access; entering a real key; enabling external AI; test-connection against live OpenAI; SemVer / `v0.8.0` movement / Release / ZIP; any M14 freeze or runtime edit; claiming M14 is closed; generic secret framework; extra providers; SupportExport schema change; schema/`DB_VERSION` bump; shared Support Chat / Telegram vault code.

---

## 10. Freeze acceptance

This document is the authoritative freeze once the annotated tag `m10-o9-encrypted-openai-credential-freeze` peels to its merge commit. Implementation may proceed only on that freeze. Implementation merges still do **not** authorise enablement, real keys, Release, ZIP, or SemVer movement.
