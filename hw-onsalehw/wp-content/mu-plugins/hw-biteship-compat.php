<?php
/**
 * Plugin Name: HW Biteship Compatibility
 * Description: Silences legacy Biteship dynamic property warnings without touching vendor files.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'hw_biteship_intercept_dynamic_property_notices', 0);

/**
 * Intercept deprecated dynamic property notices emitted by Biteship legacy classes.
 *
 * @return void
 */
function hw_biteship_intercept_dynamic_property_notices()
{
    if (defined('HW_BITESHIP_ERROR_HANDLER_ATTACHED')) {
        return;
    }

    define('HW_BITESHIP_ERROR_HANDLER_ATTACHED', true);

    $previous = set_error_handler(
        static function ($errno, $errstr, $errfile = null, $errline = null, $errcontext = null) {
            if (E_DEPRECATED === $errno && is_string($errstr) && hw_biteship_message_matches($errstr)) {
                return true;
            }

            return false;
        },
        E_DEPRECATED
    );

    if (null !== $previous) {
        set_error_handler(
            static function ($errno, $errstr, $errfile = null, $errline = null, $errcontext = null) use ($previous) {
                if (E_DEPRECATED === $errno && is_string($errstr) && hw_biteship_message_matches($errstr)) {
                    return true;
                }

                return (bool) call_user_func($previous, $errno, $errstr, $errfile, $errline, $errcontext);
            },
            E_DEPRECATED
        );
    }
}

/**
 * Detect whether a deprecation message is produced by the Biteship plugin.
 *
 * @param string $message Error message.
 * @return bool
 */
function hw_biteship_message_matches($message)
{
    if (function_exists('str_contains')) {
        return str_contains($message, 'Biteship');
    }

    return false !== strpos($message, 'Biteship');
}
