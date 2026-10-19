<?php

/**
 * Maps one CMXADR CSV row to the fields the sync stores.
 *
 * Indexes follow the Collmex address record (0-based), the same positions the
 * sync already used for street (9), zip (10), city (11), country (14),
 * email (17), url (28) and address group (31). Phone is field 16, index 15.
 */
class CollmexAddress
{
    public static function fromRow(array $row)
    {
        if (!isset($row[0]) || $row[0] !== 'CMXADR') {
            return null;
        }

        return array(
            'address_id' => self::at($row, 1),
            'firstname' => self::at($row, 5),
            'surname' => self::at($row, 6),
            'name' => self::at($row, 7),
            'street' => self::at($row, 9),
            'zip' => self::at($row, 10),
            'city' => self::at($row, 11),
            'country' => self::at($row, 14),
            'phone' => self::at($row, 15),
            'email' => self::at($row, 17),
            'web' => self::at($row, 28),
            'address_group' => self::at($row, 31),
        );
    }

    private static function at(array $row, $index)
    {
        return isset($row[$index]) ? (string) $row[$index] : '';
    }
}
