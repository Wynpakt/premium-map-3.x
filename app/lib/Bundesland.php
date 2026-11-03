<?php

/**
 * German postcode ranges to a federal state.
 *
 * Ranges follow the published routing list (gist westberliner/8c527f7390621bafa01f).
 * A border code can sit in two states; fromZip returns both, and the filter
 * keeps the pin when either state is selected. Codes outside the list match
 * no state. Selecting every state does not hide Austria, Switzerland or the rest.
 */
class Bundesland
{
    public static function codes()
    {
        return array(
            'BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV',
            'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH',
        );
    }

    /**
     * @return string[]
     */
    public static function fromZip($zip)
    {
        $zip = trim((string) $zip);
        if (!preg_match('/^\d{4,5}$/', $zip)) {
            return array();
        }
        $number = (int) $zip;
        $found = array();
        foreach (self::ranges() as $range) {
            if ($number >= $range[0] && $number <= $range[1]) {
                $found[$range[2]] = $range[2];
            }
        }

        return array_values($found);
    }

    /**
     * Empty string: every state is selected, so the predicate is left off.
     * "1=0": nothing valid was selected.
     */
    public static function sql(array $codes)
    {
        $wanted = array();
        foreach ($codes as $code) {
            if (in_array($code, self::codes(), true)) {
                $wanted[$code] = true;
            }
        }
        if (count($wanted) === 0) {
            return '1=0';
        }
        if (count($wanted) === count(self::codes())) {
            return '';
        }

        $parts = array();
        foreach (self::ranges() as $range) {
            if (!isset($wanted[$range[2]])) {
                continue;
            }
            $parts[] = 'CAST(item.zip AS UNSIGNED) BETWEEN ' . $range[0] . ' AND ' . $range[1];
        }

        return "item.country='DE' AND (" . implode(' OR ', $parts) . ')';
    }

