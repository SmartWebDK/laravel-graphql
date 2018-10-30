<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Error;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;

/**
 * Provides error formatting with support for setting debug mode.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class ErrorFormatter
{
    
    /**
     * Configuration key used to store debug mode configuration settings.
     */
    public const DEBUG_MODE_CONFIG_KEY = 'graphql.debug_mode';
    
    /**
     * Default value used for debug mode.
     */
    private const DEBUG_MODE_DEFAULT = false;
    
    /**
     * @var int|bool
     */
    private static $debug;
    
    /**
     * @param Error $e
     *
     * @return array
     * @throws \Throwable
     */
    public static function formatError(Error $e) : array
    {
        $error = FormattedError::createFromException($e, self::getDebug());
        
        $previous = $e->getPrevious();
        
        if ($previous && $previous instanceof ValidationError) {
            $error['validation'] = $previous->getValidatorMessages();
        }
        
        return $error;
    }
    
    /**
     * @return bool|int
     */
    public static function getDebug()
    {
        return self::$debug ?? self::$debug = self::getDebugModeFromConfig();
    }
    
    /**
     * @return int|bool
     */
    private static function getDebugModeFromConfig()
    {
        return \config(self::DEBUG_MODE_CONFIG_KEY, self::DEBUG_MODE_DEFAULT);
    }
    
    /**
     * @param bool|int $debug
     */
    public static function setDebug($debug) : void
    {
        self::$debug = $debug;
    }
}
