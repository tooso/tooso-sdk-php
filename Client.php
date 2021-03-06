<?php
namespace Tooso\SDK;

use \Tooso\SDK\Index\Result as IndexResult;
use \Tooso\SDK\Log\LoggerInterface;
use \Tooso\SDK\Log\SendInterface;
use \Tooso\SDK\Search\Result as SearchResult;
use \Tooso\SDK\Storage\SessionInterface;
use \ZipArchive;
use \CurlFile;

/**
 * @category Bitbull
 * @package  Bitbull_Tooso
 * @author   Gennaro Vietri <gennaro.vietri@bitbull.it>
*/
class Client implements ClientInterface
{
    const HTTP_METHOD_GET = 'GET';
    const HTTP_METHOD_POST = 'POST';
    const TRACKING_AGENT_HEADER = 'X-Tooso-Agent';
    const FORCE_ERROR = false; //DEBUG: force client to trigger error

    const INDEX_DOC_TYPE = 1;
    const INDEX_EXTENSION = 'csv';

    const ARRAY_VALUES_SEPARATOR = ',';

    /**
     * Base url for API calls
     *
     * @var string
     */
    protected $_baseUrl;

    /**
     * API key
     *
     * @var string
    */
    protected $_apiKey;

    /**
     * Store language
     *
     * @var string
     */
    protected $_language;

    /**
     * Store code
     *
     * @var string
     */
    protected $_storeCode;

    /**
     * API version
     *
     * @var null|string
     */
    protected $_version;

    /**
     * Timeout for API connection wait
     * in milliseconds
     *
     * @var int
     */
    protected $_connectTimeout = 2000;

    /**
     * Timeout for API response wait
     * in milliseconds
     *
     * @var int
     */
    protected $_timeout = 4000;

    /**
     * @var stdClass
    */
    protected $_response = null;

    /**
     * @var SendInterface
    */
    protected $_reportSender;

    /**
     * @var LoggerInterface
    */
    protected $_logger;

    /**
     * @var SessionInterface
     */
    protected $_sessionStorage;

    /**
     * @var string
     */
    protected $_agent = 'Unknown';

    /**
     * @param string $apiKey
     * @param string $version
     * @param string $apiBaseUrl
     * @param string $language
     * @param string $storeCode
     * @param LoggerInterface $logger
     * @param SendInterface $reportSender
     * @param SessionInterface $sessionStorage
     * @param string $agent
     */
    public function __construct($apiKey, $version, $apiBaseUrl, $language, $storeCode, $logger = null, $reportSender = null, $sessionStorage = null, $agent = 'Unknown')
    {
        $this->_apiKey = $apiKey;
        $this->_version = $version;
        $this->_baseUrl = $apiBaseUrl;
        $this->_language = strtolower($language);
        $this->_storeCode = $storeCode;
        $this->_logger = $logger;
        $this->_reportSender = $reportSender;
        $this->_sessionStorage = $sessionStorage;
        $this->_agent = $agent;
    }

