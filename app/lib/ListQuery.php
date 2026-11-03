<?php

/**
 * /item/list expects filter lists as arrays.
 * A bare string is a bad request and yields no markers.
 */
class ListQuery
{
    public static function rejected($query)
    {
        foreach (array('types', 'countries', 'products', 'status', 'visibility', 'states') as $key) {
            if (isset($query[$key]) && !is_array($query[$key])) {
                return true;
            }
        }
        return false;
    }
}
