<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Error;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Language\SourceLocation;

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
        $error = [
            'message' => $e->getMessage(),
        ];
        
        $error['locations'] = \array_map(
            function (SourceLocation $location) {
                return $location->toArray();
            },
            $e->getLocations()
        );
        
        $previous = $e->getPrevious();
        
        if ($previous && $previous instanceof ValidationError) {
            $error['validation'] = $previous->getValidatorMessages();
        }
        
        return FormattedError::addDebugEntries($error, $e, self::getDebug());
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
