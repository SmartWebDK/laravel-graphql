<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Registry;

use Folklore\GraphQL\Error\TypeNotFoundException;
use GraphQL\Type\Definition\Type;

/**
 * TODO: Missing interface description.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
interface TypeRegistryInterface
{
    
    /**
     * Get the type registered with the given name.
     *
     * @param string $typeName
     *
     * @throws TypeNotFoundException Thrown if no type of the given name could be located.
     *
     * @return Type
     */
    public function get(string $typeName) : Type;
    
    /**
     * @param Type        $type
     * @param null|string $name
     *
     * @return TypeRegistryInterface
     */
    public function set(Type $type, ?string $name = null) : TypeRegistryInterface;
}
