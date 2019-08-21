<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Server;

use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Type\Schema;
use Illuminate\Contracts\Support\Arrayable;

/**
 * TODO: Missing class description.
 *
 * @property Schema              $schema
 * @property mixed|null          $rootValue
 * @property mixed|null          $context
 * @property callable|null       $fieldResolver
 * @property array|callable|null $validationRules
 * @property bool|null           $queryBatching
 * @property int|null            $debug
 * @property callable|null       $persistentQueryLoader
 * @property callable|null       $errorFormatter
 * @property callable|null       $errorHandler
 * @property PromiseAdapter|null $promiseAdapter
 *
 * @author Nicolai Agersbæk <na@zitcom.dk>
 *
 * @api
 */
class Config implements Arrayable
{
    
    // FIXME: Extend Symfony\OptionsResolver!
    
    /**
     * @var array
     */
    private $options;
    
    /**
     * @param array|null $options
     */
    public function __construct(?array $options = null)
    {
        $this->options = $options ?? [];
    }
    
    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray() : array
    {
        return $this->options;
    }
    
    /**
     * is utilized for reading data from inaccessible members.
     *
     * @param $name string
     *
     * @return mixed
     * @link https://php.net/manual/en/language.oop5.overloading.php#language.oop5.overloading.members
     */
    public function __get($name)
    {
        // TODO: Implement __get() method.
        throw new \BadMethodCallException(__METHOD__ . ' not yet implemented!');
    }
    
    /**
     * run when writing data to inaccessible members.
     *
     * @param $name  string
     * @param $value mixed
     *
     * @return void
     * @link https://php.net/manual/en/language.oop5.overloading.php#language.oop5.overloading.members
     */
    public function __set($name, $value)
    {
        // TODO: Implement __set() method.
        throw new \BadMethodCallException(__METHOD__ . ' not yet implemented!');
    }
    
    /**
     * is triggered by calling isset() or empty() on inaccessible members.
     *
     * @param $name string
     *
     * @return bool
     * @link https://php.net/manual/en/language.oop5.overloading.php#language.oop5.overloading.members
     */
    public function __isset($name)
    {
        // TODO: Implement __isset() method.
        throw new \BadMethodCallException(__METHOD__ . ' not yet implemented!');
    }
    
}
