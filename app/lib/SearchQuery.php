<?php

/**
 * Marker search by name, city, zip or street. The keyword is bound, not concatenated.
 */
class SearchQuery
{
    const LIMIT = 20;

    public static function sql()
    {
        return 'SELECT item.* FROM item'
            . ' WHERE (item.name LIKE :q OR item.city LIKE :q OR item.zip LIKE :q OR item.street LIKE :q)'
            . ' AND EXISTS (SELECT 1 FROM item_has_offertype ho WHERE ho.item_id = item.id)'
            . ' AND EXISTS (SELECT 1 FROM item_has_product hp WHERE hp.item_id = item.id)'
            . ' LIMIT ' . (int) self::LIMIT;
    }

    public static function like($keyword)
    {
        $keyword = trim((string) $keyword);
        $keyword = str_replace(array('%', '_', '\\'), '', $keyword);

        return '%' . $keyword . '%';
    }
}
