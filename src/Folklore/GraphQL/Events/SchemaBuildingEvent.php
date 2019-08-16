<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Events;

/**
 * Event fired when a GraphQL schema is being resolved.
 *
 * @author Nicolai Agersbæk <na@zitcom.dk>
 *
 * @internal
 */
abstract class SchemaBuildingEvent
{
    
    /**
     * @var string
     */
    private $schemaName;
    
    /**
     * @param string $schemaName
     */
    public function __construct(string $schemaName)
    {
        $this->schemaName = $schemaName;
    }
    
    /**
     * @return string
     */
    public function getSchemaName() : string
    {
        return $this->schemaName;
    }
}
