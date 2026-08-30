# M12 calibration evidence kit

**Purpose:** Allow authorised human reviewers to produce a privacy-safe `m12-cal-v1` labelled corpus for the offline harness under `scripts/calibration/`.

**Status:** Preparation only. M12 remains **NO-GO — AUTOMATIC ACTION DEFERRED**. This kit does **not** implement automatic moderation, does **not** authorise DEV/prod WordPress access, and does **not** contain real customer reviews.

Authoritative freeze: [`docs/roadmap/m12-guarded-auto-spam.md`](../../../docs/roadmap/m12-guarded-auto-spam.md) §5.  
Taxonomy: [`taxonomy.md`](taxonomy.md).  
Harness: [`../README.md`](../README.md).

---

## What this kit is / is not

| Is | Is not |
|----|--------|
| Templates, procedures, machine-readable manifest shape | A labelled corpus |
| Opaque sample IDs only | Real review text, names, emails, order IDs, IPs, tokens, URLs, credentials |
| Holdout lock **before** labelling completes / before tuning | Invented labels presented as evidence |
| Input to offline `evaluate.php` after human authorisation | Runtime AI enablement |

---

## Frozen thresholds (must all pass on holdout)

| Gate | Threshold |
|------|-----------|
| Legitimate negative / critical (`not_spam`) | ≥ **400** |
| Technical spam | ≥ **200** |
| Locked holdout per primary stratum | ≥ **20%** |
| Blind double-label overlap (primary) | ≥ **20%** |
| False-spam Wilson 95% UCB (legit-neg holdout) | ≤ **1.0%** |
| Technical-spam precision (holdout would-act) | ≥ **95%** |
| Mandatory-human would-act | **0** |
| Calibrated tuple | Exact provider kind, assessor version, heuristic **or** model/prompt/guidance fingerprint, validator, assessment / recommendation / action policy versions |

Would-act = M11 `RecommendationPolicy::suggest` === `likely_spam`.

---

## Authority required before introducing a real corpus

All of the following are mandatory:

1. **Maintainer written authorisation** to collect/label a calibration set for UPR M12 (named dataset id).
2. **Legal/privacy clearance** for the chosen `provenance.source_class` (`operator_authorised_historical` \| `third_party_licensed` \| `synthetic_authorised`). Customer WordPress/DEV/prod mining is **out of scope** unless separately authorised outside this kit.
3. **Consent / authorisation reference** recorded in `provenance.consent_or_authorisation_ref` (ticket/ADR/legal memo id — not personal data).
4. **Two or more human reviewers** instructed with [`taxonomy.md`](taxonomy.md); blind double-label ≥ 20% with adjudication of disagreements.
5. **Holdout assignment locked** (hashes recorded) **before** threshold tuning and before labelling is treated as final.
6. **Privacy export**: only opaque ids + assessment field maps + labels enter the JSON committed or evaluated; **never** review bodies or customer identifiers in Git.
7. **`evidence_status`: `authorised_labelled`** only after the above. Templates/examples/synthetic drafts remain non-GO forever until replaced by authorised evidence.

Without these, the harness **must** return **NO-GO**.

---

## Labelling procedure (human)

1. Create a private working area **outside** Git for full text (if any). Never commit bodies.
2. Assign opaque ids (`[A-Za-z0-9_-]{8,128}`).
3. Fill [`templates/holdout-lock.template.json`](templates/holdout-lock.template.json): map every id → `train`|`holdout`; compute `assignment_sha256`; set lock flags **before** final labels / tuning.
4. Reviewers label independently using [`taxonomy.md`](taxonomy.md). Record double-labels and adjudications.
5. Export privacy-safe rows into the manifest shape ([`templates/manifest.template.json`](templates/manifest.template.json)).
6. Fill provenance + dataset hashes (`rows_sha256`, `labels_sha256`, `assessments_sha256`).
7. Set `evidence_status` to `authorised_labelled` only when steps 1–6 and authority gates are complete.
8. Run offline:

```bash
php scripts/calibration/evaluate.php path/to/authorised-evidence.json
```

Exit `0` / verdict `Calibration GO` only if structural + metric gates pass. Otherwise **NO-GO**.

---

## Templates (empty / example-safe only)

| File | Role |
|------|------|
| [`templates/manifest.template.json`](templates/manifest.template.json) | Full `m12-cal-v1` shell (`evidence_status: template`) |
| [`templates/holdout-lock.template.json`](templates/holdout-lock.template.json) | Split lock + hash placeholders |
| [`templates/provenance.template.json`](templates/provenance.template.json) | Provenance / consent fields |
| [`templates/labelling-worksheet.template.csv`](templates/labelling-worksheet.template.csv) | Header-only worksheet (no sample rows) |

Evaluating any template **must** yield **NO-GO**.

---

## Compatibility with the harness

The evaluator requires:

- `schema_version`: `m12-cal-v1`
- `evidence_status`: `authorised_labelled` for GO eligibility
- `provenance`, `holdout_lock`, `dataset_hashes`, `privacy` (bodies/secrets/identifiers = false)
- `tuple` with non-placeholder values
- Primary strata `legitimate_negative` + `technical_spam`; optional `mandatory_human`, `excluded`
- Opaque ids; no forbidden content keys; double_label objects when `double_labelled: true`
- Holdout assignments consistent with row `split` when `holdout_lock.assignments` present
