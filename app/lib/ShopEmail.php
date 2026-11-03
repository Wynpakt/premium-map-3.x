<?php

/**
 * The address stored on the map. An empty or invalid Collmex value clears it.
 */
class ShopEmail
{
    public static function stored($raw)
    {
        $email = trim((string) $raw);
        if (strpos($email, ',') !== false) {
            $parts = explode(',', $email);
            $email = trim($parts[0]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $email;
    }
}
