<?php

/**
 * The monthly index refresh holds this lock so update geo does not burn
 * geolocate_count while a country Photon is stopped.
 */
class PhotonIndexRefresh
{
    const LOCK_FILE = '/var/lib/photon/refresh.lock';

    public static function isRefreshing($lockFile = null)
    {
        if ($lockFile === null) {
            $lockFile = self::LOCK_FILE;
        }

        return is_file($lockFile);
    }
}
