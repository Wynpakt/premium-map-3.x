<?php

/**
 * A Collmex reply with no address rows is a failed sync.
 * An empty list must not be treated as "nothing changed".
 */
class SyncRun
{
    public static function emptyFetch($records)
    {
        return !is_array($records) || count($records) === 0;
    }
}
