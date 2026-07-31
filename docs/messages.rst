Messages, envelopes, and routing
================================

Messages are ordinary objects. ``Envelope`` adds lifecycle metadata through
typed ``Stamp`` objects without requiring inheritance from an Omnibus base
class.

Core stamps
-----------

.. list-table::
   :header-rows: 1

   * - Stamp
     - Meaning
   * - ``MessageIdStamp``
     - Stable message identity assigned before transport dispatch.
   * - ``RouteStamp``
     - Selected transport and queue.
   * - ``DelayStamp``
     - Earliest delivery delay in seconds.
   * - ``AttemptStamp``
     - Current reservation attempt.
   * - ``EnqueuedAtStamp``
     - Enqueue timestamp used by opt-in telemetry.
   * - ``UniqueStamp``
     - Detached uniqueness lease identity and token.
   * - ``ChainStamp`` / ``BatchStamp``
     - Durable workflow identity and item position.
   * - ``CancellationStamp``
     - Cooperative execution-deadline token.
   * - ``HandledStamp``
     - Synchronous handler result; never part of durable serialization.

Map resolution
--------------

``RouteMap`` and ``HandlerMap`` accept explicit class/interface maps. Exact
message classes win. Parent and interface mappings supply polymorphic defaults.
Resolved lookups are cached on the map instance.

The default route is ``sync/default``. Configure another default explicitly
when asynchronous-by-default behavior is intended. A missing named transport
throws ``TransportNotFound``; Omnibus never silently falls back.

Boundary limits
---------------

* Queue names contain 1 through 191 bytes and no ASCII control characters.
* Transport names contain 1 through 100 bytes.
* Message IDs contain 1 through 191 bytes; workflow and workflow-item IDs
  contain at most 26 bytes.
* Receive and workflow-pending limits contain 1 through 1000 items.
* Delays and visibility timeouts must be finite and non-negative (strictly
  positive for visibility).
* Durable microsecond timestamps reject integer overflow before arithmetic.
* Opaque provider receipts are bounded at 4096 bytes. Oversized-but-valid
  provider receipts are hashed to a stable bounded terminal-failure ID.

``InMemoryTransport`` also assigns a message ID, matching durable transport
behavior and keeping tests representative.
