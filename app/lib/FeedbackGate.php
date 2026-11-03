<?php

/**
 * Stops the public feedback form from sending unlimited mail.
 * Five messages per address per hour. A body over 4000 characters is refused.
 */
class FeedbackGate
{
    const MAX_CHARS = 4000;
    const MAX_SENDS = 5;
    const WINDOW_SECONDS = 3600;

    public static function tooLong($text)
    {
        return strlen((string) $text) > self::MAX_CHARS;
    }

    public static function allow($timestamps, $now)
    {
        $fresh = array();
        foreach ($timestamps as $stamp) {
            $stamp = (int) $stamp;
            if ($stamp > (int) $now - self::WINDOW_SECONDS) {
                $fresh[] = $stamp;
            }
        }
        if (count($fresh) >= self::MAX_SENDS) {
            return array(false, $fresh);
        }
        $fresh[] = (int) $now;
        return array(true, $fresh);
    }

    public static function remember($ip, $now, $dir)
    {
        $path = rtrim((string) $dir, '/') . '/landkarte-feedback-' . hash('sha256', (string) $ip);
        $stamps = array();
        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                $stamps = $lines;
            }
        }
        list($ok, $stamps) = self::allow($stamps, $now);
        if ($ok) {
            file_put_contents($path, implode("\n", $stamps) . "\n", LOCK_EX);
        }
        return $ok;
    }
}
