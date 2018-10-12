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
     * Determines if the a type by the given name is registered.
     *
     * @param string $typeName
     *
     * @return bool
     */
    public function has(string $typeName) : bool;
    
    /**
     * Register a type in the registry, optionally using the provided name.
     *
     * @param Type        $type
     * @param null|string $name
     *
     * @return Type The newly registered type.
     */
    public function register(Type $type, ?string $name = null) : Type;
}
