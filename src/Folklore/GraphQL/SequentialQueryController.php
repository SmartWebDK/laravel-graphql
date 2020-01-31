<?php
/** @noinspection EfferentObjectCouplingInspection */
declare(strict_types = 1);


namespace Folklore\GraphQL;

use Folklore\GraphQL\Events\RequestResolved;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
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
use Illuminate\Support\Arr;

/**
 * Controller handling HTTP requests for the GraphQL end-points using sequential
 * resolution for batched requests.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class SequentialQueryController extends Controller
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
     * @var GraphQL
     */
    private $graphQL;
    
    /**
     * @param Request         $request
     * @param Repository      $config
     * @param ResponseFactory $responseFactory
     * @param Factory         $viewFactory
     * @param Dispatcher      $dispatcher
     * @param GraphQL         $graphQL
     */
    public function __construct(
        Request $request,
        Repository $config,
        ResponseFactory $responseFactory,
        Factory $viewFactory,
        Dispatcher $dispatcher,
        GraphQL $graphQL
    ) {
        $this->config = $config;
        $this->responseFactory = $responseFactory;
        $this->viewFactory = $viewFactory;
        $this->dispatcher = $dispatcher;
        $this->graphQL = $graphQL;
        
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
    
    public function query(Request $request, $graphql_schema = null)
    {
        $isBatch = !$request->has('query');
        $inputs = $request->all();
    
        if (is_null($graphql_schema)) {
            $graphql_schema = config('graphql.schema');
        }
    
        /** @var ConnectionInterface $connection */
        $connection = Container::getInstance()->get(ConnectionInterface::class);
    
        $connection->beginTransaction();
    
        if (!$isBatch) {
            $data = $this->executeQuery($graphql_schema, $inputs);
        } else {
            $data = [];
            foreach ($inputs as $input) {
                $data[] = $this->executeQuery($graphql_schema, $input);
            }
        }
    
        $headers = config('graphql.headers', []);
        $options = config('graphql.json_encoding_options', 0);
    
        $errors = !$isBatch
            ? array_get($data, 'errors', [])
            : [];
    
        if ($errors !== []) {
            $connection->rollBack();
        } else {
            $connection->commit();
        }
    
        $authorized = array_reduce(
            $errors,
            function ($authorized, $error) {
                return !$authorized || array_get($error, 'message') === 'Unauthorized'
                    ? false
                    : true;
            },
            true
        );
        if (!$authorized) {
            return response()->json($data, 403, $headers, $options);
        }
        
        return response()->json($data, 200, $headers, $options);
    }
    
    /**
     * @param Request $request
     * @param null    $graphql_schema
     *
     * @return Factory|\Illuminate\View\View
     * @noinspection PhpUnused
     */
    public function graphiql(Request $request, $graphql_schema = null)
    {
        $view = config('graphql.graphiql.view', 'graphql::graphiql');
        
        return view(
            $view,
            [
                'graphql_schema' => $graphql_schema,
            ]
        );
    }
    
    protected function executeQuery($schema, $input)
    {
        $variablesInputName = config('graphql.variables_input_name', 'variables');
        $query = array_get($input, 'query');
        $variables = array_get($input, $variablesInputName);
        if (is_string($variables)) {
            $variables = json_decode($variables, true);
        }
        $operationName = array_get($input, 'operationName');
        $context = $this->queryContext($query, $variables, $schema);
        
        return app('graphql')->query(
            $query,
            $variables,
            [
                'context'       => $context,
                'schema'        => $schema,
                'operationName' => $operationName,
            ]
        );
    }
    
    protected function queryContext($query, $variables, $schema)
    {
        try {
            return app('auth')->user();
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * @param Request     $request
     * @param string|null $graphql_schema
     *
     * @return JsonResponse
     *
     * @throws Exception\SchemaNotFound
     * @throws Exception\TypeNotFound
     */
    public function query2(Request $request, ?string $graphql_schema = null) : JsonResponse
    {
        /** @var ConnectionInterface $connection */
        $connection = Container::getInstance()->get(ConnectionInterface::class);
        
        $isBatch = !$request->has('query');
        $inputs = $request->all();
        
        $schemaName = $this->getSchemaName($graphql_schema);
        
        $connection->beginTransaction();
        
        if (!$isBatch) {
            $results = $this->executeQuery2($schemaName, $inputs);
        } else {
            $results = [];
            foreach ($inputs as $batchIndex => $input) {
                $results[] = $this->executeQuery2($schemaName, $input);
            }
        }
        
        $errors = $this->getSequentialErrors($results, $isBatch);
        
        if ($errors !== []) {
            $connection->rollBack();
        } else {
            $connection->commit();
        }
        
        if (!$this->isAuthorized($errors)) {
            return $this->response($results, 403);
        }
        
        $this->dispatcher->dispatch(new RequestResolved($schemaName, $errors));
        
        return $this->response($results, 200);
    }
    
    /**
     * @param string|null $schemaName
     *
     * @return string
     */
    private function getSchemaName(?string $schemaName) : string
    {
        return $schemaName ?? $this->config->get('graphql.schema');
    }
    
    /**
     * @param string $schemaName
     * @param array  $input
     *
     * @return array
     *
     * @throws Exception\SchemaNotFound
     * @throws Exception\TypeNotFound
     */
    protected function executeQuery2(string $schemaName, array $input) : array
    {
        return $this->graphQL->query(
            $this->getQuery($input),
            $variables = $this->getVariablesInput($input),
            [
                'context'       => $this->getQueryContext(),
                'schema'        => $schemaName,
                'operationName' => $this->getOperationName($input),
            ]
        );
    }
    
    /**
     * @param array $results
     * @param bool  $isBatch
     *
     * @return array
     */
    private function getSequentialErrors(array $results, bool $isBatch) : array
    {
        return !$isBatch
            ? Arr::get($results, 'errors', [])
            : [];
    }
    
    /**
     * @param array $errors
     *
     * @return bool
     */
    private function isAuthorized(array $errors) : bool
    {
        // FIXME: Missing tests!
        // No need to analyze error array if no errors arose.
        if ($errors === []) {
            return true;
        }
        
        return \array_reduce(
            $errors,
            static function ($authorized, $error) {
                return !(!$authorized || Arr::get($error, 'message') === 'Unauthorized');
            },
            true
        );
    }
    
    /**
     * @param ExecutionResult|ExecutionResult[]|Promise $results
     * @param int                                       $status
     *
     * @return JsonResponse
     */
    private function response($results, int $status) : JsonResponse
    {
        // FIXME: Missing tests!
        // FIXME: Move to separate Http\ResponseFactory class!
        $headers = $this->config->get('graphql.headers', []);
        $options = $this->config->get('graphql.json_encoding_options', 0);
        
        return $this->responseFactory->json($results, $status, $headers, $options);
    }
    
    /**
     * @param array $input
     *
     * @return string
     */
    private function getQuery(array $input) : string
    {
        return Arr::get($input, 'query');
    }
    
    /**
     * @param array $input
     *
     * @return array
     */
    private function getVariablesInput(array $input) : array
    {
        $variablesInputName = $this->config->get('graphql.variables_input_name', 'variables');
        $variables = Arr::get($input, $variablesInputName);
        
        return \is_string($variables)
            ? \json_decode($variables, true)
            : $variables;
    }
    
    /**
     * @return mixed|null
     */
    private function getQueryContext()
    {
        try {
            // FIXME: Replace with injection of `\Illuminate\Contracts\Auth\Guard`!
            $context = \app('auth')->user();
        } /** @noinspection BadExceptionsProcessingInspection */
        catch (\Exception $error) {
            $context = null;
        }
        
        return $context;
    }
    
    /**
     * @param array $input
     *
     * @return string
     */
    private function getOperationName(array $input) : string
    {
        return Arr::get($input, 'operationName');
    }
}