    /**
     * @inheritdoc
     */
    public function search($query, $typoCorrection = true, $extraParams = array(), $enriched = false, $page = null, $limit = null)
    {
        if(self::FORCE_ERROR){
            $query = null;
        }

        if($page === null){
            $page = 0;
        }
        if($limit === null){
            $limit = 250;
        }

        $path = '/search';
        $params = array_merge(
            array(
                'q' => $query,
                'typoCorrection' => ($typoCorrection ? 'true' : 'false'),
                'page' => $page,
                'limit' => $limit,
                'channel' => 'web'
            ),
            (array)$extraParams
        );

        if($enriched){
            $params['debug'] = 1;
        }

        try {
            $response = $this->doRequest($path, self::HTTP_METHOD_GET, $params);
            $result = new SearchResult($response);
            if ($this->_sessionStorage) {
                $searchId = $result->getSearchId();
                $this->_sessionStorage->setSearchId($searchId);
                if($this->_logger){
                    $this->_logger->debug('Session: set search id to '.$searchId);
                }
            }

        } catch (Exception $e) {
            $response = $e->getResponse();
            if($response != null && $this->_sessionStorage){
                $result = new SearchResult($response);
                $searchId = $result->getSearchId();
                $this->_sessionStorage->setSearchId($searchId);
                if($this->_logger){
                    $this->_logger->debug('Session: set search id to '.$searchId);
                }
            }
            throw $e;
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function index($csvContents, $params)
    {
        $tmpZipFile = sys_get_temp_dir() . '/tooso_index_' . microtime() . '.zip';

        if($this->_logger){
            $this->_logger->debug('Temporary zip file: ' . $tmpZipFile);
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpZipFile, ZipArchive::CREATE)) {
            if (is_array($csvContents) === true) {
                foreach ($csvContents as $filename => $csvContent) {
                    $zip->addFromString($filename, $csvContent);
                }
            } else {
                $zip->addFromString('magento_catalog.csv', $csvContents);
            }

            $zip->close();
        } else {
            throw new Exception('Error creating zip file for reindex', 0);
        }

        if($this->_logger){
            $this->_logger->debug('Start uploading zipfile');
        }

        if(!isset($params['ACCESS_KEY_ID']) || !isset($params['SECRET_KEY']) || !isset($params['BUCKET']) || !isset($params['PATH'])){
            $this->_removeTemporaryFile($tmpZipFile);
            throw new Exception('Index params are not correct', 0);
        }

        $accessKeyId = $params["ACCESS_KEY_ID"];
        $secretKey = $params["SECRET_KEY"];
        $bucket = $params["BUCKET"];
        $region = 'us-west-2';
        $fileName = $params["PATH"].round(microtime(true) * 1000)."_".$this->getUuid()."_".$this->_apiKey.'.zip';
        $fileType = 'application/zip';

        $policy = base64_encode(json_encode(array(
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
            'conditions' => array(
                array('bucket' => $bucket),
                array('starts-with', '$key', ''),
                array('starts-with', '$Content-Type', '')
            )
        )));

        $signature = hash_hmac('sha1', $policy, $secretKey, true);
        $signature = base64_encode($signature);

        $url = 'https://' . $bucket . '.s3-' . $region . '.amazonaws.com';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'key' => $fileName,
            'AWSAccessKeyId' =>  $accessKeyId,
            'policy' =>  $policy,
            'Content-Type' =>  $fileType,
            'signature' => $signature,
            'file' => new CurlFile(realpath($tmpZipFile), $fileType, $fileName)
        ));

        $output = curl_exec($ch);
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errorNumber = curl_errno($ch);

        curl_close($ch);

        if($this->_logger) {
            $this->_logger->debug("Raw response: " . print_r($output, true));
        }

        $result = new IndexResult($output, $httpStatusCode);

