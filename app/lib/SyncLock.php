<?php

/**
 * One Collmex sync at a time. The handle has to stay open until the process ends.
 */
class SyncLock
{
    const PATH = '/var/lock/landkarte-sync.lock';

    /**
     * false means another sync holds the lock. null means the lock file
     * could not be opened, which must not be treated as "already running".
     *
     * @param string $path
     * @return resource|false|null
     */
    public static function acquire($path)
    {
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        return $handle;
    }
}
