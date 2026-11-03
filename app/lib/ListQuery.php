<?php

/**
 * /item/list expects types, countries and products as arrays.
 * A bare string is a bad request and yields no markers.
 */
class ListQuery
{
    public static function rejected($query)
    {
        foreach (array('types', 'countries', 'products') as $key) {
            if (isset($query[$key]) && !is_array($query[$key])) {
                return true;
            }
        }
        return false;
    }
}
