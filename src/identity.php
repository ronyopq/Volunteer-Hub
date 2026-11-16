<?php
declare(strict_types=1);
/** RFC-aligned 26-char Crockford ULID with a safely separated entity prefix. */
function praan_uid(string $prefix): string {
    if (!preg_match('/^[A-Z]{3,5}$/D', $prefix)) throw new InvalidArgumentException('Invalid UID prefix');
    $millis = (int)floor(microtime(true) * 1000);
    // 48-bit big-endian time followed by 80 cryptographically random bits.
    $bytes = pack('N', ($millis >> 16) & 0xffffffff) . pack('n', $millis & 0xffff) . random_bytes(10);
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $bits = '00';
    foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    $encoded = '';
    for ($i=0; $i<130; $i+=5) $encoded .= $alphabet[bindec(substr($bits, $i, 5))];
    return $prefix . '-' . $encoded;
}
function praan_uid_valid(string $uid, string $prefix): bool {
    return (bool)preg_match('/^'.preg_quote($prefix,'/').'-[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $uid);
}
