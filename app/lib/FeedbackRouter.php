<?php

/**
 * Picks the regional feedback inbox from feedback.txt lines "CC;prefix;email".
 * The first matching country and zip prefix wins. Otherwise the default inbox.
 */
class FeedbackRouter
{
    const DEFAULT_EMAIL = 'anna-lilja@premium-kollektiv.de';

    public static function parseLines($contents)
    {
        $contacts = array();
        foreach (preg_split("/\r\n|\n|\r/", (string) $contents) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ';') === false) {
                continue;
            }
            $parts = explode(';', $line);
            if (count($parts) < 3 || trim($parts[2]) === '') {
                continue;
            }
            $contacts[] = array(
                'country' => strtoupper(trim($parts[0])),
                'prefix' => trim($parts[1]),
                'email' => trim($parts[2]),
            );
        }

        return $contacts;
    }

    public static function route(array $contacts, $country, $zip, $defaultEmail = self::DEFAULT_EMAIL)
    {
        $country = strtoupper(trim((string) $country));
        $zip = trim((string) $zip);
        foreach ($contacts as $contact) {
            if ($contact['country'] === $country && self::startsWith($zip, $contact['prefix'])) {
                return $contact['email'];
            }
        }

        return $defaultEmail;
    }

    public static function startsWith($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }

        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}
