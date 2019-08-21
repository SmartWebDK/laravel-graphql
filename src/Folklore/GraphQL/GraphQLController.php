<?php
declare(strict_types = 1);


namespace Folklore\GraphQL;

use Folklore\GraphQL\Error\ErrorFormatter;
use Folklore\GraphQL\Error\InvalidConfigError;
use Folklore\GraphQL\Events\RequestResolved;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Schema;
use GraphQL\Utils\Utils;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller handling HTTP requests for the GraphQL end-points.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class GraphQLController extends Controller
{
    
    /**
     * @var Repository
     */
    private $config;
    
    /**
     * @var ResponseFactory
     */
    private $responseFactory;
    
    /**
     * @var Factory
     */
    private $viewFactory;
    
    /**
     * @var Dispatcher
     */
    private $dispatcher;
    
    /**
     * @param Request $request
     * @param Repository      $config
     * @param ResponseFactory $responseFactory
     * @param Factory         $viewFactory
     * @param Dispatcher      $dispatcher
     */
    public function __construct(
        Request $request,
        Repository $config,
        ResponseFactory $responseFactory,
        Factory $viewFactory,
        Dispatcher $dispatcher
    ) {
        $this->config = $config;
        $this->responseFactory = $responseFactory;
        $this->viewFactory = $viewFactory;
        $this->dispatcher = $dispatcher;
        
        $route = $request->route();
        
        // Prevent schema middleware from being applied to 'graphiql' routes
        if ($this->routeIsGraphiQL($route)) {
            return;
        }
        
        $schema = $this->getSchemaFromRoute($route);
        
        $middleware = $this->config->get('graphql.middleware_schema.' . $schema, null);
        
        if ($middleware) {
            $this->middleware($middleware);
        }
    }
    
    /**
     * Prevent schema middleware from being applied to 'graphiql' routes
     *
     * Be careful !! For Lumen < 5.6, Request->route() returns an array with
     * 'as' key for named routes
     *
     * @see https://github.com/laravel/lumen-framework/issues/119
     * @see https://laravel.com/api/5.5/Illuminate/Http/Request.html#method_route
     *
     * @param Route|object|string $route
     *
     * @return bool
     */
    private function routeIsGraphiQL($route) : bool
    {
        $routeName = $this->getRouteName($route);
        
        return $routeName !== null && \preg_match('/^graphql\.graphiql/', $routeName);
    }
    
    /**
     * @param Route|object|string $route
     *
     * @return null|string
     */
    private function getRouteName($route) : ?string
    {
        return \is_object($route)
            ? $route->getName()
            : $this->getRouteArrayName($route);
    }
    
    /**
     * @param mixed $route
     *
     * @return null|string
     */
    private function getRouteArrayName($route) : ?string
    {
        return \is_array($route)
            ? $route['as'] ?? null
            : null;
    }
    
    /**
     * @param Route|object|string $route
     *
     * @return string
     */
    private function getSchemaFromRoute($route) : string
    {
        $defaultSchema = $this->config->get('graphql.schema');
        $prefix = $this->config->get('graphql.prefix');
        
        $schema = $defaultSchema;
        
        if (\is_array($route)) {
            $schema = array_get($route, '2.' . $prefix . '_schema', $defaultSchema);
        } elseif (\is_object($route)) {
            $schema = $route->parameter($prefix . '_schema', $defaultSchema);
        }
        
        return $schema;
    }
    
    /**
     * @param ServerRequestInterface $request
     * @param null|string            $graphql_schema
     *
     * @return JsonResponse
     *
     * @throws Exception\SchemaNotFound
     * @throws Exception\TypeNotFound
     */
    public function query(ServerRequestInterface $request, ?string $graphql_schema = null) : JsonResponse
    {
        /** @var ConnectionInterface $connection */
        $connection = Container::getInstance()->get(ConnectionInterface::class);
        
        $graphQLSchema = $graphql_schema ?? $this->config->get('graphql.schema');
        
        $server = new StandardServer($this->getServerConfig($graphQLSchema));
        
        $isBatch = !$request->has('query');
        $inputs = $request->all();
        
        $connection->beginTransaction();
        
        $data = $server->executePsrRequest($request);
//        $server->handleRequest($request);

//        if (!$isBatch) {
//            $data = $this->executeQuery($graphQLSchema, $inputs);
//        } else {
//            $data = [];
//            foreach ($inputs as $input) {
//                $data[] = $this->executeQuery($graphQLSchema, $input);
//            }
//        }
        
        $headers = $this->config->get('graphql.headers', []);
        $options = $this->config->get('graphql.json_encoding_options', 0);
        
        $errors = !$isBatch
            ? array_get($data, 'errors', [])
            : [];
        
        $authorized = \array_reduce(
            $errors,
            function ($authorized, $error) {
                return !(!$authorized || \array_get($error, 'message') === 'Unauthorized');
            },
            true
        );
        
        if ($errors !== []) {
            $connection->rollBack();
        } else {
            $connection->commit();
        }
        
        if (!$authorized) {
            return $this->responseFactory->json($data, 403, $headers, $options);
        }
        
        $this->dispatcher->dispatch(new RequestResolved($graphQLSchema, $errors));
        
        return $this->responseFactory->json($data, 200, $headers, $options);
    }
    
    /**
     * @param Request     $request
     * @param null|string $graphql_schema
     *
     * @return View
     */
    public function graphiql(Request $request, ?string $graphql_schema = null) : View
    {
        $view = $this->config->get('graphql.graphiql.view', 'graphql::graphiql');
        
        return $this->viewFactory->make(
            $view,
            [
                'graphql_schema' => $graphql_schema,
            ]
        );
    }
    
    /**
     * @param $schema
     * @param $input
     *
     * @return mixed
     */
    protected function executeQuery($schema, $input)
    {
        $variablesInputName = $this->config->get('graphql.variables_input_name', 'variables');
        $query = array_get($input, 'query');
        $variables = array_get($input, $variablesInputName);
        
        if (\is_string($variables)) {
            $variables = json_decode($variables, true);
        }
        
        $operationName = array_get($input, 'operationName');
        $context = $this->getQueryContext();
        
        return \app('graphql')->query(
            $query,
            $variables,
            \compact('context', 'schema', 'operationName')
        );
    }
    
    /**
     * @param string|null $schemaName
     *
     * @return array
     *
     * @throws Exception\SchemaNotFound
     * @throws Exception\TypeNotFound
     */
    private function getServerConfig(?string $schemaName) : ServerConfig
    {
        $config = [
            'schema'          => $this->getSchema($schemaName),
            //'rootValue'             => $this->getRootValue($schemaName),
            'context'         => $this->getQueryContext(),
            'fieldResolver'   => $this->getFieldResolver(),
            'validationRules' => [],
            'queryBatching'   => true,
            'debug'           => ErrorFormatter::getDebug(),
            //'persistentQueryLoader' =>,
            'errorFormatter'  => $this->getErrorFormatter(),
            'errorHandler'    => $this->getErrorHandler(),
            //'promiseAdapter'        =>,
        ];
        
        // Filter out null values.
        return ServerConfig::create(\array_filter($config));
    }
    
    /**
     * @param string|null $schemaName
     *
     * @return Schema
     *
     * @throws Exception\SchemaNotFound
     * @throws Exception\TypeNotFound
     */
    private function getSchema(?string $schemaName) : Schema
    {
        $graphQLSchema = $schemaName ?? $this->config->get('graphql.schema');
        
        /** @var GraphQL $graphQL */
        $graphQL = \app('graphql');
        
        return $graphQL->schema($graphQLSchema);
    }
    
    /**
     * @param string|null $schemaName
     *
     * @return mixed
     */
    private function getRootValue(?string $schemaName)
    {
        $additionalResolversSchemaName = \is_string($schemaName)
            ? $schemaName
            : $this->config->get('graphql.schema', 'default');
        
        $additionalResolvers = $this->config->get('graphql.resolvers.' . $additionalResolversSchemaName, []);
        
        return $additionalResolvers;
    }
    
    /**
     * @return mixed|null
     */
    private function getQueryContext()
    {
        try {
            $context = \app('auth')->user();
        } catch (\Exception $error) {
            $context = null;
        }
        
        return $context;
    }
    
    /**
     * @return callable|null
     */
    private function getFieldResolver() : ?callable
    {
        return $this->config->get('graphql.defaultFieldResolver');
    }
    
    /**
     * @return callable
     */
    private function getErrorFormatter() : ?callable
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
     * @return callable|null
     */
    private function getErrorHandler() : ?callable
    {
        return null;
    }
}
