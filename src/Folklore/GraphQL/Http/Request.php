<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Http;

use Illuminate\Http\Request as BaseRequest;
use Illuminate\Support\Arr;

/**
 * TODO: Missing class description.
 *
 * @author Nicolai Agersbæk <na@zitcom.dk>
 *
 * @api
 */
class Request extends BaseRequest
{
    
    /**
     * @return bool
     */
    public function isBatch() : bool
    {
        return !$this->has('query');
    }
    
    /**
     * @return string
     */
    public function queryString() : string
    {
        return $this->value('query');
    }
    
    /**
     * @param string $key
     *
     * @return mixed
     */
    public function value(string $key)
    {
        return Arr::get($this->all(), $key);
    }
    
    /**
     * @return string
     */
    public function operationName() : string
    {
        return $this->value('operationName');
    }
    
    /**
     * @param string $variablesInputName
     *
     * @return array
     */
    public function variables(string $variablesInputName) : array
    {
        $variables = $this->value($variablesInputName);
        
        if (\is_string($variables)) {
            $variables = \json_decode($variables, true);
        }
        
        return $variables;
    }
}
