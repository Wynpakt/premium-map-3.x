<?php

/**
 * Marker SQL for the internal map.
 *
 * The public query stays in MarkerQuery: it inner-joins offer type and product
 * and the controller drops groups 16 and 93. Here a full selection of a facet
 * adds no predicate, so addresses with no role, no product or no visibility
 * flag stay on the map. A partial selection narrows.
 */
class InternalFilter
{
    const STATUSES = array('aktuell', 'ehemalig');
    const VISIBILITY = array('oeffentlich', 'verborgen');
    const PRODUCTS = array('cola', 'bier', 'frohlunder', 'muntermate', 'ohne');
    const TYPES = array(
        'laeden' => 1,
        'haendler' => 2,
        'sprecher' => 3,
        'webshop' => 4,
        'ohne' => 0,
    );

    /**
     * @return string|null null when the request has no type list
     */
    public static function sql(array $query)
    {
        if (!isset($query['types']) || !is_array($query['types'])) {
            return null;
        }

        $sql = 'SELECT item.id, item.lat, item.lng, ho.offertype_id AS offertype, item.collmex_address_groups'
            . ' FROM item'
            . ' LEFT JOIN item_has_offertype ho ON ho.item_id = item.id'
            . ' WHERE item.lat IS NOT NULL AND item.lng IS NOT NULL';
        $sql .= self::andWhere(self::typeSql(self::picked($query, 'types', array_keys(self::TYPES))));
        $sql .= self::andWhere(self::countrySql($query));
        $sql .= self::andWhere(self::productSql($query));
        $sql .= self::andWhere(self::statusSql($query));
        $sql .= self::andWhere(self::visibilitySql($query));
        $sql .= self::andWhere(self::stateSql($query));

        return $sql;
    }

    private static function typeSql(array $picked)
    {
        if (self::covers($picked, array_keys(self::TYPES))) {
            return '';
        }
        $parts = array();
        foreach ($picked as $type) {
            if ($type === 'ohne') {
                $parts[] = 'ho.offertype_id IS NULL';
                continue;
            }
            $parts[] = 'ho.offertype_id=' . self::TYPES[$type];
        }
        if (count($parts) === 0) {
            return '1=0';
        }

        return implode(' OR ', $parts);
    }

    private static function countrySql(array $query)
    {
        if (!isset($query['countries'])) {
            return '';
        }
        $codes = array();
        $other = false;
        foreach ($query['countries'] as $value) {
            if ($value === 'OTHER') {
                $other = true;
                continue;
            }
            if (is_string($value) && preg_match('/^[A-Z]{2}$/', $value)) {
                $codes[$value] = $value;
            }
        }
        if ($other && isset($codes['DE'], $codes['AT'], $codes['CH']) && count($codes) === 3) {
            return '';
        }
        $parts = array();
        foreach ($codes as $code) {
            $parts[] = "item.country='" . $code . "'";
        }
        if ($other) {
            $parts[] = "item.country NOT IN ('DE','AT','CH')";
        }
        if (count($parts) === 0) {
            return '1=0';
        }

        return implode(' OR ', $parts);
    }

    private static function productSql(array $query)
    {
        if (!isset($query['products'])) {
            return '';
        }
        $picked = self::picked($query, 'products', self::PRODUCTS);
        if (self::covers($picked, self::PRODUCTS)) {
            return '';
        }
        $names = array();
        foreach ($picked as $name) {
            if ($name !== 'ohne') {
                $names[] = "p.name='" . $name . "'";
            }
        }
        $exists = '';
        if (count($names) > 0) {
            $exists = 'EXISTS (SELECT 1 FROM item_has_product hp, product p'
                . ' WHERE hp.item_id = item.id AND hp.product_id = p.id AND ('
                . implode(' OR ', $names) . '))';
        }
        $none = 'NOT EXISTS (SELECT 1 FROM item_has_product hp WHERE hp.item_id = item.id)';
        if (in_array('ohne', $picked, true) && $exists !== '') {
            return $exists . ' OR ' . $none;
        }
        if (in_array('ohne', $picked, true)) {
            return $none;
        }
        if ($exists !== '') {
            return $exists;
        }

        return '1=0';
    }

    private static function statusSql(array $query)
    {
        if (!isset($query['status'])) {
            return '';
        }
        $picked = self::picked($query, 'status', self::STATUSES);
        if (self::covers($picked, self::STATUSES)) {
            return '';
        }
        if (in_array('ehemalig', $picked, true)) {
            return "FIND_IN_SET('" . AddressGroup::FORMER . "', item.collmex_address_groups)";
        }
        if (in_array('aktuell', $picked, true)) {
            return "FIND_IN_SET('" . AddressGroup::FORMER . "', item.collmex_address_groups) = 0";
        }

        return '1=0';
    }

    private static function visibilitySql(array $query)
    {
        if (!isset($query['visibility'])) {
            return '';
        }
        $picked = self::picked($query, 'visibility', self::VISIBILITY);
        if (self::covers($picked, self::VISIBILITY)) {
            return '';
        }
        $parts = array();
        if (in_array('oeffentlich', $picked, true)) {
            $parts[] = "FIND_IN_SET('" . AddressGroup::PUBLIC_MAP . "', item.collmex_address_groups)";
        }
        if (in_array('verborgen', $picked, true)) {
            $parts[] = "FIND_IN_SET('" . AddressGroup::HIDDEN . "', item.collmex_address_groups)";
        }
        if (count($parts) === 0) {
            return '1=0';
        }

        return implode(' OR ', $parts);
    }

    private static function stateSql(array $query)
    {
        if (!isset($query['states'])) {
            return '';
        }

        return Bundesland::sql(self::picked($query, 'states', Bundesland::codes()));
    }

    private static function picked(array $query, $key, array $allowed)
    {
        $out = array();
        if (!isset($query[$key]) || !is_array($query[$key])) {
            return $out;
        }
        foreach ($query[$key] as $value) {
            if (in_array($value, $allowed, true) && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function covers(array $picked, array $all)
    {
        foreach ($all as $one) {
            if (!in_array($one, $picked, true)) {
                return false;
            }
        }

        return true;
    }

    private static function andWhere($expr)
    {
        if ($expr === '') {
            return '';
        }

        return ' AND (' . $expr . ')';
    }
}
