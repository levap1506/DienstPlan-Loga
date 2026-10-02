"""LOGA ``privateRPC`` transport — encrypted GWT-RPC.

LOGA3 (build ``20260813015921482``) protects every ``/loga3/privateRPC/<Service>``
call with a thin transport encryption on top of GWT-RPC.  This module
implements it so the client can both *read* (decrypt) and *send* (encrypt)
`privateRPC` calls without a browser.

How it was derived
------------------
The L2Main GWT cache JS (public, fetched by
:class:`RuntimeConfigResolver`) contains the encryptor ``ypT(payload, key)`` and
the key getter ``WqT()``.  ``WqT()`` builds the key from a constant plus the
``Rpc-Xsrf`` token (the same 32-hex value used as the REST ``?xsrf=`` token):

    key = b"1$7d%C&S" + token[8:24]          # 24 bytes -> AES-192

Cipher: **AES-192-CBC with a zero IV and PKCS#7 padding** (deterministic; the
same plaintext always yields the same ciphertext for one token).

Plaintext of a request body is::

    base64( "7|3|<stringTableSize>|<moduleBase>|<strongName>|49|<token>|_|<method>|<args...>" )

Request headers observed::

    Content-Type:        text/x-gwt-rpc; charset=utf-8
    Rpc-Xsrf:            <token>
    X-GWT-Module-Base:   https://schwarzw.pi-asp.de/loga3/bts/<version>/L2Main/
    X-GWT-Permutation:   <L2Main permutation>
    rpc-context-app:     LOGA
    rpc-context-msk:     LWSPEP

The ``<args...>`` are GWT's obfuscated per-build serialization, so callers
should template a captured envelope (replace token/date/ids) rather than build
args from scratch.

Security note: this is transport obfuscation, not a secret.  The key is fully
derivable from the session ``Rpc-Xsrf`` token, so it is not stored anywhere.
"""

from __future__ import annotations

import base64
from dataclasses import dataclass

#: Constant part of the AES key (from ``WqT()`` in the L2Main cache JS).
KEY_PREFIX = b"1$7d%C&S"
#: Slice of the ``Rpc-Xsrf`` token appended to the key prefix.
TOKEN_OFFSET = 8
TOKEN_END = 24
BLOCK_SIZE = 16
ZERO_IV = b"\x00" * BLOCK_SIZE


class LogaRpcError(RuntimeError):
    """Malformed LOGA RPC payload or missing crypto dependency."""


def derive_key(token: str) -> bytes:
    """Derive the 24-byte AES-192 key from the ``Rpc-Xsrf`` token."""
    if not token or len(token) < TOKEN_END:
        raise LogaRpcError("RPC token is missing or too short")
    return KEY_PREFIX + token[TOKEN_OFFSET:TOKEN_END].encode("latin1")


def _cipher(key: bytes):
    try:
        from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
    except ImportError as exc:  # pragma: no cover - depends on environment
        raise LogaRpcError(
            "The 'cryptography' package is required for LOGA RPC: "
            "python -m pip install -r requirements.txt"
        ) from exc
    return Cipher(algorithms.AES(key), modes.CBC(ZERO_IV))


def _pad(data: bytes) -> bytes:
    pad = BLOCK_SIZE - (len(data) % BLOCK_SIZE)
    return data + bytes([pad]) * pad


def _unpad(data: bytes) -> bytes:
    if not data:
        raise LogaRpcError("Empty RPC payload")
    pad = data[-1]
    if pad < 1 or pad > BLOCK_SIZE or pad > len(data):
        raise LogaRpcError("Invalid PKCS#7 padding (wrong token or corrupt body)")
    return data[:-pad]


def decrypt_body(token: str, body_hex: str) -> str:
    """Decrypt a ``privateRPC`` request/response body (hex) to its envelope."""
    key = derive_key(token)
    try:
        ciphertext = bytes.fromhex(body_hex.strip())
    except ValueError as exc:
        raise LogaRpcError("RPC body is not valid hex") from exc

    decryptor = _cipher(key).decryptor()
    plain = _unpad(decryptor.update(ciphertext) + decryptor.finalize())
    try:
        return base64.b64decode(plain).decode("latin1")
    except Exception as exc:  # noqa: BLE001 - surfaced as a domain error
        raise LogaRpcError("RPC payload is not base64") from exc


