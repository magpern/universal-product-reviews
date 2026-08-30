# M12 calibration harness (offline)

**Status:** Read-only calibration tooling for the frozen contract `auto_spam_held_technical`.  
**Does not** access WordPress comments, call providers, change statuses, use credentials, or access DEV/prod.

Authoritative gates: [`docs/roadmap/m12-guarded-auto-spam.md`](../../docs/roadmap/m12-guarded-auto-spam.md) §5.  
Evidence kit (labelling procedure + templates): [`evidence-kit/README.md`](evidence-kit/README.md).  
Taxonomy: [`evidence-kit/taxonomy.md`](evidence-kit/taxonomy.md).

## Evidence format (`m12-cal-v1`)

Privacy-safe JSON only. Required for GO eligibility:

| Field | Rule |
|-------|------|
| `evidence_status` | Must be `authorised_labelled` (never `template` / `example` / `synthetic` / `incomplete` / `draft`) |
| `provenance` | dataset id, authorising party, consent/authorisation ref, source class, labelled_at, reviewer_count |
| `holdout_lock` | Locked before labelling complete / tuning; non-zero `assignment_sha256`; optional `assignments` map checked for contamination |
| `dataset_hashes` | Non-zero `rows_sha256`, `labels_sha256`, `assessments_sha256` |
| `privacy` | `review_bodies_committed`, `secrets_committed`, `customer_identifiers_committed` all **false** |
| `tuple` | Non-placeholder provider kind, assessor version, heuristic **or** model/prompt fingerprint, validator, assessment / recommendation / action policy versions |
| Rows | Opaque ids; `human_label` ∈ `not_spam` \| `technical_spam` \| `mandatory_human` \| `excluded`; `split` train\|holdout; assessment field map only |
| Double-label | ≥ 20% primary overlap; `double_label` object with adjudication on disagreement |

**Forbidden in Git / evidence JSON:** review body, customer names, emails, order IDs, IPs, tokens, URLs, provider credentials, claim/lease material.

See `fixtures/empty-corpus.example.json` and `evidence-kit/templates/manifest.template.json` (both **NO-GO** by design).

## Evaluate

```bash
php scripts/calibration/evaluate.php scripts/calibration/fixtures/empty-corpus.example.json
php scripts/calibration/evaluate.php scripts/calibration/evidence-kit/templates/manifest.template.json
```

Exit `0` only on **Calibration GO**. Missing/insufficient/synthetic/undocumented corpus or threshold failure → exit `1` / verdict **NO-GO**.

## Gates enforced

| Gate | Threshold |
|------|-----------|
| Legitimate-negative corpus | ≥ 400 |
| Technical-spam corpus | ≥ 200 |
| Holdout per primary stratum | ≥ 20% |
| Double-label overlap (primary) | ≥ 20% |
| False-spam Wilson 95% UCB (legit-neg holdout) | ≤ 1.0% |
| Technical-spam precision (holdout would-act) | ≥ 95% |
| Mandatory-human would-act | 0 |
| Structural kit completeness | provenance, hashes, holdout lock, privacy, non-placeholder tuple |

Would-act = M11 `RecommendationPolicy::suggest` === `likely_spam` (frozen conjunction).

## Current evidence

No compliant labelled corpus is present in this repository. Fabricating labels is forbidden. M12 remains **NO-GO**. See [`docs/roadmap/m12-calibration-nogo.md`](../../docs/roadmap/m12-calibration-nogo.md).
