<?php
declare(strict_types = 1);


namespace Folklore\GraphQL;

use Folklore\GraphQL\Events\RequestResolved;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;

/**
 * Main controller for Laravel GraphQL.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class GraphQLController extends Controller
{
    
    // FIXME: Clean up logic!
    // FIXME: Use custom Request class to handle isolation data, e.g. input variables and query string!
    
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
     * @var LoggerInterface
     */
    private $logger;
    
    /**
     * @param Request         $request
     * @param Repository      $config
     * @param ResponseFactory $responseFactory
     * @param Factory         $viewFactory
     * @param Dispatcher      $dispatcher
     * @param LoggerInterface $logger
     */
    public function __construct(
        Request $request,
        Repository $config,
        ResponseFactory $responseFactory,
        Factory $viewFactory,
        Dispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->responseFactory = $responseFactory;
        $this->viewFactory = $viewFactory;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        
        $route = $request->route();
        
        // Prevent schema middleware from being applied to 'graphiql' routes
        if ($this->routeIsGraphiQL($route)) {
            return;
        }
        
        $schema = $this->getSchemaFromRoute($route);
    
        $middleware = $this->config->get('graphql.middleware_schema.' . $schema);
        
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
            $schema = Arr::get($route, '2.' . $prefix . '_schema', $defaultSchema);
        } elseif (\is_object($route)) {
            $schema = $route->parameter($prefix . '_schema', $defaultSchema);
        }
        
        return $schema;
    }
    
    /**
     * @param Request     $request
     * @param null|string $graphql_schema
     *
     * @return JsonResponse
     */
    public function query(Request $request, ?string $graphql_schema = null) : JsonResponse
    {
        $isBatch = !$request->has('query');
        $inputs = $request->all();
        
        $graphQLSchema = $graphql_schema ?? $this->config->get('graphql.schema');
    
        $data = $this->executeQuery($request, $graphQLSchema);
        
        $headers = $this->config->get('graphql.headers', []);
        $options = $this->config->get('graphql.json_encoding_options', 0);
        
        $errors = !$isBatch
            ? Arr::get($data, 'errors', [])
            : [];
        
        $authorized = \array_reduce(
            $errors,
            static function ($authorized, $error) {
                return !(!$authorized || Arr::get($error, 'message') === 'Unauthorized');
            },
            true
        );
        
        if (!$authorized) {
            return $this->responseFactory->json($data, 403, $headers, $options);
        }
    
        $this->dispatcher->dispatch(
            new RequestResolved(
                $graphQLSchema,
                $this->getQueryString($inputs),
                $this->getVariables($inputs),
                $errors
            )
        );
        
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
     * @param Request $request
     * @param string  $schema
     *
     * @return array
     */
    private function executeQuery(Request $request, string $schema) : array
    {
        $isBatch = !$request->has('query');
        $inputs = $request->all();
        $context = $this->queryContext();
    
        if (!$isBatch) {
            return $this->runQuery($inputs, $schema, $context);
        }
    
        $data = [];
    
        foreach ($inputs as $input) {
            $data[] = $this->runQuery($input, $schema, $context);
        }
    
        return $data;
    }
    
    /**
     * @param array                $input
     * @param string               $schema
     * @param Authenticatable|null $context
     *
     * @return array
     */
    private function runQuery(array $input, string $schema, ?Authenticatable $context) : array
    {
        $query = $this->getQueryString($input);
        $variables = $this->getVariables($input);
        
        $operationName = Arr::get($input, 'operationName');
        
        return \app('graphql')->query(
            $query,
            $variables,
            \compact('context', 'schema', 'operationName')
        );
    }
    
    /**
     * @param array $input
     *
     * @return string
     */
    private function getQueryString(array $input) : string
    {
        return Arr::get($input, 'query');
    }
    
    /**
     * @param array $input
     *
     * @return array
     */
    private function getVariables(array $input) : array
    {
        $variablesInputName = $this->config->get('graphql.variables_input_name', 'variables');
        $variables = Arr::get($input, $variablesInputName);
        
        if (\is_string($variables)) {
            $variables = \json_decode($variables, true);
        }
        
        return $variables;
    }
    
    /**
     * @return Authenticatable|null
     */
    private function queryContext() : ?Authenticatable
    {
        try {
            $context = \app('auth')->user();
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage(), \compact('exception'));
            $context = null;
        }
        
        return $context;
    }
}