def encrypt_body(token: str, envelope: str) -> str:
    """Encrypt a plaintext envelope into a ``privateRPC`` hex body."""
    key = derive_key(token)
    plain = base64.b64encode(envelope.encode("latin1"))
    encryptor = _cipher(key).encryptor()
    return (encryptor.update(_pad(plain)) + encryptor.finalize()).hex()


def rpc_headers(
    module_base: str,
    permutation: str,
    token: str,
    app: str = "LOGA",
    mask: str = "LWSPEP",
) -> dict[str, str]:
    """Headers required for a ``privateRPC`` call."""
    return {
        "Content-Type": "text/x-gwt-rpc; charset=utf-8",
        "Rpc-Xsrf": token,
        "X-GWT-Module-Base": module_base,
        "X-GWT-Permutation": permutation,
        "rpc-context-app": app,
        "rpc-context-msk": mask,
    }


@dataclass(frozen=True)
class Envelope:
    """Parsed ``privateRPC`` envelope.

    Layout::

        7 | 3 | <stringTableSize> | <moduleBase> | <strongName> | 49 |
        <token> | _ | <method> | <gwt args...>
    """

    version: str
    table_size: int
    module_base: str
    strong_name: str
    token: str
    method: str
    raw: str

    @classmethod
    def parse(cls, envelope: str) -> "Envelope":
        parts = envelope.split("|")
        if len(parts) < 9 or parts[0] != "7":
            raise LogaRpcError(
                f"Unexpected RPC envelope: {envelope[:80]!r}"
            )
        return cls(
            version=parts[0],
            table_size=int(parts[2]) if parts[2].isdigit() else 0,
            module_base=parts[3],
            strong_name=parts[4],
            token=parts[6],
            method=parts[8],
            raw=envelope,
        )


# ─── Self-test ──────────────────────────────────────────────────────────────
# Fixed vector captured from the live portal (token already expired).  Proves
# the implementation matches the server: AES-192-CBC/zero-IV/PKCS#7 is
# deterministic, so encrypt(decrypt(x)) must reproduce the exact body.
_TEST_TOKEN = "12299310C9BF05BF2242643F51ABA7B9"
_TEST_BODY = (
    "7e7c5832562ccd3b69a677697c2e37006e39675f0cbfb340174e5e8ff8769921165e6ed70a3c42ad9c"
    "e66f5aff2eeb4b1d7e48b340b6163c8f301d77325717b694b801a8a49cd400b75e2c064fa30df64fb2"
    "4c49d893b1d53ff00cc06a9e8156f364421b487f700791df8c0e1d33263736a9623478250f1c8f44a9"
    "29d341d956f2bfee631867755111a5aab7645614d9fd36f2442a159cc854c198168730e034cca77bacd"
    "8e250c018c86e027c0232085e6277c1c5452c9d9a0ea8bcb713cac33207f99141cb1e9ab0f09a25966"
    "e82370599fdc3a20f91137058d5a151eda7961d51175b4a1898928c157ee7967c4e52"
)
_TEST_EXPECT = (
    "7|3|6|https://schwarzw.pi-asp.de/loga3/bts/20260813015921482/L2Main/"
    "|B0DCAB5410DA1EFFB4E3F4A1A02669CE|49|"
)


def _self_test() -> None:
    envelope = decrypt_body(_TEST_TOKEN, _TEST_BODY)
    assert envelope.startswith(_TEST_EXPECT), envelope[:120]
    assert encrypt_body(_TEST_TOKEN, envelope) == _TEST_BODY, "round-trip mismatch"

    parsed = Envelope.parse(envelope)
    assert parsed.method == "shouldRefreshTerminal", parsed.method
    assert parsed.token == _TEST_TOKEN

    custom = "7|3|4|https://x/L2Main/|ABCDEF|49|" + _TEST_TOKEN + "|_|doThing|1|2|0|"
    assert decrypt_body(_TEST_TOKEN, encrypt_body(_TEST_TOKEN, custom)) == custom
    print("loga_rpc self-test OK")


if __name__ == "__main__":
    _self_test()
