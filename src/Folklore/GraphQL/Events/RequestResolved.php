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
     * @var string
     */
    private $query;
    
    /**
     * @var array
     */
    private $variables;
    
    /**
     * @var array
     */
    private $errors;
    
    /**
     * @param string $schemaName
     * @param string $query
     * @param array  $variables
     * @param array  $errors
     */
    public function __construct(string $schemaName, string $query, array $variables, array $errors)
    {
        $this->schemaName = $schemaName;
        $this->query = $query;
        $this->variables = $variables;
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
     * @return string
     */
    public function getQuery() : string
    {
        return $this->query;
    }
    
    /**
     * @return array
     */
    public function getVariables() : array
    {
        return $this->variables;
    }
    
    /**
     * @return array
     */
    public function getErrors() : array
    {
        return $this->errors;
    }
}
