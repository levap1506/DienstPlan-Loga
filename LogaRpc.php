<?php
/**
 * LOGA Portal - privateRPC transport crypto
 *
 * Mirror of the client's loga_rpc.py. LOGA3 protects every
 * /loga3/privateRPC/<Service> call with a thin transport encryption:
 *
 *   key   = "1$7d%C&S" + substr(token, 8, 16)      (24 bytes -> AES-192)
 *   token = the Rpc-Xsrf value (same as the REST ?xsrf= token)
 *   cipher: AES-192-CBC, zero IV, PKCS#7 (deterministic)
 *   body  = hex( encrypt( base64( envelope ) ) )
 *
 * envelope = "7|3|<tableSize>|<moduleBase>|<strongName>|49|<token>|_|<method>|<args...>"
 *
 * @author  DienstPlan System
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';

class LogaRpc {
    private const KEY_PREFIX   = '1$7d%C&S';
    private const TOKEN_OFFSET = 8;
    private const TOKEN_END    = 24;
    private const BLOCK_SIZE   = 16;
    private const CIPHER       = 'aes-192-cbc';

    /**
     * Derive the 24-byte AES-192 key from the Rpc-Xsrf token.
     */
    public static function deriveKey(string $token): string {
        if ($token === '' || strlen($token) < self::TOKEN_END) {
            throw new \RuntimeException('LOGA RPC token is missing or too short');
        }
        return self::KEY_PREFIX . substr($token, self::TOKEN_OFFSET, self::TOKEN_END - self::TOKEN_OFFSET);
    }

    private static function iv(): string {
        return str_repeat("\0", self::BLOCK_SIZE);
    }

    /**
     * Encrypt a plaintext envelope into a privateRPC hex body.
     */
    public static function encryptBody(string $token, string $envelope): string {
        $key = self::deriveKey($token);
        $plain = base64_encode($envelope);
        // openssl applies PKCS#7 padding by default.
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, self::iv());
        if ($cipher === false) {
            throw new \RuntimeException('LOGA RPC encryption failed: ' . openssl_error_string());
        }
        return bin2hex($cipher);
    }

    /**
     * Decrypt a privateRPC body (hex) into its plaintext envelope.
     */
    public static function decryptBody(string $token, string $bodyHex): string {
        $key = self::deriveKey($token);
        $binary = @hex2bin(trim($bodyHex));
        if ($binary === false) {
            throw new \RuntimeException('LOGA RPC body is not valid hex');
        }
        $plain = openssl_decrypt($binary, self::CIPHER, $key, OPENSSL_RAW_DATA, self::iv());
        if ($plain === false) {
            throw new \RuntimeException('LOGA RPC decryption failed: ' . openssl_error_string());
        }
        $decoded = base64_decode($plain, true);
        return $decoded !== false ? $decoded : $plain;
    }

    /**
     * Headers required for a privateRPC call.
     */
    public static function headers(string $moduleBase, string $permutation, string $token, string $mask = 'LWSPEP'): array {
        return [
            'Origin: https://schwarzw.pi-asp.de',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0',
            'Accept: */*',
            'Content-Type: text/x-gwt-rpc; charset=utf-8',
            'Rpc-Xsrf: ' . $token,
            'X-GWT-Module-Base: ' . $moduleBase,
            'X-GWT-Permutation: ' . $permutation,
            'rpc-context-app: LOGA',
            'rpc-context-msk: ' . $mask,
        ];
    }

    /**
     * Escape a value for a GWT-RPC string-table entry (backslash and pipe).
     */
    public static function escape(string $value): string {
        return str_replace(['\\', '|'], ['\\\\', '\\!'], $value);
    }

    /**
     * Fill {PLACEHOLDER} tokens in an envelope template.
     *
     * @param array<string,string> $values Placeholder => raw value (escaped here)
     */
    public static function fill(string $template, array $values): string {
        foreach ($values as $key => $value) {
            $template = str_replace('{' . $key . '}', self::escape((string)$value), $template);
        }
        return $template;
    }

    // ─── Self-test ──────────────────────────────────────────────────────────
    public static function selfTest(): void {
        $token = '12299310C9BF05BF2242643F51ABA7B9';
        $body  = '7e7c5832562ccd3b69a677697c2e37006e39675f0cbfb340174e5e8ff8769921165e6ed70a3c42ad9c'
               . 'e66f5aff2eeb4b1d7e48b340b6163c8f301d77325717b694b801a8a49cd400b75e2c064fa30df64fb2'
               . '4c49d893b1d53ff00cc06a9e8156f364421b487f700791df8c0e1d33263736a9623478250f1c8f44a9'
               . '29d341d956f2bfee631867755111a5aab7645614d9fd36f2442a159cc854c198168730e034cca77bacd'
               . '8e250c018c86e027c0232085e6277c1c5452c9d9a0ea8bcb713cac33207f99141cb1e9ab0f09a25966'
               . 'e82370599fdc3a20f91137058d5a151eda7961d51175b4a1898928c157ee7967c4e52';
        $envelope = self::decryptBody($token, $body);
        if (strpos($envelope, '7|3|6|https://schwarzw.pi-asp.de/') !== 0) {
            throw new \RuntimeException('Self-test: unexpected envelope: ' . substr($envelope, 0, 60));
        }
        if (self::encryptBody($token, $envelope) !== $body) {
            throw new \RuntimeException('Self-test: encrypt(decrypt(x)) mismatch');
        }
        echo "LogaRpc self-test OK\n";
    }
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'self-test') {
    LogaRpc::selfTest();
}
