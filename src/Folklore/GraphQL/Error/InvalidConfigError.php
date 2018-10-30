<?php
declare(strict_types = 1);


namespace Folklore\GraphQL\Error;

/**
 * Thrown when a configured option is invalid.
 *
 * @author Nicolai Agersbæk <na@smartweb.dk>
 *
 * @api
 */
class InvalidConfigError extends \RuntimeException implements ExceptionInterface
{

}
