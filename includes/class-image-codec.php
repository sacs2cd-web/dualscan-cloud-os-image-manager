<?php
namespace Dualscan\CloudOS;
/** Original .dualscan format. Every chunk and the final marker authenticate the complete header. */
final class ImageCodec {
    public const MAGIC = "DUALSCAN\x01";
    public const CHUNK = 1048576;
    public const MAX_BYTES = 1099511627776;
    private static function password(string $password): void { if (strlen($password) < 12 || strlen($password) > 1024) { throw new EngineException('Use an image password between 12 and 1,024 bytes.'); } }
    public static function encrypt(string $plainPath, string $targetPath, string $password): void {
        self::password($password); $size = filesize($plainPath);
        if ($size === false || $size > self::MAX_BYTES) { throw new EngineException('Image exceeds the 1 TiB format limit.'); }
        $salt = random_bytes(32); $prefix = random_bytes(8);
        $header = SafeFiles::json(['version' => 1, 'cipher' => 'AES-256-GCM', 'kdf' => 'PBKDF2-SHA256', 'iterations' => 600000, 'salt' => base64_encode($salt), 'noncePrefix' => base64_encode($prefix), 'chunkBytes' => self::CHUNK, 'plainBytes' => $size]);
        $aad = self::MAGIC . pack('N', strlen($header)) . $header;
        $key = hash_pbkdf2('sha256', $password, $salt, 600000, 32, true);
        $in = fopen($plainPath, 'rb'); $out = fopen($targetPath, 'xb');
        if (!$in || !$out) { throw new EngineException('Cannot open encrypted image output.'); } chmod($targetPath, 0600);
        try {
            SafeFiles::writeAll($out, $aad); $index = 0;
            do {
                $plain = fread($in, self::CHUNK); if ($plain === false) { throw new EngineException('Cannot read staged image.'); }
                $length = strlen($plain); $tag = '';
                $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix . pack('N', $index), $tag, $aad . pack('NN', $index, $length), 16);
                if ($encrypted === false || strlen($tag) !== 16) { throw new EngineException('Encryption failed.'); }
                SafeFiles::writeAll($out, pack('N', $length) . $encrypted . $tag); $index++;
            } while ($length !== 0);
            if (!fflush($out)) { throw new EngineException('Cannot flush encrypted output.'); }
            if (function_exists('fsync')) { fsync($out); }
        } catch (\Throwable $e) { fclose($in); fclose($out); @unlink($targetPath); throw $e; }
        fclose($in); fclose($out); if (function_exists('sodium_memzero')) { sodium_memzero($key); }
    }
    public static function decrypt(string $imagePath, string $plainPath, string $password): void {
        self::password($password); $in = fopen($imagePath, 'rb'); if (!$in) { throw new EngineException('Cannot read image.'); }
        $out = null; $key = '';
        try {
            if (SafeFiles::readExactly($in, strlen(self::MAGIC)) !== self::MAGIC) { throw new EngineException('This is not a supported .dualscan image.'); }
            $headerLength = unpack('N', SafeFiles::readExactly($in, 4))[1];
            if ($headerLength < 100 || $headerLength > 4096) { throw new EngineException('Invalid image header length.'); }
            $headerText = SafeFiles::readExactly($in, $headerLength);
            try { $header = json_decode($headerText, true, 16, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new EngineException('Invalid image header.', 0, $e); }
            if (!is_array($header) || ($header['version'] ?? null) !== 1 || ($header['cipher'] ?? '') !== 'AES-256-GCM' || ($header['kdf'] ?? '') !== 'PBKDF2-SHA256' || !is_int($header['iterations'] ?? null) || $header['iterations'] < 600000 || $header['iterations'] > 2000000 || ($header['chunkBytes'] ?? null) !== self::CHUNK || !is_int($header['plainBytes'] ?? null) || $header['plainBytes'] < 4 || $header['plainBytes'] > self::MAX_BYTES) { throw new EngineException('Invalid or unsupported image parameters.'); }
            $salt = base64_decode($header['salt'] ?? '', true); $prefix = base64_decode($header['noncePrefix'] ?? '', true);
            if ($salt === false || strlen($salt) !== 32 || $prefix === false || strlen($prefix) !== 8) { throw new EngineException('Invalid image key parameters.'); }
            $key = hash_pbkdf2('sha256', $password, $salt, $header['iterations'], 32, true);
            $aad = self::MAGIC . pack('N', $headerLength) . $headerText;
            $out = fopen($plainPath, 'xb'); if (!$out) { throw new EngineException('Cannot create private decrypt stage.'); } chmod($plainPath, 0600);
            $total = 0; $index = 0;
            while (true) {
                $length = unpack('N', SafeFiles::readExactly($in, 4))[1];
                $expectedLength = min(self::CHUNK, $header['plainBytes'] - $total);
                if ($length !== $expectedLength) { throw new EngineException('Image chunk exceeds or disagrees with its authenticated bounds.'); }
                $cipher = SafeFiles::readExactly($in, $length); $tag = SafeFiles::readExactly($in, 16);
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix . pack('N', $index), $tag, $aad . pack('NN', $index, $length));
                if ($plain === false) { throw new EngineException('The image encryption key does not match, or the image is damaged.'); }
                $index++; $total += $length;
                if ($length === 0) { if ($total !== $header['plainBytes'] || fread($in, 1) !== '') { throw new EngineException('Image length or final authentication marker is invalid.'); } break; }
                SafeFiles::writeAll($out, $plain);
            }
        } catch (\Throwable $e) { if (is_resource($out)) { fclose($out); } fclose($in); @unlink($plainPath); if ($key !== '' && function_exists('sodium_memzero')) { sodium_memzero($key); } throw $e; }
        fclose($in); fclose($out); if (function_exists('sodium_memzero')) { sodium_memzero($key); }
    }
}
