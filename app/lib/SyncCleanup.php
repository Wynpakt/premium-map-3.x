<?php

/**
 * Shops leave the map when Collmex no longer lists them in group 124.
 * Each database id is removed once. An empty fetch deletes nothing.
 */
class SyncCleanup
{
    const MAP_GROUP = '124';

    public static function keptIds($rows)
    {
        $kept = array();
        if (!is_array($rows)) {
            return $kept;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['address_id'])) {
                continue;
            }
            $groups = explode(',', (string) (isset($row['collmex_address_groups']) ? $row['collmex_address_groups'] : ''));
            if (in_array(self::MAP_GROUP, $groups, true)) {
                $kept[(string) (int) $row['address_id']] = true;
            }
        }
        return $kept;
    }
}
