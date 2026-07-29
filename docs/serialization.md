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

Do not place secrets, credentials, access tokens, complete ORM/repository
objects, open resources, closures, or unbounded binary data in messages.
