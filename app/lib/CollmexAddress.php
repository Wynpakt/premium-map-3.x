<?php

/**
 * Maps one CMXADR CSV row to the fields the sync stores.
 *
 * Indexes follow the Collmex address record (0-based). The positions are the
 * Satzbeschreibung Adresse in the Collmex manual: department 9, note 13,
 * inactive 14, phone 16, no-mailings 26, phone 2 is 27, url 29, groups 32.
 * https://www.collmex.de/handbuch_pro.html
 *
 * Address type 4 is a contact person and is exported only. Field 2 stays the
 * address the contact belongs to. Field 38 is that contact's own address
 * number ("Adressnummer des Ansprechpartners").
 */
class CollmexAddress
{
    const CONTACT_TYPE = '4';

    public static function fromRow(array $row)
    {
        if (!isset($row[0]) || $row[0] !== 'CMXADR') {
            return null;
        }

        return array(
            'address_id' => self::at($row, 1),
            'address_type' => self::at($row, 2),
            'firstname' => self::at($row, 5),
            'surname' => self::at($row, 6),
            'name' => self::at($row, 7),
            'department' => self::at($row, 8),
            'street' => self::at($row, 9),
            'zip' => self::at($row, 10),
            'city' => self::at($row, 11),
            'note' => self::at($row, 12),
            'inactive' => self::at($row, 13),
            'country' => self::at($row, 14),
            'email' => self::at($row, 17),
            'phone' => self::at($row, 15),
            'phone2' => self::at($row, 26),
            'no_mailings' => self::at($row, 25),
            'web' => self::at($row, 28),
            'address_group' => self::at($row, 31),
            'contact_address_id' => self::at($row, 37),
        );
    }

    public static function isContact(array $mapped)
    {
        return isset($mapped['address_type'])
            && (string) $mapped['address_type'] === self::CONTACT_TYPE;
    }

    /**
     * @param array<int, array> $mappedRows
     * @return array<int, array>
     */
    public static function shops(array $mappedRows)
    {
        $shops = array();
        foreach ($mappedRows as $row) {
            if (!is_array($row) || self::isContact($row)) {
                continue;
            }
            $shops[] = $row;
        }

        return $shops;
    }

    /**
     * Contacts keyed by the shop's Collmex address number.
     *
     * @param array<int, array> $mappedRows
     * @return array<int, array<int, array>>
     */
    public static function contactsFor(array $mappedRows)
    {
        $byParent = array();
        foreach ($mappedRows as $row) {
            if (!is_array($row) || !self::isContact($row)) {
                continue;
            }
            $contact = self::contact($row);
            if ($contact['parent_id'] <= 0 || self::contactIsEmpty($contact)) {
                continue;
            }
            $byParent[$contact['parent_id']][] = $contact;
        }

        return $byParent;
    }

    public static function contact(array $mapped)
    {
        $name = trim(self::text($mapped, 'firstname') . ' ' . self::text($mapped, 'surname'));
        if ($name === '') {
            $name = trim(self::text($mapped, 'name'));
        }

        return array(
            'parent_id' => (int) self::text($mapped, 'address_id'),
            'collmex_id' => (int) self::text($mapped, 'contact_address_id'),
            'name' => $name,
            'department' => trim(self::text($mapped, 'department')),
            'phone' => trim(self::text($mapped, 'phone')),
            'phone2' => trim(self::text($mapped, 'phone2')),
            'email' => ShopEmail::stored(self::text($mapped, 'email')),
        );
    }

    private static function contactIsEmpty(array $contact)
    {
        return $contact['name'] === ''
            && $contact['phone'] === ''
            && $contact['phone2'] === ''
            && $contact['email'] === '';
    }

    private static function text(array $row, $key)
    {
        return isset($row[$key]) ? (string) $row[$key] : '';
    }

    private static function at(array $row, $index)
    {
        return isset($row[$index]) ? (string) $row[$index] : '';
    }
}