        if (false === $output) {

            if ($this->_reportSender) {
                $message = 'cURL error = ' . $error . ' - Error number = ' . $errorNumber;

                $this->_reportSender->sendReport($url, "S3", $accessKeyId, $this->_language, $this->_storeCode, $message);
            }

            $this->_removeTemporaryFile($tmpZipFile);
            throw new Exception('cURL error = ' . $error, $errorNumber);

        }else{

            if (!$result->isValid()) {

                if ($this->_reportSender) {
                    $message = 'Error description = ' . $result->getErrorMessage();
                    $this->_reportSender->sendReport($url, "PUT", $accessKeyId, $this->_language, $this->_storeCode, $message);
                }

                $this->_removeTemporaryFile($tmpZipFile);
                throw new Exception($result->getErrorMessage(), $result->getErrorCode());
            } else {
                if($this->_logger){
                    $this->_logger->debug("End uploading zipfile to s3://".$bucket."/".$fileName);
                }

                $this->_removeTemporaryFile($tmpZipFile);
                return $result;
            }
        }
    }

    /**
     * Remove temporary zip file
     *
     * @param $tmpZipFile
     */
    private function _removeTemporaryFile($tmpZipFile)
    {
        if (file_exists($tmpZipFile)) {
            unlink($tmpZipFile);
            if($this->_logger){
                $this->_logger->debug("Temporary zipfile '$tmpZipFile' delete");
            }
        }
    }

    /**
     * Build and execute request via CURL.
     *
     * @param string $path
     * @param string $httpMethod
     * @param array $params\
     * @param int $timeout
     * @param boolean $ignoreResponse
     * @return Response
     * @throws Exception
    */
    public function doRequest($path, $httpMethod = self::HTTP_METHOD_GET, $params = array(), $timeout = null, $ignoreResponse = false)
    {
        $url = $this->_buildUrl($path, $params);

        if($this->_logger) {
            $this->_logger->debug("Performing API request to url: " . $url . " with method: " . $httpMethod);
            $this->_logger->debug("Params: " . print_r($params, true));
        }

        $ch = curl_init();

        if ($httpMethod == self::HTTP_METHOD_POST) {
            curl_setopt($ch, CURLOPT_POST, true);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->_connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, !is_null($timeout) ? $timeout : $this->_timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            self::TRACKING_AGENT_HEADER.': '.$this->_agent
        ]);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $output = curl_exec($ch);
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errorNumber = curl_errno($ch);

        curl_close($ch);

        if($this->_logger) {
            if ($ignoreResponse === false) {
                $this->_logger->debug("Raw response: " . print_r($output, true));
            }else{
                $this->_logger->debug("Raw response: status code $httpStatusCode");
            }
        }

        if (false === $output) {

            if ($this->_reportSender) {
                $message = 'cURL error = ' . $error . ' - Error number = ' . $errorNumber;

                $this->_reportSender->sendReport($url, $httpMethod, $this->_apiKey, $this->_language, $this->_storeCode, $message);
            }

            throw new Exception('cURL error = ' . $error, $errorNumber);

        }else{
            $response = json_decode($output);
            $result = new Response();
            $result->setResponse($response);

            if ($httpStatusCode != 200) {

                if ($this->_reportSender) {
                    $message = 'API unavailable, HTTP STATUS CODE = ' . $httpStatusCode;

                    if ($result->getErrorDebugInfo() != null) {
                        $message .= "\n\nDebugInfo: " . $result->getErrorDebugInfo();
                    }

                    $this->_reportSender->sendReport($url, $httpMethod, $this->_apiKey, $this->_language, $this->_storeCode, $message);
                }

                $e = new Exception('API unavailable, HTTP STATUS CODE = ' . $httpStatusCode, 0);
                $e->setResponse($result);
                throw $e;

            } else if ($httpStatusCode == 200 && $ignoreResponse === false && !$result->isValid()) {

                if ($this->_reportSender) {
                    $message = 'Error description = ' . $result->getErrorDescription() . "\n"
                        . "Error code = " . $result->getErrorCode() . "\n"
                        . "Debug info = " . $result->getErrorDebugInfo();

                    $this->_reportSender->sendReport($url, $httpMethod, $this->_apiKey, $this->_language, $this->_storeCode, $message);
                }

                $e = new Exception($result->getErrorDescription(), $result->getErrorCode());
                $e->setDebugInfo($result->getErrorDebugInfo());
                $e->setResponse($result);
                throw $e;

            }

            return $result;

        }

    }

    /**
     * Build an url for an API call
     *
     * @param string $path
     * @param array $params
     * @return string
     * @throws Exception
    */
    protected function _buildUrl($path, $params)
    {
        if($this->_logger){
            $this->_logger->debug(print_r($params, true));
        }

        if (filter_var($this->_baseUrl, FILTER_VALIDATE_URL) === false) {
            $message = 'API base URL missing or invalid: "' . $this->_baseUrl . '"';

            if ($this->_reportSender) {
                $this->_reportSender->sendReport('', '', $this->_apiKey, $this->_language, $this->_storeCode, $message);
            }

            throw new Exception($message, 0);
        }

        $baseUrl = $this->_baseUrl;
        if(substr($baseUrl, -1) != '/'){
            $baseUrl .= '/';
        }

        $url = $baseUrl;
        if ($this->_version !== null) {
            $url .= 'v'.$this->_version;
        }
        $url .= $path;

        $language = $this->_language;
        if($language == null){
            $language = $this->_storeCode;
        }
        $queryString = array(
            'ul=' . $language,
            'tid=' . $this->_apiKey,
            'v=' . $this->_version,
            'z=' . $this->getUuid(),
        );

        foreach ($params as $key => $value) {
            $queryString[] = $key . '=' . urlencode($value);
        }

        $url .= '?' . implode('&', $queryString);

        return $url;
    }

    /**
     * Generate uuid
     *
     * @return string
     */
    public function getUuid(){
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}