    private static function ranges()
    {
        return array(
        array(1001, 1936, 'SN'),
        array(1941, 1998, 'BB'),
        array(2601, 2999, 'SN'),
        array(3001, 3253, 'BB'),
        array(4001, 4579, 'SN'),
        array(4581, 4639, 'TH'),
        array(4641, 4889, 'SN'),
        array(4891, 4938, 'BB'),
        array(6001, 6548, 'ST'),
        array(6551, 6578, 'TH'),
        array(6601, 6928, 'ST'),
        array(7301, 7919, 'TH'),
        array(7919, 7919, 'SN'),
        array(7919, 7919, 'TH'),
        array(7920, 7950, 'TH'),
        array(7951, 7951, 'SN'),
        array(7952, 7952, 'SN'),
        array(7952, 7952, 'TH'),
        array(7953, 7980, 'TH'),
        array(7982, 7982, 'SN'),
        array(7985, 7985, 'SN'),
        array(7985, 7985, 'TH'),
        array(7985, 7989, 'TH'),
        array(8001, 9669, 'SN'),
        array(10001, 14330, 'BE'),
        array(14401, 14715, 'BB'),
        array(14715, 14715, 'ST'),
        array(14723, 16949, 'BB'),
        array(17001, 17256, 'MV'),
        array(17258, 17258, 'BB'),
        array(17258, 17259, 'MV'),
        array(17261, 17291, 'BB'),
        array(17301, 17309, 'MV'),
        array(17309, 17309, 'BB'),
        array(17309, 17321, 'MV'),
        array(17321, 17321, 'BB'),
        array(17321, 17322, 'MV'),
        array(17326, 17326, 'BB'),
        array(17328, 17331, 'MV'),
        array(17335, 17335, 'BB'),
        array(17335, 17335, 'MV'),
        array(17337, 17337, 'BB'),
        array(17337, 19260, 'MV'),
        array(19271, 19273, 'NI'),
        array(19273, 19273, 'MV'),
        array(19273, 19306, 'MV'),
        array(19307, 19357, 'BB'),
        array(19357, 19417, 'MV'),
        array(20001, 21037, 'HH'),
        array(21039, 21039, 'SH'),
        array(21039, 21170, 'HH'),
        array(21202, 21449, 'NI'),
        array(21451, 21521, 'SH'),
        array(21522, 21522, 'NI'),
        array(21524, 21529, 'SH'),
        array(21601, 21789, 'NI'),
        array(22001, 22113, 'HH'),
        array(22113, 22113, 'SH'),
        array(22115, 22143, 'HH'),
        array(22145, 22145, 'HH'),
        array(22145, 22145, 'SH'),
        array(22147, 22786, 'HH'),
        array(22801, 23919, 'SH'),
        array(23921, 23999, 'MV'),
        array(24001, 25999, 'SH'),
        array(26001, 27478, 'NI'),
        array(27483, 27498, 'SH'),
        array(27499, 27499, 'HH'),
        array(27501, 27580, 'HB'),
        array(27607, 27809, 'NI'),
        array(28001, 28779, 'HB'),
        array(28784, 29399, 'NI'),
        array(29401, 29416, 'ST'),
        array(29431, 31868, 'NI'),
        array(32001, 33829, 'NW'),
        array(34001, 34329, 'HE'),
        array(34331, 34353, 'NI'),
        array(34355, 34355, 'HE'),
        array(34355, 34355, 'NI'),
        array(34356, 34399, 'HE'),
        array(34401, 34439, 'NW'),
        array(34441, 36399, 'HE'),
        array(36401, 36469, 'TH'),
        array(37001, 37194, 'NI'),
        array(37194, 37195, 'HE'),
        array(37197, 37199, 'NI'),
        array(37201, 37299, 'HE'),
        array(37301, 37359, 'TH'),
        array(37401, 37649, 'NI'),
        array(37651, 37688, 'NW'),
        array(37689, 37691, 'NI'),
        array(37692, 37696, 'NW'),
        array(37697, 38479, 'NI'),
        array(38481, 38489, 'ST'),
        array(38501, 38729, 'NI'),
        array(38801, 39649, 'ST'),
        array(40001, 48432, 'NW'),
        array(48442, 48465, 'NI'),
        array(48466, 48477, 'NW'),
        array(48478, 48480, 'NI'),
        array(48481, 48485, 'NW'),
        array(48486, 48488, 'NI'),
        array(48489, 48496, 'NW'),
        array(48497, 48531, 'NI'),
        array(48541, 48739, 'NW'),
        array(49001, 49459, 'NI'),
        array(49461, 49549, 'NW'),
        array(49551, 49849, 'NI'),
        array(50101, 51597, 'NW'),
        array(51598, 51598, 'RP'),
        array(51601, 53359, 'NW'),
        array(53401, 53579, 'RP'),
        array(53581, 53604, 'NW'),
        array(53614, 53619, 'RP'),
        array(53621, 53949, 'NW'),
        array(54181, 55239, 'RP'),
        array(55240, 55252, 'HE'),
        array(55253, 56869, 'RP'),
        array(57001, 57489, 'NW'),
        array(57501, 57648, 'RP'),
        array(58001, 59966, 'NW'),
        array(59969, 59969, 'HE'),
        array(59969, 59969, 'NW'),
        array(60001, 63699, 'HE'),
        array(63701, 63774, 'BY'),
        array(63776, 63776, 'HE'),
        array(63776, 63928, 'BY'),
        array(63928, 63928, 'BW'),
        array(63930, 63939, 'BY'),
        array(64201, 64753, 'HE'),
        array(64754, 64754, 'BW'),
        array(64754, 65326, 'HE'),
        array(65326, 65326, 'RP'),
        array(65327, 65391, 'HE'),
        array(65391, 65391, 'RP'),
        array(65392, 65556, 'HE'),
        array(65558, 65582, 'RP'),
        array(65583, 65620, 'HE'),
        array(65621, 65626, 'RP'),
        array(65627, 65627, 'HE'),
        array(65629, 65629, 'RP'),
        array(65701, 65936, 'HE'),
        array(66001, 66459, 'SL'),
        array(66461, 66509, 'RP'),
        array(66511, 66839, 'SL'),
        array(66841, 67829, 'RP'),
        array(68001, 68312, 'BW'),
        array(68501, 68519, 'HE'),
        array(68520, 68549, 'BW'),
        array(68601, 68649, 'HE'),
        array(68701, 69234, 'BW'),
        array(69235, 69239, 'HE'),
        array(69240, 69429, 'BW'),
        array(69430, 69431, 'HE'),
        array(69434, 69434, 'BW'),
        array(69434, 69434, 'HE'),
        array(69435, 69469, 'BW'),
        array(69479, 69488, 'HE'),
        array(69489, 69502, 'BW'),
        array(69503, 69509, 'HE'),
        array(69510, 69514, 'BW'),
        array(69515, 69518, 'HE'),
        array(70001, 74592, 'BW'),
        array(74594, 74594, 'BY'),
        array(74594, 76709, 'BW'),
        array(76711, 76891, 'RP'),
        array(77601, 79879, 'BW'),
        array(80001, 87490, 'BY'),
        array(87493, 87561, 'BY'),
        array(87571, 87789, 'BY'),
        array(88001, 88099, 'BW'),
        array(88101, 88146, 'BY'),
        array(88147, 88147, 'BW'),
        array(88147, 88179, 'BY'),
        array(88181, 89079, 'BW'),
        array(89081, 89081, 'BY'),
        array(89081, 89085, 'BW'),
        array(89087, 89087, 'BY'),
        array(89090, 89198, 'BW'),
        array(89201, 89449, 'BY'),
        array(89501, 89619, 'BW'),
        array(90001, 96489, 'BY'),
        array(96501, 96529, 'TH'),
        array(97001, 97859, 'BY'),
        array(97861, 97877, 'BW'),
        array(97888, 97892, 'BY'),
        array(97893, 97896, 'BW'),
        array(97896, 97896, 'BY'),
        array(97897, 97900, 'BW'),
        array(97901, 97909, 'BY'),
        array(97911, 97999, 'BW'),
        array(98501, 99998, 'TH'),
        );
    }
}
