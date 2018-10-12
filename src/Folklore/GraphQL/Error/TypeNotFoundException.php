<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Error;

/**
 * Thrown when a type could not be located in a registry.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class TypeNotFoundException extends \LogicException implements ExceptionInterface
{
    
    /**
     * @var string
     */
    private $typeName;
    
    /**
     * @param null|string $typeName
     */
    public function __construct(string $typeName)
    {
        parent::__construct("Unable to locate type '{$typeName}'");
        
        $this->typeName = $typeName;
    }
    
    /**
     * @return string
     */
    public function getTypeName() : ?string
    {
        return $this->typeName;
    }
}
