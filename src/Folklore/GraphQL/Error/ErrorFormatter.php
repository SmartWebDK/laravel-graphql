<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Error;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use Psr\Log\LoggerInterface;

/**
 * Provides error formatting with support for setting debug mode.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class ErrorFormatter
{
    
    /**
     * Configuration key used to store debug mode configuration settings.
     */
    public const DEBUG_MODE_CONFIG_KEY = 'graphql.debug_mode';
    
    /**
     * Default value used for debug mode.
     */
    private const DEBUG_MODE_DEFAULT = false;
    
    /**
     * @var int|bool
     */
    private static $debug;
    
    /**
     * @var self[]
     */
    private static $instance;
    
    /**
     * @var LoggerInterface
     */
    private $logger;
    
    /**
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger)
    {
        $this->logger = $logger ?? \app(LoggerInterface::class);
    }
    
    /**
     * @param LoggerInterface|null $logger
     *
     * @return ErrorFormatter
     */
    public static function instance(?LoggerInterface $logger = null) : self
    {
        $loggerId = self::getLoggerId($logger);
        
        return self::$instance[$loggerId] ?? self::$instance[$loggerId] = new self($logger);
    }
    
    /**
     * @param LoggerInterface|null $logger
     *
     * @return string
     */
    private static function getLoggerId(?LoggerInterface $logger) : string
    {
        return $logger === null
            ? 'NULL'
            : \spl_object_hash($logger);
    }
    
    /**
     * @param Error $e
     *
     * @return array
     * @throws \Throwable
     */
    public function doFormatError(Error $e) : array
    {
        $error = FormattedError::createFromException($e, self::getDebug());
        $this->logError($e);
        
        $previous = $e->getPrevious();
        
        if ($previous && $previous instanceof ValidationError) {
            $error['validation'] = $previous->getValidatorMessages();
        }
        
        return $error;
    }
    
    /**
     * @param Error $error
     */
    private function logError(Error $error) : void
    {
        $this->logger->error($error->getMessage(), ['exception' => $error]);
    }
    
    /**
     * @param Error $e
     *
     * @return array
     * @throws \Throwable
     */
    public static function formatError(Error $e) : array
    {
        return self::instance()->doFormatError($e);
    }
    
    /**
     * @return bool|int
     */
    public static function getDebug()
    {
        return self::$debug ?? self::$debug = self::getDebugModeFromConfig();
    }
    
    /**
     * @return int|bool
     */
    private static function getDebugModeFromConfig()
    {
        return \config(self::DEBUG_MODE_CONFIG_KEY, self::DEBUG_MODE_DEFAULT);
    }
    
    /**
     * @param bool|int $debug
     */
    public static function setDebug($debug) : void
    {
        self::$debug = $debug;
    }
}
