<?php

/**
 * When the routed inbox is rejected, feedback mail goes to the collective contact address once.
 */
class MailFallback
{
    const ADDRESS = 'kontakt@premium-kollektiv.de';

    public static function isRecipientRejected($error)
    {
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        return strpos($message, 'Recipient address rejected') !== false;
    }

    public static function replacement($rejectedAddress)
    {
        $rejectedAddress = trim((string) $rejectedAddress);
        if ($rejectedAddress === '' || strcasecmp($rejectedAddress, self::ADDRESS) === 0) {
            return null;
        }

        return self::ADDRESS;
    }

    public static function notice($rejectedAddress)
    {
        return "\n\nDie Adresse " . trim((string) $rejectedAddress) . " wurde vom Mailserver abgelehnt.\n";
    }
}
