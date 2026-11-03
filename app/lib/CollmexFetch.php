<?php

/**
 * Collmex request lines.
 *
 * ADDRESS_GET field 10 asks for contact persons and field 12 asks for
 * inactive addresses. Both stay off for the public map.
 * ADDRESS_GROUPS_GET returns one ADRGRP row per group name.
 * https://www.collmex.de/cgi-bin/cgi.exe?1005,1,help,api_adressen
 * https://www.collmex.de/handbuch_pro.html
 */
class CollmexFetch
{
    public static function addresses($audience)
    {
        if (!MapAudience::isInternal($audience)) {
            return 'ADDRESS_GET';
        }

        return 'ADDRESS_GET;;;;;;;;;1;;1';
    }

    public static function groups()
    {
        return 'ADDRESS_GROUPS_GET';
    }
}
