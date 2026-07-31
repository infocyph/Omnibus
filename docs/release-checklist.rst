Release checklist
=================

Before tagging a stable release:

#. Confirm direct dependency constraints against Composer repositories.
#. Run ``composer validate --strict`` and
   ``composer audit --locked --abandoned=report``. Triage every abandoned
   package separately from security advisories and confirm whether it is
   runtime or development-only.
#. Run ``composer ic:ci`` with stable and lowest dependencies.
#. Run live MySQL, PostgreSQL, and Redis integration tests.
#. Build documentation with
   ``sphinx-build -W --keep-going -b html docs docs/_build/html``.
#. Run ``composer benchmark``, ``composer soak:consumer``, and
   ``composer soak:durable`` in a stable environment.
#. Run ``composer dump-autoload --no-dev --classmap-authoritative`` and require
   ``vendor/autoload.php``.
#. Build ``composer archive`` and inspect the archive contents.
#. Confirm the archive excludes tests, benchmarks, docs sources, local IDE
   files, caches, lock file, and development configuration.
#. Verify a clean consumer project can install ``infocyph/omnibus`` without
   DBLayer, CacheLayer, Redis, a framework, or a command package.
#. Verify optional adapters fail explicitly when their selected dependency or
   provider capability is unavailable.

Stable guarantees to recheck
----------------------------

* terminal failure persistence precedes rejection;
* acknowledgement errors are not retried as handler failures;
* stale receipts cannot settle reclaimed work;
* workflow terminal transitions and events are idempotent;
* telemetry exporter failure cannot change application outcomes;
* unknown serialized aliases cannot instantiate arbitrary classes;
* no optional integration initializes on an unrelated route.
