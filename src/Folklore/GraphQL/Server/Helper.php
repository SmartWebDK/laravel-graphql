<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Server;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Server\Helper as BaseHelper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\ServerConfig;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Contains functionality that could be re-used by various server implementations.
 *
 * @author Nicolai Agersbæk <nicolai.agersbaek@team.blue>
 *
 * @api
 */
class Helper
{
    
    /**
     * Executes batched GraphQL operations with shared promise queue
     * (thus, effectively batching Deferreds|Promises of all queries at once)
     *
     * @param ServerConfig      $config
     * @param OperationParams[] $operations
     *
     * @return ExecutionResult[]|Promise
     */
    public function executeBatch(ServerConfig $config, array $operations)
    {
        $promiseAdapter = $config->getPromiseAdapter() ?? Executor::getPromiseAdapter();
    
        $result = $this->getBatchExecutionResults($config, $promiseAdapter, $operations);
    
        // Wait for promised results when using sync promises
        return $promiseAdapter instanceof SyncPromiseAdapter
            ? $promiseAdapter->wait($result)
            : $result;
    }
    
    /**
     * @param ServerConfig      $config
     * @param PromiseAdapter    $promiseAdapter
     * @param OperationParams[] $operations
     *
     * @return Promise
     */
    private function getBatchExecutionResults(
        ServerConfig $config,
        PromiseAdapter $promiseAdapter,
        array $operations
    ) : Promise {
        $result = [];
        
        $this->prepareConfigContextForBatchExecution($config);
        
        foreach ($operations as $index => $operation) {
            $this->updateConfigContextWithBatchIndex($config, $index);
            
            $result[] = $this->promiseToExecuteOperation($promiseAdapter, $config, $operation, true);
        }
        
        return $promiseAdapter->all($result);
    }
    
    /**
     * @param ServerConfig $config
     */
    private function prepareConfigContextForBatchExecution(ServerConfig $config) : void
    {
        $context = $config->getContext();
        $context = \array_merge(['viewer' => $context, 'batchIndex' => 0]);
        
        $config->setContext($context);
    }
    
    /**
     * @param ServerConfig $config
     * @param int          $batchIndex
     */
    private function updateConfigContextWithBatchIndex(ServerConfig $config, int $batchIndex) : void
    {
        $context = $config->getContext();
        
        $context['batchIndex'] = $batchIndex;
        $config->setContext($context);
    }
    
    /**
     * @param PromiseAdapter  $promiseAdapter
     * @param ServerConfig    $config
     * @param OperationParams $params
     * @param bool|null       $isBatch Default: **false**.
     *
     * @return Promise
     */
    private function promiseToExecuteOperation(
        PromiseAdapter $promiseAdapter,
        ServerConfig $config,
        OperationParams $params,
        ?bool $isBatch = null
    ) : Promise {
        $isBatch = $isBatch ?? false;
        
        try {
            $result = $this->attemptToExecuteOperation($promiseAdapter, $config, $params, $isBatch);
        } catch (RequestError $error) {
            $result = $promiseAdapter->createFulfilled(
                new ExecutionResult(null, [Error::createLocatedError($error)])
            );
        } catch (Error $error) {
            $result = $promiseAdapter->createFulfilled(
                new ExecutionResult(null, [$error])
            );
        }
        
        return $this->applyErrorHandling($result, $config);
    }
    
    /**
     * @param PromiseAdapter  $promiseAdapter
     * @param ServerConfig    $config
     * @param OperationParams $params
     * @param bool            $isBatch
     *
     * @return Promise
     *
     * @throws RequestError
     */
    private function attemptToExecuteOperation(
        PromiseAdapter $promiseAdapter,
        ServerConfig $config,
        OperationParams $params,
        bool $isBatch
    ) : Promise {
        $this->validateConfigForPromiseExecution($config, $isBatch);
        
        $errors = $this->getErrorsForOperationParams($params);
        
        if ($errors !== []) {
            return $promiseAdapter->createFulfilled(
                new ExecutionResult(null, $errors)
            );
        }
        
        $document = $this->getDocumentNodeFromOperationParams($config, $params);
        $operationType = $this->getOperationType($document, $params);
        
        return $this->promiseToExecute($promiseAdapter, $config, $params, $document, $operationType);
    }
    
