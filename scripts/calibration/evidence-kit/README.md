# M12 calibration evidence kit

**Purpose:** Produce privacy-safe `m12-cal-v1` evidence for the offline harness — either **synthetic Simulation GO** fixtures or **real-world Calibration GO** corpora.

**Status:** Preparation only. Repository posture remains **NO-GO — automatic action deferred** until a GO is issued. This kit does **not** implement automatic moderation and does **not** contain customer reviews.

Authoritative freeze: [`docs/roadmap/m12-guarded-auto-spam.md`](../../../docs/roadmap/m12-guarded-auto-spam.md) §0 / §5.  
Taxonomy: [`taxonomy.md`](taxonomy.md).  
Harness: [`../README.md`](../README.md).

---

## Two gates (do not confuse)

| Verdict | Input | Authorises | Does not authorise |
|---------|-------|------------|--------------------|
| `SIMULATION GO — implementation and non-production testing only` | AI/synthetic privacy-safe fixtures (`evidence_status: synthetic_simulation`) | Implementation with masters **default-off**; DEV/pre-prod testing with controlled synthetic fixtures; taxonomy / CAS / ledger / hook-parity / reversibility / rate-limit / disable / false-positive **scenario** validation | Production enablement; production customer-review action; claims of real-world precision/FPR |
| `CALIBRATION GO — production enablement decision may be considered` | Authorised real-world human-labelled privacy-safe evidence (`authorised_labelled`) | Same as simulation **plus** production enablement may be **considered** | Automatic production turn-on (separate production enablement GO still required) |
| `NO-GO — automatic action deferred` | Empty / incomplete / template / example / bare `synthetic` / failing metrics | Nothing | — |

---

## What this kit is / is not

| Is | Is not |
|----|--------|
| Templates, procedures, machine-readable manifest shape | A committed labelled corpus |
| Opaque sample IDs only | Real review text, names, emails, order IDs, IPs, tokens, URLs, credentials |
| Holdout lock **before** labelling completes / before tuning | Invented labels presented as Calibration evidence |
| Input to offline `evaluate.php` | Runtime AI / production enablement |

---

## Frozen thresholds (Simulation and Calibration)

| Gate | Threshold |
|------|-----------|
| Legitimate negative / critical (`not_spam`) | ≥ **400** |
| Technical spam | ≥ **200** |
| Locked holdout per primary stratum | ≥ **20%** |
| Blind double-label overlap (primary) | ≥ **20%** |
| False-spam Wilson 95% UCB (legit-neg holdout) | ≤ **1.0%** |
| Technical-spam precision (holdout would-act) | ≥ **95%** |
| Mandatory-human would-act | **0** |
| Calibrated tuple | Non-placeholder provider kind, assessor, heuristic **or** model/prompt fingerprint, validator, assessment / recommendation / action policy versions |

Would-act = M11 `RecommendationPolicy::suggest` === `likely_spam`.

---

## Simulation workflow (synthetic / AI-generated)

1. Copy [`templates/synthetic-simulation.template.json`](templates/synthetic-simulation.template.json).
2. Fill opaque rows + assessment maps only; `source_class: synthetic_authorised`.
3. Lock holdout + non-zero hashes; non-placeholder tuple.
4. `php scripts/calibration/evaluate.php <file.json>` → exit **10** on Simulation GO.

Maintainer acknowledgement that fixtures are synthetic is still required before treating Simulation GO as authority for Implementation GO.

---

## Calibration workflow (real-world) — unchanged authority bar

All of the following remain mandatory for Calibration GO:

1. **Maintainer written authorisation** for a named real-world dataset.
2. **Legal/privacy clearance** + `consent_or_authorisation_ref`.
3. `evidence_status: authorised_labelled` with `source_class` ∈ `operator_authorised_historical` \| `third_party_licensed` (**not** `synthetic_authorised`).
4. ≥2 human reviewers; ≥20% blind double-label + adjudication.
5. Holdout locked before final labelling/tuning.
6. Privacy-safe export only.

Without these, harness returns **NO-GO** or at most Simulation GO — **never** Calibration GO from synthetic input.

---

## Templates

| File | Role |
|------|------|
| [`templates/manifest.template.json`](templates/manifest.template.json) | Generic shell (`evidence_status: template`) → NO-GO |
| [`templates/synthetic-simulation.template.json`](templates/synthetic-simulation.template.json) | Simulation shell (empty rows → NO-GO) |
| [`templates/holdout-lock.template.json`](templates/holdout-lock.template.json) | Split lock placeholders |
| [`templates/provenance.template.json`](templates/provenance.template.json) | Provenance fields |
| [`templates/labelling-worksheet.template.csv`](templates/labelling-worksheet.template.csv) | Header-only worksheet |
