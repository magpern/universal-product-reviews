# M12 calibration harness (offline)

**Status:** Read-only tooling for `auto_spam_held_technical` evidence gates.  
**Does not** access WordPress comments, call providers, change statuses, use credentials, or access DEV/prod.

Authoritative gates: [`docs/roadmap/m12-guarded-auto-spam.md`](../../docs/roadmap/m12-guarded-auto-spam.md) §0 / §5.  
Evidence kit: [`evidence-kit/README.md`](evidence-kit/README.md).

## Exact verdicts

| Verdict | Exit code | Authorises |
|---------|-----------|------------|
| `SIMULATION GO — implementation and non-production testing only` | **10** | Implementation (masters default-off); DEV/pre-prod synthetic testing. **Not** production. |
| `CALIBRATION GO — production enablement decision may be considered` | **0** | Same as simulation **plus** production enablement may be **considered** (separate GO still required). |
| `NO-GO — automatic action deferred` | **1** | Nothing. |

`production_enablement_authorised` is **always false** in harness output. No corpus grants production permission automatically.

## Evidence status

| `evidence_status` | Possible outcomes |
|-------------------|-------------------|
| `synthetic_simulation` | Simulation GO only (never Calibration GO) |
| `authorised_labelled` | Calibration GO only (requires non-synthetic `source_class`) |
| `template` / `example` / `synthetic` / `incomplete` / `draft` | Always NO-GO |

## Evaluate

```bash
php scripts/calibration/evaluate.php scripts/calibration/fixtures/empty-corpus.example.json
php scripts/calibration/evaluate.php scripts/calibration/evidence-kit/templates/manifest.template.json
php scripts/calibration/evaluate.php scripts/calibration/evidence-kit/templates/synthetic-simulation.template.json
```

## Frozen metric gates (both Simulation and Calibration)

Same quantitative floors: ≥400 legit-neg, ≥200 technical-spam, ≥20% holdout/stratum, ≥20% double-label, Wilson false-spam UCB ≤ 1%, technical-spam precision ≥ 95%, zero mandatory-human would-act.

Simulation GO still does **not** claim real-world performance.
