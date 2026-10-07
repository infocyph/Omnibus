# Batch 2 Runwire child-ownership audit

The Omnibus native backend and the explicitly selected Runwire supervisor have different ownership boundaries and are evaluated separately.

## Released Runwire behavior

The released Runwire `1.0` implementation currently resolved by Omnibus and the planned `2.1.1` release both implement `Supervisor\\Internal\\ChildReaper::reap()` with:

`pcntl_waitpid(-1, $status, WNOHANG)`

That means the standalone Runwire supervisor is a process-wide child reaper. Omnibus must not claim that selecting this backend preserves unrelated host-owned child statuses.

## Omnibus contract

Batch 2 fixes the native Omnibus backend so it polls only PIDs in its owned-child registry.

The Runwire backend remains an explicitly selected **standalone supervisor**. It is not a host-composition path and must not be selected merely because a host later supplies Runwire runtime/request/task capabilities. Batch 4 must keep passed-instance host composition separate from `RunwireWorkerPoolBackend`.

This upstream ownership limitation remains visible until the dependency floor can point at a Runwire release whose supervisor reaps only its owned children. It must not be hidden by an Omnibus test exclusion or by claiming global child ownership is host-safe.
