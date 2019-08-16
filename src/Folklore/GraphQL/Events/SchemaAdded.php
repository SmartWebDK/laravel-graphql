<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Events;

/**
 * Event fired when a schema is added to the registry.
 *
 * @author Nicolai Agersbæk <na@zitcom.dk>
 *
 * @api
 */
class SchemaAdded
{
    
    /**
     * @var array
     */
    public $schema;
    
    /**
     * @var string
     */
    public $name;
    
    /**
     * @param array  $schema
     * @param string $name
     */
    public function __construct(array $schema, string $name)
    {
        $this->schema = $schema;
        $this->name = $name;
    }
}
