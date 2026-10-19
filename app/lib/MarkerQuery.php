<?php

/**
 * SQL for /item/list marker filters.
 *
 * Country and product lists used to be applied only when exactly one value
 * was selected, because `$list > 1` is false for a PHP array.
 */
class MarkerQuery
{
    public static function defaultCountries()
    {
        return array('DE', 'AT', 'CH');
    }

    public static function defaultProducts()
    {
        return array('cola', 'bier', 'frohlunder', 'muntermate');
    }

    public static function sql(array $countries, array $products)
    {
        $sql = 'SELECT item.id, item.lat, item.lng, ho.offertype_id AS offertype, item.collmex_address_groups'
            . ' FROM item, item_has_offertype ho, item_has_product hp, product'
            . ' WHERE ho.item_id = item.id AND hp.item_id = item.id AND hp.product_id = product.id'
            . ' AND item.lat IS NOT NULL AND item.lng IS NOT NULL';
        $sql .= self::orEquals('item.country', self::tokens($countries));
        $sql .= self::orEquals('product.name', self::tokens($products));

        return $sql;
    }

    private static function tokens(array $values)
    {
        $out = array();
        foreach ($values as $value) {
            if (is_string($value) && preg_match('/^[A-Za-z0-9]+$/', $value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function orEquals($column, array $values)
    {
        if (count($values) === 0) {
            return ' AND 1=0';
        }

        $parts = array();
        foreach ($values as $value) {
            $parts[] = $column . "='" . $value . "'";
        }

        if (count($parts) === 1) {
            return ' AND ' . $parts[0];
        }

        return ' AND (' . implode(' OR ', $parts) . ')';
    }
}
