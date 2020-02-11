<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Events;

/**
 * Event fired when the execution of a query is initiated.
 *
 * @author Nicolai Agersbæk <nicolai.agersbaek@team.blue>
 *
 * @api
 */
class QueryExecutionStarted
{
    
    /**
     * @var string
     */
    private $query;
    
    /**
     * @var bool
     */
    private $batchExecution;
    
    /**
     * @var array|null
     */
    private $variables;
    
    /**
     * @var array|null
     */
    private $options;
    
    /**
     * @param string     $query
     * @param bool       $batchExecution
     * @param array|null $variables
     * @param array|null $options
     */
    public function __construct(string $query, bool $batchExecution, ?array $variables = null, ?array $options = null)
    {
        $this->query = $query;
        $this->batchExecution = $batchExecution;
        $this->variables = $variables;
        $this->options = $options;
    }
    
    /**
     * @return string
     */
    public function getQuery() : string
    {
        return $this->query;
    }
    
    /**
     * @return bool
     */
    public function isBatchExecution() : bool
    {
        return $this->batchExecution;
    }
    
    /**
     * @return array|null
     */
    public function getVariables() : ?array
    {
        return $this->variables;
    }
    
    /**
     * @return array|null
     */
    public function getOptions() : ?array
    {
        return $this->options;
    }
    
    /**
     * @param array $executionData
     *
     * @return self
     */
    public static function fromExecutionData(array $executionData) : self
    {
        return new self(
            $executionData['query'],
            $executionData['batchExecution'],
            $executionData['variables'] ?? null,
            $executionData['options'] ?? null
        );
    }
}
