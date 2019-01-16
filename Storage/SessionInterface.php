<?php
namespace Tooso\SDK\Storage;

/**
 * @category Bitbull
 * @package  Bitbull_Tooso
 * @author   Gennaro Vietri <gennaro.vietri@bitbull.it>
 */
interface SessionInterface
{
    /**
     * Store Search ID into session
     *
     * @param string $value
     */
    public function setSearchId($value);

    /**
     * Get Search ID from session
     *
     * @return string
     */
    public function getSearchId();
}