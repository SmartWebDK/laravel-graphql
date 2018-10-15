<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Error;

use GraphQL\Error\Error;
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
     * @param Error $e
     *
     * @return array
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
        
        return $error;
    }
}