    /**
     * @param PromiseAdapter  $promiseAdapter
     * @param ServerConfig    $config
     * @param OperationParams $params
     * @param DocumentNode    $document
     * @param false|string    $operationType
     *
     * @return Promise
     */
    private function promiseToExecute(
        PromiseAdapter $promiseAdapter,
        ServerConfig $config,
        OperationParams $params,
        DocumentNode $document,
        $operationType
    ) : Promise {
        return GraphQL::promiseToExecute(
            $promiseAdapter,
            $config->getSchema(),
            $document,
            $this->resolveRootValue($config, $params, $document, $operationType),
            $this->resolveContextValue($config, $params, $document, $operationType),
            $params->variables,
            $params->operation,
            $config->getFieldResolver(),
            $this->resolveValidationRules($config, $params, $document, $operationType)
        );
    }
    
    /**
     * @param Promise      $result
     * @param ServerConfig $config
     *
     * @return Promise
     */
    private function applyErrorHandling(Promise $result, ServerConfig $config) : Promise
    {
        return $result->then($this->applyErrorHandlingCallback($config));
    }
    
    /**
     * @param ServerConfig $config
     *
     * @return callable
     */
    private function applyErrorHandlingCallback(ServerConfig $config) : callable
    {
        return static function (ExecutionResult $result) use ($config) {
            return self::applyErrorHandlingToResult($result, $config);
        };
    }
    
    /**
     * @param ExecutionResult $result
     * @param ServerConfig    $config
     *
     * @return ExecutionResult
     *
     * @internal
     */
    public static function applyErrorHandlingToResult(ExecutionResult $result, ServerConfig $config) : ExecutionResult
    {
        if ($config->getErrorsHandler()) {
            $result->setErrorsHandler($config->getErrorsHandler());
        }
        
        if ($config->getErrorFormatter() || $config->getDebug()) {
            $result->setErrorFormatter(
                FormattedError::prepareFormatter(
                    $config->getErrorFormatter(),
                    $config->getDebug()
                )
            );
        }
        
        return $result;
    }
    
    /**
     * @param DocumentNode    $document
     * @param OperationParams $params
     *
     * @return false|string
     *
     * @throws RequestError
     */
    private function getOperationType(DocumentNode $document, OperationParams $params)
    {
        /** @var false|string $operationType */
        $operationType = AST::getOperation($document, $params->operation);
        
        if ($operationType !== 'query' && $params->isReadOnly()) {
            throw new RequestError('GET supports only query operation');
        }
        
        return $operationType;
    }
    
    /**
     * @param ServerConfig    $config
     * @param OperationParams $params
     *
     * @return DocumentNode
     *
     * @throws RequestError
     */
    private function getDocumentNodeFromOperationParams(ServerConfig $config, OperationParams $params) : DocumentNode
    {
        $document = $params->queryId
            ? $this->loadPersistedQuery($config, $params)
            : $params->query;
        
        return !$document instanceof DocumentNode
            ? Parser::parse($document)
            : $document;
    }
    
    /**
     * @param ServerConfig $config
     * @param bool         $isBatch
     *
     * @throws RequestError
     */
    private function validateConfigForPromiseExecution(
        ServerConfig $config,
        bool $isBatch
    ) : void {
        if (!$config->getSchema()) {
            throw new InvariantViolation('Schema is required for the server');
        }
        
        if ($isBatch && !$config->getQueryBatching()) {
            throw new RequestError('Batched queries are not supported by this server');
        }
    }
    
    /**
     * @param OperationParams $params
     *
     * @return Error[]
     */
    private function getErrorsForOperationParams(OperationParams $params) : array
    {
        $errors = $this->validateOperationParams($params);
        
        return $errors !== []
            ? $this->formatOperationParamsErrors($errors)
            : $errors;
    }
    
    /**
     * @param array $errors
     *
     * @return Error[]
     */
    private function formatOperationParamsErrors(array $errors) : array
    {
        $mapping = static function (RequestError $error) {
            return Error::createLocatedError($error, null, null);
        };
        
        return \array_map($mapping, $errors);
    }
    
