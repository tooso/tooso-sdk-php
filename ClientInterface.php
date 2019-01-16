<?php
namespace Tooso\SDK;

use \Tooso\SDK\Index\Result as IndexResult;
use \Tooso\SDK\Search\Result as SearchResult;


/**
 * @category Bitbull
 * @package  Bitbull_Tooso
 * @author   Fabio Gollinucci <fabio.gollinucci@bitbull.it>
 */
interface ClientInterface
{
    /**
     * @param string $apiKey
     * @param string $version
     * @param string $apiBaseUrl
     * @param string $language
     * @param string $storeCode
     */
    public function __construct($apiKey, $version, $apiBaseUrl, $language, $storeCode);

    /**
     * Perform a search
     *
     * @param string $query
     * @param boolean $typoCorrection
     * @param array $extraParams
     * @param boolean $enriched
     * @param int $page
     * @param int $limit
     * @return SearchResult
     * @throws Exception
     */
    public function search($query, $typoCorrection = true, $extraParams = array(), $enriched = false, $page = null, $limit = null);

    /**
     * Send data to index
     *
     * @param string $csvContent
     * @param array $params
     * @return IndexResult
     * @throws Exception
     */
    public function index($csvContent, $params);

    /**
     * Generate uuid
     *
     * @return string
     */
    public function getUuid();
}