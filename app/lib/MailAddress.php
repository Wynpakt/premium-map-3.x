<?php

/**
 * Turns smtp.from (string, [email => name], or an object with toArray) into one address.
 */
class MailAddress
{
    public static function parse($from)
    {
        if (is_string($from)) {
            $from = trim($from);
            if (strpos($from, '@') !== false) {
                return array('email' => $from, 'name' => '');
            }
            return null;
        }

        if (is_object($from) && method_exists($from, 'toArray')) {
            $from = $from->toArray();
        }

        if (!is_array($from)) {
            return null;
        }

        foreach ($from as $email => $name) {
            if (is_string($email) && strpos($email, '@') !== false) {
                return array('email' => $email, 'name' => trim((string) $name));
            }
        }

        if (isset($from[0]) && is_string($from[0]) && strpos($from[0], '@') !== false) {
            return array('email' => trim($from[0]), 'name' => '');
        }

        return null;
    }
}
