<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Error;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;

/**
 * TODO: Missing class description.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class ErrorFormatter
{
    
    /**
     * @var int|bool
     */
    private static $debug = false;
    
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
        return self::$debug;
    }
    
    /**
     * @param bool|int $debug
     */
    public static function setDebug($debug) : void
    {
        self::$debug = $debug;
    }
}
