<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Events;

/**
 * Event fired when a type is added to the registry.
 *
 * @author Nicolai Agersbæk <na@zitcom.dk>
 *
 * @api
 */
class TypeAdded
{
    
    /**
     * @var object|string
     */
    public $type;
    
    /**
     * @var string
     */
    public $name;
    
    /**
     * @param object|string $type
     * @param string        $name
     */
    public function __construct($type, string $name)
    {
        $this->type = $type;
        $this->name = $name;
    }
}
