# Guest-edit credential alternative — decision

**Status:** **Deferred.** Completed-invite re-entry is sufficient. No second customer-visible edit secret is implemented.  
**Date:** 2026-08-31  
**Independent of SemVer / Release.** Runtime remains **`0.8.0`**.

---

## Intended experience (M14 freeze)

Guests who already completed a review may re-enter the seven-day edit flow with the **original** invite secret:

- Reissue of a short-lived `edit_session` is allowed while the comment clock and E3 predicates hold.
- A new mint **revokes** any prior active `edit_session` for that parent invite.
- Existing E30 bounds apply: serialised parent `FOR UPDATE`; **10** mints / rolling hour including revoked; generic denial at cap (no “rate limit” copy).
- Outside eligibility: the same generic invitation-unavailable denial as M2.

A second customer-visible completion credential was an explicit M14 **non-goal**.

---

## Automated evidence (merged M14)

| Requirement | Result | Test |
|-------------|--------|------|
| Completed-invite bearer enters `/upr-review/edit/` | **Pass** | `test_completed_invite_secret_edits_only_and_cannot_resubmit`; `test_token_exchange_completed_secret_303_to_edit` |
| No M2 reactivation / second submit | **Pass** | same; `find_active_by_raw(..., 'invite')` remains null; form POST does not insert |
| Reissue within the seven-day window | **Pass** | `test_e30_concurrent_from_zero_at_most_one_active` (second mint succeeds while eligible) |
| Prior active edit session revoked | **Pass** | same: hour count 2, **exactly one** unrevoked `edit_session` |
| Rate bounds (10 / hour, serialised) | **Pass** | `test_e30_ten_per_hour_serialized_reissue` (9 children → one mint, one denial, count = 10, never 11) |
| Generic denial outside eligibility | **Pass** | `test_security_revoked_redeemed_invite_generic_denial`; 11th mint `kind=deny` with M2 copy |

No functional deficiency is demonstrated. The optional guest-edit credential alternative **remains deferred**. This is not a freeze for implementing one.

---

## Explicit non-actions

- No second raw secret on the success page
- No completion-credential table or cookie
- No email
- No SemVer / Release / ZIP
