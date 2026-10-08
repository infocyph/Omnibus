# Batch 6 measured performance and structural review

Status: implementation in progress; final exact-SHA quality and matched-environment release-performance certification remain separate.

## Current profiler evidence

GitHub Actions run `37726650107` on `87f13597180baaf5bb9463e261ec38b080b856b1` passed PHP 8.4/8.5 characterization and captured the PHPForge duplicate inventory. Earlier query-plan run `37726127853` on `4a9b9938c86518c3e98368a79913e21fb16ab4a4` passed MySQL/PostgreSQL 10,000-message depth at 2 workers under current/candidate/candidate-reclaim indexes. These are hosted-runner **diagnostics**, not stable-environment host RPM proof.

| Characterization | PHP 8.4 | PHP 8.5 |
| --- | ---: | ---: |
| Codec validation operations/s (50k repeated) | 2,308,940 | 2,247,246 |
| Envelope encode operations/s | 793,589 | 798,575 |
| Envelope decode operations/s | 508,891 | 486,628 |
| Warm route lookup operations/s | 10,860,258 | 10,350,707 |
| Warm handler lookup operations/s | 8,897,389 | 8,491,082 |
| Warm listener lookup operations/s | 11,490,085 | 10,595,308 |
| Distinct loaded classes resolved | 256 | 256 |
| Transient resolution-cache growth (bytes) | 37,032 | 37,032 |

Only measured one short runner sample of each workload. Each of six warm profiles reported zero PHP allocated-memory growth over 50,000 invocations. On map disposal, memory returned below the before-resolution reading because the benchmark also releases its temporary objects. These observations support **retaining** the simple configured lookup maps for now: PHP-owned class definitions are not unloaded by evicting Omnibus map entries. Do not infer persistent-host leakage absence from this single sample.

## U03 serializer disposition

The existing callback JSON validator performs an initial `json_encode(..., JSON_THROW_ON_ERROR)` before the final envelope encoding. That duplicate traversal has a measurable operation cost, but supplies cycle, invalid UTF-8, depth, resource, nonfinite float and invalid value rejection at the trust boundary. Added adversarial regressions for cyclic payloads, invalid UTF-8 and over-depth envelopes. **Retain** the current validator: no validated, materially faster replacement or application-level RPM gain has been demonstrated. Do not remove this guard based only on a component benchmark.

## U04 current PHPForge duplicate inventory

The current unmodified `composer ic:test:duplicate` reported **12 clone groups**, 665 duplicated lines across 173 PHP files, 5.82%, with detector status **PASS**. The older audit's 14-group count referred to an earlier tree.

| Group | Owners | Decision |
| --- | --- | --- |
| 1 | Worker-pool benchmark and DBLayer failure/transport/workflow stores | Retain: similar retry/setup statements across distinct DB ownership and benchmark boundaries |
| 2 | Durable soak and release harnesses | Retain independent benchmark setup to prevent coupling their measured workloads |
| 3 | DBLayer workflow failed/succeeded transitions | Retain distinct atomic state mutations and event accounting |
| 4 | HandlerMap and RouteMap hot-path resolution | Retain: callable identity vs route-value equivalence and different miss behavior; generic resolver would add hot-path indirection |
| 5 | CacheLayer OverlapProtectionScope and UniqueSender declarations | Retain separate execution/dispatch lease owners |
| 6 | Two worker-pool benchmark phases | Retain independent measurement setup |
| 7 | OverlapProtectionScope and UniqueSender lease operations | Retain: acquisition, settlement and release semantics differ |
| 8 | InMemoryWorkflowStore dispatch confirm vs release | Retain explicit tokens and opposite state transitions, avoiding extra call on successful dispatch |
| 9 | DBLayerWorkflowStore locked item and workflow queries | Retain dialect-specific row-locking SQL with distinct table/identity predicates |
| 10 | HandlerMap and RouteMap interface resolution | Retain separate ambiguity and equality rules |
| 11 | Two benchmark/run.php sample loops | Retain independent benchmark scenario accounting |
| 12 | InMemoryWorkflowStore cancel and chain-failure cancellation loops | **Shared invariant**: centralize active-state cancellation and claim-token clearing in one private helper, preserving item-index eligibility at each call site |

The last disposition is a correctness-maintenance improvement: both cancellation paths must clear the same claim ownership fields. It is not presented as an RPM optimization. No PHPForge policy, duplicate budget or suppression is modified.

## U05 lookup-cache disposition

The profiler resolves 256 distinct already-loaded classes without `eval` or generated code (forbidden by PHPForge); mapping growth was 37,032 bytes and maps were disposed at the end. Warm finite-class lookups are allocation-stable in the sampled loop. **Retain** unbounded per-map memoization pending evidence from an actual persistent host with dynamically defined classes; eviction cannot unload PHP classes, and a bound would cost hot finite-class lookup throughput.

## U07 durable storage and retention

The existing SQL comparison harness had an incorrect queue-index identifier and used DBLayer's SELECT-only API for EXPLAIN. Both were corrected; the query planner now runs via the supplied PDO connection, which is disconnected before forking child workers. Six MySQL/PostgreSQL 10k/2-worker candidate runs passed and retained JSON query-plan artifacts. This **does not** justify replacing production indexes yet: large 100k/1m, MariaDB/SQL Server/SQLite, and deeper contention/retention data remain open.

Found and fixed a material retention defect: `FailureStore::prune()` could remove `retrying` and `sent` failure records while their claims or reconciliation were active. Both in-memory and DBLayer implementations now prune only unclaimed `failed` records, with regressions and operations guidance. No schema or migration is introduced.

## Remaining Batch 6 acceptance

- Review captured SQL plan artifacts and repeat representative driver/backlog/retention profiles before deciding on any production index change.
- Compare 2.6/dependency-only, unbound 3.0 and host-bound 3.0 in a matched workload/environment, with failures, retries, cache hit/miss, and contention.
- Run exact-head PHPForge gates and regression suites after the final Batch 6 changes.
- Never treat GitHub-hosted component measurements as the stable 2% release RPM certification (Batch 8).
