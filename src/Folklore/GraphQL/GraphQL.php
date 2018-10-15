<?php
declare(strict_types = 1);


namespace Folklore\GraphQL;

use Folklore\GraphQL\Error\ErrorFormatter;
use Folklore\GraphQL\Events\SchemaAdded;
use Folklore\GraphQL\Events\TypeAdded;
use Folklore\GraphQL\Exception\SchemaNotFound;
use Folklore\GraphQL\Exception\TypeNotFound;
use Folklore\GraphQL\Registry\TypeRegistryInterface;
use Folklore\GraphQL\Support\Contracts\TypeConvertible;
use Folklore\GraphQL\Support\PaginationCursorType;
use Folklore\GraphQL\Support\PaginationType;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL as GraphQLBase;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;

/**
 * TODO: Missing class description.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class GraphQL
{
    
    /**
     * @var Application
     */
    protected $app;
    
    /**
     * @var TypeRegistryInterface
     */
    private $registry;
    
    /**
     * @var array
     */
    protected $schemas = [];
    
    /**
     * @var array
     */
    private $types = [];
    
    /**
     * @var Repository
     */
    private $config;
    
    /**
     * @param Application           $app
     * @param TypeRegistryInterface $registry
     */
    public function __construct(Application $app, TypeRegistryInterface $registry)
    {
        $this->app = $app;
        $this->registry = $registry;
        $this->config = $app->make('config');
    }
    
    /**
     * @param null $schema
     *
     * @return array|Schema|mixed|null|string
     * @throws SchemaNotFound
     * @throws TypeNotFound
     */
    public function schema($schema = null)
    {
        if ($schema instanceof Schema) {
            return $schema;
        }
        
        $schemaName = \is_string($schema)
            ? $schema
            : $this->config->get('graphql.schema', 'default');
        
        if (!\is_array($schema) && !isset($this->schemas[$schemaName])) {
            throw new SchemaNotFound('Type ' . $schemaName . ' not found.');
        }
        
        $schema = \is_array($schema)
            ? $schema
            : $this->schemas[$schemaName];
        
        if ($schema instanceof Schema) {
            return $schema;
        }
        
        $schemaQuery = Arr::get($schema, 'query', []);
        $schemaMutation = Arr::get($schema, 'mutation', []);
        $schemaSubscription = Arr::get($schema, 'subscription', []);
        $schemaTypes = Arr::get($schema, 'types', []);
        
        // Get the types from the schema.
        $types = $this->registerSchemaTypes($schemaTypes);
        
        $query = $this->objectType(
            $schemaQuery,
            [
                'name' => 'Query',
            ]
        );
        
        $mutation = $this->objectType(
            $schemaMutation,
            [
                'name' => 'Mutation',
            ]
        );
        
        $subscription = $this->objectType(
            $schemaSubscription,
            [
                'name' => 'Subscription',
            ]
        );
        
        $typeLoader = function (string $name) : Type {
            return $this->registry->get($name);
        };
        
        return new Schema(
            [
                'query'        => $query,
                'mutation'     => !empty($schemaMutation)
                    ? $mutation
                    : null,
                'subscription' => !empty($schemaSubscription)
                    ? $subscription
                    : null,
                'types'        => $types,
                'typeLoader'   => $typeLoader,
            ]
        );
    }
    
    /**
     * @param array $schemaTypes
     *
     * @return array
     * @throws TypeNotFound
     */
    private function registerSchemaTypes(array $schemaTypes) : array
    {
        $registeredTypes = [];
        
        foreach ($schemaTypes as $name => $type) {
            $typeOptions = \is_numeric($name)
                ? []
                : ['name' => $name];
            $objectType = $this->objectType($type, $typeOptions);
            
            $this->registry->register($objectType);
            
            $registeredTypes[] = $objectType;
            
            $this->addType($type, $name);
        }
        
        return $registeredTypes;
    }
    
    /**
     * @param string $name
     *
     * @return ObjectType|Type|mixed|null
     * @throws TypeNotFound
     */
    public function type(string $name)
    {
        if (!isset($this->types[$name])) {
            throw new TypeNotFound('Type ' . $name . ' not found.');
        }
        
        return $this->registry->has($name)
            ? $this->registry->get($name)
            : $this->registry->register($this->resolveType($name));
    }
    
    /**
     * @param string $name
     *
     * @return Type
     *
     * @throws TypeNotFound
     */
    private function resolveType(string $name) : Type
    {
        $class = $this->types[$name];
        $type = $this->objectType(
            $class,
            [
                'name' => $name,
            ]
        );
        
        return $type;
    }
    
    /**
     * @param mixed $type
     * @param array $options
     *
     * @return Type
     * @throws TypeNotFound
     */
    public function objectType($type, ?array $options = null) : Type
    {
        $options = $options ?? [];
        
        // If it's already an ObjectType, just update properties and return it.
        // If it's an array, assume it's an array of fields and build ObjectType
        // from it. Otherwise, build it from a string or an instance.
        
        if ($type instanceof ObjectType) {
            $objectType = $this->updateObjectTypeProperties($type, $options);
        } elseif (\is_array($type)) {
            $objectType = $this->buildObjectTypeFromFields($type, $options);
        } else {
            $objectType = $this->buildObjectTypeFromClass($type, $options);
        }
        
        return $objectType;
    }
    
    /**
     * @param ObjectType $objectType
     * @param array      $options
     *
     * @return ObjectType
     */
    private function updateObjectTypeProperties(ObjectType $objectType, array $options) : ObjectType
    {
        foreach ($options as $key => $value) {
            if (\property_exists($objectType, $key)) {
                $objectType->{$key} = $value;
            }
            if (isset($objectType->config[$key])) {
                $objectType->config[$key] = $value;
            }
        }
        
        return $objectType;
    }
    
    /**
     * @param string     $query
     * @param array|null $variables
     * @param array|null $opts
     *
     * @return array|null
     *
     * @throws SchemaNotFound
     * @throws TypeNotFound
     */
    public function query(string $query, ?array $variables = [], ?array $opts = []) : ?array
    {
        $result = $this->queryAndReturnResult($query, $variables, $opts);
        
        return $this->formatQueryResult($result);
    }
    
    /**
     * @param ExecutionResult $result
     *
     * @return array
     */
    private function formatQueryResult(ExecutionResult $result) : array
    {
        if (!empty($result->errors)) {
            $errorFormatter = $this->getErrorFormatter();
            
            return [
                'data'   => $result->data,
                'errors' => \array_map($errorFormatter, $result->errors),
            ];
        }
        
        return [
            'data' => $result->data,
        ];
    }
    
    /**
     * @return callable
     */
    private function getErrorFormatter() : callable
    {
        return $this->config->get('graphql.error_formatter', [ErrorFormatter::class, 'formatError']);
    }
    
    /**
     * @param string     $query
     * @param array|null $variables
     * @param array|null $opts
     *
     * @return ExecutionResult
     * @throws SchemaNotFound
     * @throws TypeNotFound
     */
    public function queryAndReturnResult(string $query, ?array $variables = [], ?array $opts = []) : ExecutionResult
    {
        $context = array_get($opts, 'context', null);
        $schemaName = array_get($opts, 'schema', null);
        $operationName = array_get($opts, 'operationName', null);
        $defaultFieldResolver = config('graphql.defaultFieldResolver', null);
        
        $additionalResolversSchemaName = \is_string($schemaName)
            ? $schemaName
            : config('graphql.schema', 'default');
        $additionalResolvers = config('graphql.resolvers.' . $additionalResolversSchemaName, []);
        $root = \is_array($additionalResolvers)
            ? array_merge(array_get($opts, 'root', []), $additionalResolvers)
            : $additionalResolvers;
        
        $schema = $this->schema($schemaName);
        
        $result = GraphQLBase::executeQuery(
            $schema,
            $query,
            $root,
            $context,
            $variables,
            $operationName,
            $defaultFieldResolver
        );
        
        return $result;
    }
    
    /**
     * @param $types
     */
    public function addTypes($types) : void
    {
        foreach ($types as $name => $type) {
            $this->addType(
                $type,
                is_numeric($name)
                    ? null
                    : $name
            );
        }
    }
    
    /**
     * @param      $class
     * @param null $name
     */
    public function addType($class, $name = null) : void
    {
        $name = $this->getTypeName($class, $name);
        $this->types[$name] = $class;
        
        event(new TypeAdded($class, $name));
    }
    
    /**
     * @param $name
     * @param $schema
     */
    public function addSchema($name, $schema) : void
    {
        $this->schemas[$name] = $schema;
        
        event(new SchemaAdded($schema, $name));
    }
    
    /**
     * @param $name
     */
    public function clearType($name) : void
    {
        if (isset($this->types[$name])) {
            unset($this->types[$name]);
        }
    }
    
    /**
     * @param $name
     */
    public function clearSchema($name) : void
    {
        if (isset($this->schemas[$name])) {
            unset($this->schemas[$name]);
        }
    }
    
    public function clearTypes() : void
    {
        $this->types = [];
    }
    
    public function clearSchemas() : void
    {
        $this->schemas = [];
    }
    
    /**
     * @return array
     */
    public function getTypes() : array
    {
        return $this->types;
    }
    
    /**
     * @return array
     */
    public function getSchemas() : array
    {
        return $this->schemas;
    }
    
    /**
     * @param       $type
     * @param array $opts
     *
     * @return Type
     * @throws TypeNotFound
     */
    protected function buildObjectTypeFromClass($type, array $opts = []) : Type
    {
        if (!\is_object($type)) {
            $type = $this->app->make($type);
        }
        
        if (!$type instanceof TypeConvertible) {
            throw new TypeNotFound(sprintf('Unable to convert %s to a GraphQL type', \get_class($type)));
        }
        
        foreach ($opts as $key => $value) {
            $type->{$key} = $value;
        }
        
        return $type->toType();
    }
    
    /**
     * @param       $fields
     * @param array $opts
     *
     * @return ObjectType
     */
    protected function buildObjectTypeFromFields($fields, array $opts = []) : ObjectType
    {
        $typeFields = [];
        foreach ($fields as $name => $field) {
            if (\is_string($field)) {
                $field = $this->app->make($field);
                $name = is_numeric($name)
                    ? $field->name
                    : $name;
                $field->name = $name;
                $field = $field->toArray();
            } else {
                $name = is_numeric($name)
                    ? $field['name']
                    : $name;
                $field['name'] = $name;
            }
            $typeFields[$name] = $field;
        }
        
        return new ObjectType(
            array_merge(
                [
                    'fields' => $typeFields,
                ],
                $opts
            )
        );
    }
    
    /**
     * @param      $class
     * @param null $name
     *
     * @return null
     */
    protected function getTypeName($class, $name = null)
    {
        if ($name) {
            return $name;
        }
        
        $type = \is_object($class)
            ? $class
            : $this->app->make($class);
        
        return $type->name;
    }
    
    /**
     * @param ObjectType $type
     *
     * @return mixed
     */
    public function pagination(ObjectType $type)
    {
        // Only add the PaginationCursor when there is a pagination defined.
        if (!isset($this->types['PaginationCursor'])) {
            $this->types['PaginationCursor'] = new PaginationCursorType();
        }
        
        // If the instance type of the given pagination does not exists, create a new one.
        $paginationName = "{$type->name}Pagination";
        
        return $this->registry->has($paginationName)
            ? $this->registry->get($paginationName)
            : $this->registry->register(new PaginationType($type->name), $paginationName);
    }
}
