# M12 calibration harness (offline)

**Status:** Read-only calibration tooling for the frozen contract `auto_spam_held_technical`.  
**Does not** access WordPress comments, call providers, change statuses, use credentials, or access DEV/prod.

Authoritative gates: [`docs/roadmap/m12-guarded-auto-spam.md`](../../docs/roadmap/m12-guarded-auto-spam.md) §5.

## Evidence format (`m12-cal-v1`)

Privacy-safe JSON only:

- Opaque row ids
- `human_label`: `not_spam` | `technical_spam`
- `split`: `train` | `holdout` (holdout locked before tuning)
- `double_labelled`: boolean (blind overlap ≥ 20% of combined corpus)
- `assessment`: field map only (`state`, `confidence`, `publication_safety_score`, `reason_codes`)
- **Forbidden in Git:** review body, secrets, emails, tokens, claim/lease material, unnecessary PII

See `fixtures/empty-corpus.example.json`.

## Evaluate

```bash
php scripts/calibration/evaluate.php scripts/calibration/fixtures/empty-corpus.example.json
```

Exit `0` only on **Calibration GO**. Missing/insufficient corpus or threshold failure → exit `1` / verdict `NO-GO`.

## Gates enforced

| Gate | Threshold |
|------|-----------|
| Legitimate-negative corpus | ≥ 400 |
| Technical-spam corpus | ≥ 200 |
| Holdout per stratum | ≥ 20% |
| Double-label overlap | ≥ 20% combined |
| False-spam Wilson 95% UCB (legit-neg holdout) | ≤ 1.0% |
| Technical-spam precision (holdout would-act) | ≥ 95% |
| Mandatory-human would-act | 0 |

Would-act = M11 `RecommendationPolicy::suggest` === `likely_spam` (frozen conjunction).

## Current evidence

No compliant labelled corpus is present in this repository. Fabricating labels is forbidden. See [`docs/roadmap/m12-calibration-nogo.md`](../../docs/roadmap/m12-calibration-nogo.md).
