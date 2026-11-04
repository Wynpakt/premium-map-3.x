<?php

/**
 * The fields one sync pass would write. Equal rows are not written again.
 * Rewriting every shop every hour held the database lock longer than the cron interval.
 */
class SyncRow
{
    public static function same(array $stored, array $incoming)
    {
        return self::pack($stored) === self::pack($incoming);
    }

    private static function pack(array $row)
    {
        $contacts = array();
        if (isset($row['contacts']) && is_array($row['contacts'])) {
            foreach ($row['contacts'] as $contact) {
                if (!is_array($contact)) {
                    continue;
                }
                $contacts[] = array(
                    (int) (isset($contact['collmex_id']) ? $contact['collmex_id'] : 0),
                    self::text($contact, 'name'),
                    self::text($contact, 'department'),
                    self::text($contact, 'phone'),
                    self::text($contact, 'phone2'),
                    self::text($contact, 'email'),
                );
            }
        }
        usort($contacts, function ($left, $right) {
            return strcmp(implode("\0", $left), implode("\0", $right));
        });

        $offers = self::ints(isset($row['offertypes']) ? $row['offertypes'] : array());
        $products = self::ints(isset($row['products']) ? $row['products'] : array());
        sort($offers);
        sort($products);

        return array(
            self::text($row, 'name'),
            self::text($row, 'street'),
            self::text($row, 'zip'),
            self::text($row, 'city'),
            self::text($row, 'country'),
            self::text($row, 'web'),
            self::text($row, 'email'),
            self::text($row, 'phone'),
            self::text($row, 'phone2'),
            self::text($row, 'department'),
            self::text($row, 'firstname'),
            self::text($row, 'surname'),
            self::text($row, 'note'),
            (int) (isset($row['inactive']) ? $row['inactive'] : 0),
            (int) (isset($row['no_mailings']) ? $row['no_mailings'] : 0),
            self::text($row, 'groups'),
            $offers,
            $products,
            $contacts,
        );
    }

    private static function ints($values)
    {
        $out = array();
        if (!is_array($values)) {
            return $out;
        }
        foreach ($values as $value) {
            $out[] = (int) $value;
        }

        return $out;
    }

    private static function text(array $row, $key)
    {
        return isset($row[$key]) ? (string) $row[$key] : '';
    }
}
