Serialization and payload security
==================================

Versioned JSON envelopes
------------------------

``JsonEnvelopeSerializer`` emits envelope version ``1`` and accepts only
aliases registered in ``MessageCodecRegistry`` and ``StampCodecRegistry``.

.. list-table::
   :header-rows: 1

   * - Limit
     - Default
     - Valid values
   * - Encoded payload bytes
     - ``262144``
     - Positive integer.
   * - JSON depth
     - ``32``
     - Integer from 2 through 512.
   * - Stamp count
     - ``64``
     - Positive integer.

Decode never calls PHP ``unserialize()`` and never instantiates a class name
selected by payload data. Unknown message or stamp aliases fail before handler
execution. Registry construction rejects duplicate aliases and duplicate
runtime types.

Message aliases should be stable and versioned, for example
``billing.invoice.create.v1``. The codec owns migration from that payload
version to the current application object.

.. code-block:: php

   $messages = new MessageCodecRegistry([
       new CallbackMessageCodec(
           'billing.invoice.create.v1',
           CreateInvoice::class,
           static fn (CreateInvoice $message): array => [
               'account_id' => $message->accountId,
           ],
           static fn (array $data): CreateInvoice => new CreateInvoice(
               (string) $data['account_id'],
           ),
       ),
   ]);

Stamp payloads contain scalar values only. Message codec payloads may be nested
JSON structures, bounded by the global byte and depth limits.

Binary codecs
-------------

``CallbackEnvelopeSerializer`` supports MessagePack or another binary format
without making its extension a core dependency:

.. code-block:: php

   $serializer = new CallbackEnvelopeSerializer(
       decoder: static fn (string $payload): Envelope =>
           $safeCodec->decode(msgpack_unpack($payload)),
       encoder: static fn (Envelope $envelope): string =>
           msgpack_pack($safeCodec->encode($envelope)),
       maximumBytes: 262_144,
   );

The supplied codec must retain the explicit alias allow-list. Do not map
payload-provided class names to constructors.

Payload guidance
----------------

Do not place credentials, access tokens, secrets, closures, open resources,
complete ORM/repository objects, or unbounded binary data in messages. Prefer
stable domain identifiers and reload mutable state inside the handler.

Changing an alias or removing a codec can make queued and failed payloads
undecodable. Deploy readers for both old and new aliases before producing the
new format, then retire old readers only after queues and failure retention are
drained.
