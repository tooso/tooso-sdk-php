<?php
namespace Tooso\SDK\Log;

use \Tooso\SDK\Exception;

/**
 * @category Bitbull
 * @package  Bitbull_Tooso
 * @author   Gennaro Vietri <gennaro.vietri@bitbull.it>
 */
interface LoggerInterface
{
    /**
     * Logging facility
     *
     * @param string $message
     * @param int $level
     */
    public function log($message, $level = null);

    /**
     * @param Exception $e
    */
    public function logException(Exception $e);

    /**
     * @param string $message
     */
    public function debug($message);
}