    /**
     * Checks validity of OperationParams extracted from HTTP request and returns an array of errors
     * if params are invalid (or empty array when params are valid)
     *
     * @param OperationParams $params
     *
     * @return Error[]
     */
    public function validateOperationParams(OperationParams $params) : array
    {
        $errors = [];
        if (!$params->query && !$params->queryId) {
            $errors[] = new RequestError(
                'GraphQL Request must include at least one of those two parameters: "query" or "queryId"'
            );
        }
        if ($params->query && $params->queryId) {
            $errors[] = new RequestError('GraphQL Request parameters "query" and "queryId" are mutually exclusive');
        }
        
        if ($params->query !== null && (!is_string($params->query) || empty($params->query))) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "query" must be string, but got ' .
                Utils::printSafeJson($params->query)
            );
        }
        if ($params->queryId !== null && (!is_string($params->queryId) || empty($params->queryId))) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "queryId" must be string, but got ' .
                Utils::printSafeJson($params->queryId)
            );
        }
        
        if ($params->operation !== null && (!is_string($params->operation) || empty($params->operation))) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "operation" must be string, but got ' .
                Utils::printSafeJson($params->operation)
            );
        }
        if ($params->variables !== null && (!is_array($params->variables) || isset($params->variables[0]))) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "variables" must be object or JSON string parsed to object, but got ' .
                Utils::printSafeJson($params->getOriginalInput('variables'))
            );
        }
        
        return $errors;
    }
    
    /**
     * @param ServerConfig    $config
     * @param OperationParams $op
     *
     * @return mixed
     *
     * @throws RequestError
     */
    private function loadPersistedQuery(ServerConfig $config, OperationParams $op)
    {
        // Load query if we got persisted query id:
        $loader = $config->getPersistentQueryLoader();
        
        if (!$loader) {
            throw new RequestError('Persisted queries are not supported by this server');
        }
        
        $source = $loader($op->queryId, $op);
        
        if (!is_string($source) && !$source instanceof DocumentNode) {
            throw new InvariantViolation(
                sprintf(
                    'Persistent query loader must return query string or instance of %s but got: %s',
                    DocumentNode::class,
                    Utils::printSafe($source)
                )
            );
        }
        
        return $source;
    }
    
    /**
     * @param ServerConfig    $config
     * @param OperationParams $params
     * @param DocumentNode    $doc
     * @param mixed           $operationType
     *
     * @return mixed
     */
    private function resolveRootValue(ServerConfig $config, OperationParams $params, DocumentNode $doc, $operationType)
    {
        $root = $config->getRootValue();
        
        if ($root instanceof \Closure) {
            $root = $root($params, $doc, $operationType);
        }
        
        return $root;
    }
    
    /**
     * @param ServerConfig    $config
     * @param OperationParams $params
     * @param DocumentNode    $doc
     * @param mixed           $operationType
     *
     * @return mixed
     */
    private function resolveContextValue(
        ServerConfig $config,
        OperationParams $params,
        DocumentNode $doc,
        $operationType
    ) {
        $context = $config->getContext();
        
        if ($context instanceof \Closure) {
            $context = $context($params, $doc, $operationType);
        }
        
        return $context;
    }
    
    /**
     * @param ServerConfig    $config
     * @param OperationParams $params
     * @param DocumentNode    $doc
     * @param mixed           $operationType
     *
     * @return array
     */
    private function resolveValidationRules(
        ServerConfig $config,
        OperationParams $params,
        DocumentNode $doc,
        $operationType
    ) : array {
        // Allow customizing validation rules per operation:
        $validationRules = $config->getValidationRules();
    
        if (\is_callable($validationRules)) {
            $validationRules = $validationRules($params, $doc, $operationType);
        
            if (!\is_array($validationRules)) {
                throw new InvariantViolation(
                    \sprintf(
                        'Expecting validation rules to be array or callable returning array, but got: %s',
                        Utils::printSafe($validationRules)
                    )
                );
            }
        }
    
        return $validationRules ?? [];
    }
    
    /**
     * Parses HTTP request using PHP globals and returns GraphQL OperationParams
     * contained in this request. For batched requests it returns an array of OperationParams.
     *
     * This function does not check validity of these params
     * (validation is performed separately in validateOperationParams() method).
     *
     * If $readRawBodyFn argument is not provided - will attempt to read raw request body
     * from `php://input` stream.
     *
     * Internally it normalizes input to $method, $bodyParams and $queryParams and
     * calls `parseRequestParams()` to produce actual return value.
     *
     * For PSR-7 request parsing use `parsePsrRequest()` instead.
     *
     * @param callable|null $readRawBodyFn
     *
     * @return OperationParams|OperationParams[]
     *
     * @throws RequestError
     */
    public function parseHttpRequest(callable $readRawBodyFn = null)
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $bodyParams = [];
        $urlParams = $_GET;
        
        if ($method === 'POST') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? null;
            
            if (stripos($contentType, 'application/graphql') !== false) {
                $rawBody = $readRawBodyFn
                    ? $readRawBodyFn()
                    : $this->readRawBody();
                $bodyParams = [
                    'query' => $rawBody
                        ?: '',
                ];
            } elseif (stripos($contentType, 'application/json') !== false) {
                $rawBody = $readRawBodyFn
                    ? $readRawBodyFn()
                    : $this->readRawBody();
                $bodyParams = json_decode(
                    $rawBody
                        ?: '',
                    true
                );
                
                if (json_last_error()) {
                    throw new RequestError('Could not parse JSON: ' . json_last_error_msg());
                }
                if (!is_array($bodyParams)) {
                    throw new RequestError(
                        'GraphQL Server expects JSON object or array, but got ' .
                        Utils::printSafeJson($bodyParams)
                    );
                }
            } elseif (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                $bodyParams = $_POST;
            } elseif ($contentType === null) {
                throw new RequestError('Missing "Content-Type" header');
            } else {
                throw new RequestError('Unexpected content type: ' . Utils::printSafeJson($contentType));
            }
        }
        
        return $this->parseRequestParams($method, $bodyParams, $urlParams);
    }
    
    /**
     * Executes GraphQL operation with given server configuration and returns execution result
     * (or promise when promise adapter is different from SyncPromiseAdapter)
     *
     * @param ServerConfig    $config
     * @param OperationParams $op
     *
     * @return ExecutionResult|Promise
     *
     */
    public function executeOperation(ServerConfig $config, OperationParams $op)
    {
        $promiseAdapter = $config->getPromiseAdapter()
            ?: Executor::getPromiseAdapter();
        $result = $this->promiseToExecuteOperation($promiseAdapter, $config, $op);
        
        if ($promiseAdapter instanceof SyncPromiseAdapter) {
            $result = $promiseAdapter->wait($result);
        }
        
        return $result;
    }
    
    /**
     * Send response using standard PHP `header()` and `echo`.
     *
     * @param Promise|ExecutionResult|ExecutionResult[] $result
     * @param bool|null                                 $exitWhenDone If **true**, the program will exit when done.
     *                                                                Default: **false**.
     */
    public function sendResponse($result, ?bool $exitWhenDone = null) : void
    {
        $exitWhenDone = $exitWhenDone ?? false;
        
        if ($result instanceof Promise) {
            $this->sendPromiseResponse($result, $exitWhenDone);
        } else {
            $this->doSendResponse($result, $exitWhenDone);
        }
    }
    
    /**
     * @param Promise $result
     * @param bool    $exitWhenDone
     */
    private function sendPromiseResponse(Promise $result, bool $exitWhenDone) : void
    {
        $result->then(
            function ($actualResult) use ($exitWhenDone) {
                $this->doSendResponse($actualResult, $exitWhenDone);
            }
        );
    }
    
    /**
     * @param mixed $result
     * @param bool  $exitWhenDone
     */
    private function doSendResponse($result, bool $exitWhenDone) : void
    {
        $httpStatus = $this->resolveHttpStatus($result);
        
        $this->emitResponse($result, $httpStatus, $exitWhenDone);
    }
    
    /**
     * @param mixed $result
     *
     * @return int
     */
    private function resolveHttpStatus($result) : int
    {
        if (is_array($result) && isset($result[0])) {
            Utils::each(
                $result,
                static function ($executionResult, $index) {
                    if (!$executionResult instanceof ExecutionResult) {
                        throw new InvariantViolation(
                            sprintf(
                                'Expecting every entry of batched query result to be instance of %s but entry at position %d is %s',
                                ExecutionResult::class,
                                $index,
                                Utils::printSafe($executionResult)
                            )
                        );
                    }
                }
            );
            $httpStatus = 200;
        } else {
            if (!$result instanceof ExecutionResult) {
                throw new InvariantViolation(
                    sprintf(
                        'Expecting query result to be instance of %s but got %s',
                        ExecutionResult::class,
                        Utils::printSafe($result)
                    )
                );
            }
            if ($result->data === null && !empty($result->errors)) {
                $httpStatus = 400;
            } else {
                $httpStatus = 200;
            }
        }
        
        return $httpStatus;
    }
    
    /**
     * @param array|\JsonSerializable $jsonSerializable
     * @param int                     $httpStatus
     * @param bool                    $exitWhenDone
     */
    public function emitResponse($jsonSerializable, $httpStatus, $exitWhenDone) : void
    {
        $body = json_encode($jsonSerializable);
        header('Content-Type: application/json', true, $httpStatus);
        echo $body;
        
        if ($exitWhenDone) {
            exit;
        }
    }
    
    /**
     * Converts PSR-7 request to OperationParams[]
     *
     * @param ServerRequestInterface $request
     *
     * @return array|BaseHelper
     *
     * @throws RequestError
     */
    public function parsePsrRequest(ServerRequestInterface $request)
    {
        if ($request->getMethod() === 'GET') {
            $bodyParams = [];
        } else {
            $contentType = $request->getHeader('content-type');
            
            if (!isset($contentType[0])) {
                throw new RequestError('Missing "Content-Type" header');
            }
            
            if (stripos('application/graphql', $contentType[0]) !== false) {
                $bodyParams = ['query' => $request->getBody()->getContents()];
            } elseif (stripos('application/json', $contentType[0]) !== false) {
                $bodyParams = $request->getParsedBody();
    
                if ($bodyParams === null) {
                    throw new InvariantViolation(
                        'PSR-7 request is expected to provide parsed body for "application/json" requests but got null'
                    );
                }
                
                if (!is_array($bodyParams)) {
                    throw new RequestError(
                        'GraphQL Server expects JSON object or array, but got ' .
                        Utils::printSafeJson($bodyParams)
                    );
                }
            } else {
                $bodyParams = $request->getParsedBody();
                
                if (!is_array($bodyParams)) {
                    throw new RequestError('Unexpected content type: ' . Utils::printSafeJson($contentType[0]));
                }
            }
        }
        
        return $this->parseRequestParams(
            $request->getMethod(),
            $bodyParams,
            $request->getQueryParams()
        );
    }
    
    /**
     * Converts query execution result to PSR-7 response.
     *
     * @param Promise|ExecutionResult|ExecutionResult[] $result
     * @param ResponseInterface                         $response
     * @param StreamInterface                           $writableBodyStream
     *
     * @return Promise|ResponseInterface
     */
    public function toPsrResponse($result, ResponseInterface $response, StreamInterface $writableBodyStream)
    {
        return $result instanceof Promise
            ? $this->convertPromiseToPsrResponse($result, $response, $writableBodyStream)
            : $this->doConvertToPsrResponse($result, $response, $writableBodyStream);
    }
    
    /**
     * @param Promise           $result
     * @param ResponseInterface $response
     * @param StreamInterface   $writableBodyStream
     *
     * @return Promise
     */
    private function convertPromiseToPsrResponse(
        Promise $result,
        ResponseInterface $response,
        StreamInterface $writableBodyStream
    ) : Promise {
        $callback = function ($actualResult) use ($response, $writableBodyStream) {
            return $this->doConvertToPsrResponse($actualResult, $response, $writableBodyStream);
        };
        
        return $result->then($callback);
    }
    
    /**
     * @param mixed             $result
     * @param ResponseInterface $response
     * @param StreamInterface   $writableBodyStream
     *
     * @return ResponseInterface
     */
    private function doConvertToPsrResponse(
        $result,
        ResponseInterface $response,
        StreamInterface $writableBodyStream
    ) : ResponseInterface {
        $httpStatus = $this->resolveHttpStatus($result);
        
        // FIXME: Use Symfony's JsonEncode!
        $result = \json_encode($result);
        $writableBodyStream->write($result);
        
        return $response
            ->withStatus($httpStatus)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($writableBodyStream);
    }
    
    /**
     * @return bool|string
     */
    private function readRawBody()
    {
        return \file_get_contents('php://input');
    }
    
    /**
     * Parses normalized request params and returns instance of OperationParams
     * or array of OperationParams in case of batch operation.
     *
     * Returned value is a suitable input for `executeOperation` or `executeBatch` (if array)
     *
     * @param string $method
     * @param array  $bodyParams
     * @param array  $queryParams
     *
     * @return OperationParams|OperationParams[]
     *
     * @throws RequestError
     */
    public function parseRequestParams($method, array $bodyParams, array $queryParams)
    {
        if ($method === 'GET') {
            $result = OperationParams::create($queryParams, true);
        } elseif ($method === 'POST') {
            if (isset($bodyParams[0])) {
                $result = [];
                foreach ($bodyParams as $index => $entry) {
                    $op = OperationParams::create($entry);
                    $result[] = $op;
                }
            } else {
                $result = OperationParams::create($bodyParams);
            }
        } else {
            throw new RequestError('HTTP Method "' . $method . '" is not supported');
        }
        
        return $result;
    }
}
