<?php

/**
 * One codebase, two deployments. Missing or unknown audience is the public map.
 * The installer writes this into config.php and remembers it in /etc/landkarte/mode.
 */
class MapAudience
{
    const PUBLIC_MAP = 'public';
    const INTERNAL_MAP = 'internal';

    public static function normalize($audience)
    {
        return $audience === self::INTERNAL_MAP ? self::INTERNAL_MAP : self::PUBLIC_MAP;
    }

    public static function isInternal($audience)
    {
        return self::normalize($audience) === self::INTERNAL_MAP;
    }

    public static function fromConfig($config)
    {
        if (is_object($config) && isset($config->audience)) {
            return self::normalize((string) $config->audience);
        }
        if (is_array($config) && isset($config['audience'])) {
            return self::normalize((string) $config['audience']);
        }

        return self::PUBLIC_MAP;
    }

    /**
     * Public map stores group 124 only. Internal map stores every address
     * that already has a place and a Collmex id.
     *
     * @param array $groupIds
     */
    public static function storesAddress($audience, array $groupIds)
    {
        if (self::isInternal($audience)) {
            return true;
        }
        foreach ($groupIds as $id) {
            if ((string) $id === SyncCleanup::MAP_GROUP) {
                return true;
            }
        }

        return false;
    }

    public static function keepsWithoutOfferType($audience)
    {
        return self::isInternal($audience);
    }

    public static function storesPhone($audience)
    {
        return self::isInternal($audience);
    }

    public static function clearsStoredPhones($audience)
    {
        return !self::isInternal($audience);
    }

    /**
     * @return string|false
     */
    public static function patchConfigText($text, $audience)
    {
        $line = "'audience' => " . var_export(self::normalize($audience), true);
        if (preg_match("/'audience'\\s*=>\\s*'[^']*'/", $text)) {
            return preg_replace("/'audience'\\s*=>\\s*'[^']*'/", $line, $text, 1);
        }
        $at = strpos($text, "'collmex'");
        if ($at === false) {
            return false;
        }

        return substr($text, 0, $at) . '    ' . $line . ",\n    " . substr($text, $at);
    }
}
