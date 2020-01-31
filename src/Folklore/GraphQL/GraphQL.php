<?php
/** @noinspection EfferentObjectCouplingInspection */
declare(strict_types = 1);


namespace Folklore\GraphQL;

use Folklore\GraphQL\Error\ErrorFormatter;
use Folklore\GraphQL\Error\InvalidConfigError;
use Folklore\GraphQL\Events\SchemaAdded;
use Folklore\GraphQL\Events\SchemaBuildingCompleted;
use Folklore\GraphQL\Events\SchemaBuildingStarted;
use Folklore\GraphQL\Events\TypeAdded;
use Folklore\GraphQL\Exception\SchemaNotFound;
use Folklore\GraphQL\Exception\TypeNotFound;
use Folklore\GraphQL\Registry\TypeRegistryInterface;
use Folklore\GraphQL\Support\Contracts\TypeConvertible;
use Folklore\GraphQL\Support\PaginationCursorType;
use Folklore\GraphQL\Support\PaginationType;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL as GraphQLBase;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\Utils;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;

/**
 * Provides centralized access to GraphQL schemas and types.
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
     * @var Dispatcher
     */
    private $dispatcher;
    
    /**
     * @param Application           $app
     * @param TypeRegistryInterface $registry
     * @param Dispatcher            $dispatcher
     */
    public function __construct(Application $app, TypeRegistryInterface $registry, Dispatcher $dispatcher)
    {
        $this->app = $app;
        $this->registry = $registry;
        $this->config = $app->make('config');
        $this->dispatcher = $dispatcher;
    }
    
    /**
     * @param array|string|Schema|null $schema
     *
     * @return array|Schema|mixed|null|string
     *
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
        
        $schema = $this->getSchemaArray($schema, $schemaName);
        
        if ($schema instanceof Schema) {
            return $schema;
        }
        
        $this->dispatcher->dispatch(new SchemaBuildingStarted($schemaName));
        $built = $this->buildSchema($schema);
        $this->dispatcher->dispatch(new SchemaBuildingCompleted($schemaName));
        
        return $built;
    }
    
    /**
     * @param array|string|Schema $schema
     * @param string              $schemaName
     *
     * @return array|Schema
     *
     * @throws SchemaNotFound
     */
    private function getSchemaArray($schema, string $schemaName)
    {
        if (!\is_array($schema) && !isset($this->schemas[$schemaName])) {
            throw new SchemaNotFound("Type {$schemaName} not found.");
        }
        
        return \is_array($schema)
            ? $schema
            : $this->schemas[$schemaName];
    }
    
    /**
     * @param array $schemaConfig
     *
     * @return Schema
     *
     * @throws TypeNotFound
     */
    private function buildSchema(array $schemaConfig) : Schema
    {
        $schemaQuery = Arr::get($schemaConfig, 'query', []);
        $schemaMutation = Arr::get($schemaConfig, 'mutation', []);
        $schemaSubscription = Arr::get($schemaConfig, 'subscription', []);
        $schemaTypes = Arr::get($schemaConfig, 'types', []);
        
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
            
            $this->addType($type);
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
     * @return array
     *
     * @throws SchemaNotFound
     * @throws TypeNotFound
     */
    public function query(string $query, ?array $variables = null, ?array $opts = null) : array
    {
        $variables = $variables ?? [];
        $opts = $opts ?? [];
        
        return $this->queryAndReturnResult($query, $variables, $opts)
                    ->setErrorFormatter($this->getErrorFormatter())
                    ->toArray();
    }
    
    /**
     * @return callable
     */
    private function getErrorFormatter() : callable
    {
        static $defaultFormatter = [ErrorFormatter::class, 'formatError'];
        
        $formatter = $this->config->get('graphql.error_formatter', $defaultFormatter);
        
        if (!\is_callable($formatter)) {
            throw new InvalidConfigError(
                \sprintf(
                    'The configured error formatter must be a callable. Was: %s',
                    Utils::printSafe($formatter)
                )
            );
        }
        
        return $formatter;
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
    public function queryAndReturnResult(string $query, ?array $variables = null, ?array $opts = null) : ExecutionResult
    {
        $variables = $variables ?? [];
        $opts = $opts ?? [];
        
        $context = array_get($opts, 'context', null);
        $schemaName = array_get($opts, 'schema', null);
        $operationName = array_get($opts, 'operationName', null);
        $defaultFieldResolver = $this->getDefaultFieldResolver();
        
        $additionalResolversSchemaName = \is_string($schemaName)
            ? $schemaName
            : $this->config->get('graphql.schema', 'default');
        $additionalResolvers = $this->config->get('graphql.resolvers.' . $additionalResolversSchemaName, []);
        $root = \is_array($additionalResolvers)
            ? \array_merge(array_get($opts, 'root', []), $additionalResolvers)
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
     * @return callable|null
     */
    private function getDefaultFieldResolver() : ?callable
    {
        $resolver = $this->config->get('graphql.defaultFieldResolver');
        
        return \is_string($resolver)
            ? \resolve($resolver)
            : $resolver;
    }
    
    /**
     * @param $types
     */
    public function addTypes($types) : void
    {
        foreach ($types as $name => $type) {
            $this->addType(
                $type,
                \is_numeric($name)
                    ? null
                    : $name
            );
        }
    }
    
    /**
     * @param object|string $class
     * @param string|null   $name
     */
    public function addType($class, ?string $name = null) : void
    {
        $name = $this->getTypeName($class, $name);
        $this->types[$name] = $class;
        
        event(new TypeAdded($class, $name));
    }
    
    /**
     * @param string $name
     * @param array  $schema
     */
    public function addSchema(string $name, array $schema) : void
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
     * @param mixed      $type
     * @param array|null $config
     *
     * @return Type
     * @throws TypeNotFound
     */
    protected function buildObjectTypeFromClass($type, array $config = null) : Type
    {
        $config = $config ?? [];
        
        if (!\is_object($type)) {
            $type = $this->app->make($type);
        }
        
        if (!$type instanceof TypeConvertible) {
            throw new TypeNotFound(sprintf('Unable to convert %s to a GraphQL type', \get_class($type)));
        }
        
        foreach ($config as $key => $value) {
            $type->{$key} = $value;
        }
        
        return $type->toType();
    }
    
    /**
     * @param mixed      $fields
     * @param array|null $config
     *
     * @return ObjectType
     */
    protected function buildObjectTypeFromFields($fields, array $config = null) : ObjectType
    {
        $config = $config ?? [];
        
        $typeFields = [];
        
        foreach ($fields as $name => $field) {
            if (\is_string($field)) {
                $field = $this->app->make($field);
                $name = is_numeric($name)
                    ? $field->name
                    : $name;
                $field->name = $name;
                $field = $field->toArray();
            } elseif ($field instanceof FieldDefinition) {
                $name = $field->name;
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
                $config
            )
        );
    }
    
    /**
     * @param mixed       $class
     * @param string|null $name
     *
     * @return string
     */
    protected function getTypeName($class, ?string $name = null) : string
    {
        return $name
            ?: $this->getTypeFromClass($class)->name;
    }
    
    /**
     * @param $class
     *
     * @return mixed
     */
    private function getTypeFromClass($class)
    {
        return \is_object($class)
            ? $class
            : $this->app->make($class);
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
