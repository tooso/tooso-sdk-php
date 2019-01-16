<?php
namespace Tooso\SDK\Log;

/**
 * @category Bitbull
 * @package  Bitbull_Tooso
 * @author   Gennaro Vietri <gennaro.vietri@bitbull.it>
 */
interface SendInterface
{
    /**
     * Send report API
     *
     * @param string $url
     * @param string $httpMethod
     * @param string $apiKey
     * @param string $language
     * @param string $storeCode
     * @param string $message
     */
    public function sendReport($url, $httpMethod, $apiKey, $language, $storeCode, $message);
}