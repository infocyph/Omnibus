# Batch 5 lease and recovery certification

Implementation SHA: `38191540c26af6dfe51f28e9c5b9219c9d7a545f`

GitHub Actions run: `37707636584`

## Delivered semantics

- Runwire cancellation is phase-specific. Admission/handler cancellation is surfaced directly and never handed to the retry strategy.
- Successful business execution enters a bounded worker-owned cleanup request for acknowledgement/settlement, then the original host cancellation is surfaced before another prefetched message starts.
- DeadlineExecutionScope combines its local timeout with the earlier active Runwire request/scope deadline and cancellation signal.
- Unfinished cancelled reservations remain recoverable through normal visibility timeout.
- UniqueSender retains detached uniqueness ownership after an ambiguous send exception until TTL expiry.
- FailureManager retains an attempted retry claim after an ambiguous sender exception until lease expiry.
- WorkflowCoordinator retains the attempted dispatch claim after an ambiguous send and releases only claims that were never attempted.
- Stale retry/dispatch ownership cannot settle a newer claim.
- Existing post-execution timeout/lease/coordination failures remain explicitly nonretryable.
- After-response callbacks do not retain a completed originating request; after-commit callbacks observe the active commit request and binding state is restored afterward.

## Operational contract

The operations guide records visibility, overlap, uniqueness and claim lease sizing against measured p99 handler/settlement/jitter, including the serial prefetch waiting budget. Omnibus does not claim background lease renewal where no provider/host capability exists.

Ambiguous delivery remains at-least-once. Stable message/workflow IDs, idempotent handlers and durable failure/workflow state are the reconciliation source of truth. Lifecycle/telemetry callbacks remain bounded best-effort diagnostics rather than authoritative state.

## Exact certification

Passed on the implementation SHA:

- PHP 8.4 analysis;
- PHP 8.5 analysis;
- PHP 8.4 prefer-lowest QA;
- PHP 8.4 prefer-stable QA;
- PHP 8.5 prefer-lowest QA;
- PHP 8.5 prefer-stable QA;
- PHP 8.4 representative benchmark;
- PHP 8.5 representative benchmark;
- clean install;
- replica writer-affinity.

No PHPForge rule, static-analysis budget, or release gate was weakened.
