<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Server;

use GraphQL\Error\FormattedError;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\ServerConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * GraphQL server compatible with both: [express-graphql](https://github.com/graphql/express-graphql)
 * and [Apollo Server](https://github.com/apollographql/graphql-server).
 * Usage Example:
 *
 *     $server = new StandardServer([
 *       'schema' => $mySchema
 *     ]);
 *     $server->handleRequest();
 *
 * Or using [ServerConfig](reference.md#graphqlserverserverconfig) instance:
 *
 *     $config = GraphQL\Server\ServerConfig::create()
 *         ->setSchema($mySchema)
 *         ->setContext($myContext);
 *
 *     $server = new GraphQL\Server\StandardServer($config);
 *     $server->handleRequest();
 *
 * See [dedicated section in docs](executing-queries.md#using-server) for details.
 *
 * @api
 */
class StandardServer
{
    
    /**
     * @var ServerConfig
     */
    private $config;
    
    /**
     * @var Helper
     */
    private $helper;
    
    /**
     * Creates new instance of a standard GraphQL HTTP server
     *
     * @param ServerConfig $config
     */
    public function __construct(ServerConfig $config)
    {
        $this->config = $config;
        $this->helper = new Helper();
    }
    
    /**
     * Parses HTTP request, executes and emits response (using standard PHP `header` function and `echo`)
     *
     * By default (when $parsedBody is not set) it uses PHP globals to parse a request.
     * It is possible to implement request parsing elsewhere (e.g. using framework Request instance)
     * and then pass it to the server.
     *
     * See `executeRequest()` if you prefer to emit response yourself
     * (e.g. using Response object of some framework)
     *
     * @param OperationParams|OperationParams[] $parsedBody
     * @param bool|null                         $exitWhenDone If **true**, the program will exit when done.
     *                                                        Default: **false**.
     *
     * @throws RequestError
     */
    public function handleRequest($parsedBody = null, ?bool $exitWhenDone = null) : void
    {
        $exitWhenDone = $exitWhenDone ?? false;
        
        $result = $this->executeRequest($parsedBody);
        
        $this->helper->sendResponse($result, $exitWhenDone);
    }
    
    /**
     * Executes GraphQL operation and returns execution result
     * (or promise when promise adapter is different from SyncPromiseAdapter).
     *
     * By default (when $parsedBody is not set) it uses PHP globals to parse a request.
     * It is possible to implement request parsing elsewhere (e.g. using framework Request instance)
     * and then pass it to the server.
     *
     * PSR-7 compatible method executePsrRequest() does exactly this.
     *
     * @param OperationParams|OperationParams[] $parsedBody
     *
     * @return ExecutionResult|ExecutionResult[]|Promise
     *
     * @throws InvariantViolation
     * @throws RequestError
     */
    public function executeRequest($parsedBody = null)
    {
        if ($parsedBody === null) {
            $parsedBody = $this->helper->parseHttpRequest();
        }
    
        return \is_array($parsedBody)
            ? $this->helper->executeBatch($this->config, $parsedBody)
            : $this->helper->executeOperation($this->config, $parsedBody);
    }
    
    /**
     * Executes PSR-7 request and fulfills PSR-7 response.
     *
     * See `executePsrRequest()` if you prefer to create response yourself
     * (e.g. using specific JsonResponse instance of some framework).
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @param StreamInterface        $writableBodyStream
     *
     * @return ResponseInterface|Promise
     *
     * @throws RequestError
     */
    public function processPsrRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        StreamInterface $writableBodyStream
    ) {
        $result = $this->executePsrRequest($request);
        
        return $this->helper->toPsrResponse($result, $response, $writableBodyStream);
    }
    
    /**
     * Executes GraphQL operation and returns execution result
     * (or promise when promise adapter is different from SyncPromiseAdapter)
     *
     * @param ServerRequestInterface $request
     *
     * @return ExecutionResult|ExecutionResult[]|Promise
     *
     * @throws RequestError
     */
    public function executePsrRequest(ServerRequestInterface $request)
    {
        $parsedBody = $this->helper->parsePsrRequest($request);
        
        return $this->executeRequest($parsedBody);
    }
    
    /**
     * Returns an instance of Server helper, which contains most of the actual logic for
     * parsing / validating / executing request (which could be re-used by other server implementations)
     *
     * @return Helper
     */
    public function getHelper() : Helper
    {
        return $this->helper;
    }
    
    /**
     * Converts and exception to error and sends spec-compliant HTTP 500 error.
     * Useful when an exception is thrown somewhere outside of server execution context
     * (e.g. during schema instantiation).
     *
     * @param \Throwable    $error
     * @param bool|int|null $debug                            Debug mode used to create the error.
     *                                                        Default: **false**.
     *                                                        For a list of available debug flags see {@see \GraphQL\Error\Debug Debug} constants.
     * @param bool|null     $exitWhenDone                     If **true**, the program will exit when done.
     *                                                        Default: **false**.
     *
     * @throws \Throwable
     */
    public static function send500Error($error, $debug = null, ?bool $exitWhenDone = null) : void
    {
        $debug = $debug ?? false;
        $exitWhenDone = $exitWhenDone ?? false;
        
        $response = [
            'errors' => [
                FormattedError::createFromException($error, $debug),
            ],
        ];
        
        $helper = new Helper();
        $helper->emitResponse($response, 500, $exitWhenDone);
    }
}
