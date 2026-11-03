<?php

/**
 * Popup payload. The public map keeps the fields it already published.
 * Staff fields are added only for an internal host.
 */
class ShopDetail
{
    public static function payload($audience, array $shop, array $groupNames)
    {
        $id = (int) $shop['id'];
        $out = array(
            'id' => $id,
            'name' => (string) $shop['name'],
            'street' => self::street($audience, $shop),
            'products' => array_values($shop['products']),
            'offertypes' => array_values($shop['offertypes']),
            'city' => (string) $shop['city'],
            'zip' => (string) $shop['zip'],
            'web' => (string) $shop['web'],
            'email' => (string) $shop['email'],
            'uri' => $id,
        );
        if (!MapAudience::isInternal($audience)) {
            return $out;
        }

        $out['phone'] = self::text($shop, 'phone');
        $out['phone2'] = self::text($shop, 'phone2');
        $out['department'] = self::text($shop, 'department');
        $out['person'] = self::person($shop);
        $out['note'] = self::text($shop, 'note');
        $out['inactive'] = self::text($shop, 'inactive') === '1';
        $out['no_mailings'] = self::text($shop, 'no_mailings') === '1';
        $out['collmex_id'] = (int) self::text($shop, 'collmex_address_id');
        $out['groups'] = AddressGroup::labels(
            isset($shop['collmex_address_groups']) ? $shop['collmex_address_groups'] : '',
            $groupNames
        );
        $out['contacts'] = self::contacts(isset($shop['contacts']) ? $shop['contacts'] : array());

        return $out;
    }

    private static function street($audience, array $shop)
    {
        $speakerOnly = !empty($shop['speaker_only']);
        if ($speakerOnly && !MapAudience::isInternal($audience)) {
            return '';
        }

        return (string) $shop['street'];
    }

    private static function person(array $shop)
    {
        $person = trim(self::text($shop, 'firstname') . ' ' . self::text($shop, 'surname'));
        if ($person === '' || $person === trim((string) $shop['name'])) {
            return '';
        }

        return $person;
    }

    private static function contacts(array $contacts)
    {
        $out = array();
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $row = array(
                'name' => self::text($contact, 'name'),
                'department' => self::text($contact, 'department'),
                'phone' => self::text($contact, 'phone'),
                'phone2' => self::text($contact, 'phone2'),
                'email' => self::text($contact, 'email'),
            );
            if ($row['name'] === '' && $row['phone'] === '' && $row['phone2'] === '' && $row['email'] === '') {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    private static function text(array $row, $key)
    {
        return isset($row[$key]) ? trim((string) $row[$key]) : '';
    }
}
