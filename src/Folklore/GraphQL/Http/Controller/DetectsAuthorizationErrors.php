<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Http\Controller;

use GraphQL\Error\Error;
use Illuminate\Support\Arr;

/**
 * Detects authorization issues from GraphQL query resolution results.
 *
 * @author Nicolai Agersbæk <nicolai.agersbaek@team.blue>
 *
 * @internal
 */
trait DetectsAuthorizationErrors
{
    
    /**
     * @param array $errors
     *
     * @return bool
     */
    final protected function isAuthorized(array $errors) : bool
    {
        // FIXME: Missing tests!
        return \array_reduce($errors, [$this, 'isAuthorizedReduction'], true);
    }
    
    /**
     * @param bool                     $authorized
     * @param array|\ArrayAccess|Error $error
     *
     * @return bool
     */
    final protected function isAuthorizedReduction(bool $authorized, $error) : bool
    {
        // FIXME: Missing tests!
        // TODO: Are other types than `array|\ArrayAccess|Error` ever provided?
        return !Arr::accessible($error)
            ? $authorized
            : $authorized && !Arr::get($error, 'message') === 'Unauthorized';
    }
}
