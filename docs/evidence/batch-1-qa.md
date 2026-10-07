# Batch 1 QA evidence

Batch: queue, storage, and workflow integrity.

Implementation head before final QA synchronization: `4a4be385310411dc5bc7cff8796a691856dd0922`.

## Verified on the preceding implementation SHA

GitHub Actions on `398dc762d092ed8120c8344ccad99b03d0812522` established:

- Pest passed under the strict full integration manifest.
- PHPCS passed.
- duplicate detection passed.
- the DBLayer `acknowledgeWorkflow()` O07 complexity violation is resolved.
- PHPStan reports only `NativeWorkerPoolBackend::supervise()` at complexity 14, which belongs to Batch 2.
- representative benchmarks and clean-install checks passed.
- replica-affinity passed.
- the only QA failure was PHPForge Pint `ordered_imports` in `DBLayerTransport.php`.

The import-order defect was corrected in `4a4be385310411dc5bc7cff8796a691856dd0922`.

## Final gate

Exact-SHA GitHub Actions run `37643068219` on `49944de40c7197631f8953e083f172802b67aaa0` completed with:

- QA PHP 8.4 prefer-stable: passed.
- QA PHP 8.4 prefer-lowest: passed.
- QA PHP 8.5 prefer-stable: passed.
- QA PHP 8.5 prefer-lowest: passed.
- representative benchmark PHP 8.4: passed.
- representative benchmark PHP 8.5: passed.
- clean install: passed.
- replica writer-affinity: passed.
- Psalm and Composer audit in analysis: passed.
- PHPStan: one remaining inherited finding only, `NativeWorkerPoolBackend::supervise()` cognitive complexity 14 with budget 12. This is the Batch 2 O07 item.

Batch 1 is complete. The remaining native O07 failure is not accepted as release-ready; it is explicitly the next batch's blocker.
