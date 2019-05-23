<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Events;

/**
 * Event fired when a GraphQL request has been resolved.
 *
 * @author Nicolai Agersbæk <na@zitcom.dk>
 *
 * @api
 */
class RequestResolved
{
    
    /**
     * @var string
     */
    private $schemaName;
    
    /**
     * @var array
     */
    private $errors;
    
    /**
     * @param string $schemaName
     * @param array  $errors
     */
    public function __construct(string $schemaName, array $errors)
    {
        $this->schemaName = $schemaName;
        $this->errors = $errors;
    }
    
    /**
     * @return string
     */
    public function getSchemaName() : string
    {
        return $this->schemaName;
    }
    
    /**
     * @return array
     */
    public function getErrors() : array
    {
        return $this->errors;
    }
}
