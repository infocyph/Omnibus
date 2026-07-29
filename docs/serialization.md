# Serialization and payload security

`JsonEnvelopeSerializer` uses version `1` envelopes and explicit
`MessageCodecRegistry` and `StampCodecRegistry` aliases.

Defaults:

| Limit | Default | Valid values |
| --- | ---: | --- |
| maximum encoded bytes | `262144` | positive integer |
| maximum JSON depth | `32` | integer `>= 2` |
| maximum stamps | `64` | positive integer |

Decoding never calls `unserialize()` and never instantiates a PHP class named by
the payload. An alias is accepted only when the application registered a codec
for it. Unknown message and stamp aliases fail before handler execution.

Message aliases should include a stable domain name and payload version, such as
`billing.invoice.create.v1`. A codec owns migration from its payload version to
the current application object.

`CallbackEnvelopeSerializer` supports MessagePack or another binary format
without making its extension a core dependency. Its callbacks must retain the
same explicit alias allow-list and must never deserialize arbitrary PHP class
names. The byte limit applies before decode and after encode.

```php
$serializer = new CallbackEnvelopeSerializer(
    decoder: static fn(string $payload): Envelope => $safeCodec->decode(
        msgpack_unpack($payload),
    ),
    encoder: static fn(Envelope $envelope): string => msgpack_pack(
        $safeCodec->encode($envelope),
    ),
);
```

The application-supplied `$safeCodec` owns alias validation. Do not pass
MessagePack values directly to object constructors by a class name contained in
the payload.

Do not place secrets, credentials, access tokens, complete ORM/repository
objects, open resources, closures, or unbounded binary data in messages.
