<?php
/**
 * PCA / Postcode Anywhere lookup.
 *
 * PHP Version 5
 *
 * @author   Richard Seymour <web@bespoke.support>
 * @license  MIT
 *
 * @link     https://github.com/BespokeSupport/PCA-Postcode-Anywhere
 */
namespace BespokeSupport\PostcodeAnywhere;

use BespokeSupport\DatabaseWrapper\DatabaseWrapperInterface;
use BespokeSupport\Location\Postcode;
use Buzz\Browser;
use Buzz\Client\Curl;
use Buzz\Client\FileGetContents;
use Buzz\Exception\RequestException;

/**
 * Class PostcodeAddress.
 */
class PostcodeAddress
{
    /**
     * @var null
     */
    protected static $age = null;

    /**
     * PostcodeAddress constructor.
     */
    public function __construct()
    {
        throw new \Exception('PostcodeAddress cannot be created');
    }

    /**
     * @param $database
     * @param Postcode $postcodeClass
     * @param bool     $overrideCache
     *
     * @return array|bool
     */
    public static function cacheFind(
        DatabaseWrapperInterface $database = null,
        Postcode $postcodeClass = null,
        $overrideCache = false
    ) {
        if (!$database || !($database instanceof DatabaseWrapperInterface)) {
            return false;
        }

        if ($overrideCache) {
            return false;
        }

        $result = $database->find('paAddress', $postcodeClass->getPostcode(), 'postcode');

        if (!$result) {
            return false;
        }

        if (!static::$age ||
            (new \DateTime($result->created)) > static::$age
        ) {
            return [
                'postcode' => $postcodeClass->getPostcode(),
                'error' => false,
                'source' => 'cache',
                'data' => json_decode($result->content, true),
            ];
        }

        return false;
    }

    /**
     * @param DatabaseWrapperInterface $database
     * @param Postcode                 $postcodeClass
     * @param $result
     *
     * @return bool
     */
    public static function cacheSave(
        DatabaseWrapperInterface $database = null,
        Postcode $postcodeClass = null,
        $result = null
    ) {
        if (!$database || !$postcodeClass || !$result || empty($result['data'])) {
            return false;
        }

        $insertUpdateSql = <<<'TAG'
INSERT INTO paAddress
(postcode,content)
VALUES
(:postcode,:content)
ON DUPLICATE KEY UPDATE
content=:content
TAG;

        $database->sqlInsertUpdate(
            $insertUpdateSql,
            [
                'postcode' => $postcodeClass->getPostcode(),
                'content' => json_encode($result['data'], true),
            ]
        );

        return true;
    }

    /**
     * @param $addresses
     *
     * @return array
     */
    public static function convertApiDataToResponse($addresses)
    {
        $data = [];
        foreach ($addresses as $address) {
            $isResidential = (!empty($address['Type']) && $address['Type'] == 'Residential') ? true : false;
            $data[] = [
                'residential' => $isResidential,
                'name' => (!$isResidential) ? $address['Company'] : null,
                'line1' => $address['Line1'],
                'line2' => $address['Line2'],
                'street' => $address['PrimaryStreet'],
                'town' => $address['PostTown'],
                'county' => $address['County'],
                'country' => $address['CountryName'],
                'postcode' => $address['Postcode'],
                'id' => $address['Udprn'],
            ];
        }

        return $data;
    }

    /**
     * @param $postcode
     * @param null                          $licence
     * @param null                          $account
     * @param DatabaseWrapperInterface|null $database
     * @param bool                          $overrideCache
     *
     * @return array|null|object
     */
    public static function get(
        $postcode,
        $account = null,
        $licence = null,
        $database = null,
        $overrideCache = false
    ) {
        $postcodeClass = new Postcode($postcode);

        if (!$postcodeClass->getPostcode()) {
            return [
                'postcode' => $postcode,
                'error' => 'Invalid Postcode',
                'source' => 'cache',
                'data' => null,
            ];
        }

        if (($result = self::cacheFind($database, $postcodeClass, $overrideCache))) {
            return $result;
        }

        $result = self::lookup($postcodeClass->getPostcode(), $account, $licence);

        if (!$result || !isset($result['error']) || $result['error']) {
            return [
                'postcode' => $postcode,
                'error' => $result['error'],
                'source' => 'api',
                'data' => null,
            ];
        }

        self::cacheSave($database, $postcodeClass, $result);

        return $result;
    }

    /**
     * @return Curl|FileGetContents
     */
    public static function getClient()
    {
        if (in_array('curl', get_loaded_extensions())) {
            $client = new Curl();
        } else {
            $client = new FileGetContents();
        }

        $client->setVerifyPeer(false);
        $client->setVerifyHost(false);

        return $client;
    }

    /**
     * @param $endpoint
     * @param $version
     * @param array $params
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function getUrl($endpoint, $version, array $params)
    {
        if (!array_key_exists('Key', $params)) {
            throw new \Exception('Address API lookup licence not available');
        }

        $paramsEncoded = http_build_query($params);

        $url = sprintf(
            'https://services.postcodeanywhere.co.uk/PostcodeAnywhere/Interactive/%s/%s/json.ws?%s',
            $endpoint,
            $version,
            $paramsEncoded
        );

        return $url;
    }

    /**
     * @param $postcode
     * @param null                          $licence
     * @param null                          $account
     * @param DatabaseWrapperInterface|null $database
     *
     * @return string
     */
    public static function json(
        $postcode,
        $account = null,
        $licence = null,
        $database = null
    ) {
        $result = self::get($postcode, $account, $licence, $database);

        return json_encode($result);
    }

    /**
     * @param $postcode
     * @param null $account
     * @param null $apiLicenceKey
     *
     * @return array|null
     *
     * @throws \Exception
     */
    public static function lookup(
        $postcode,
        $account,
        $apiLicenceKey
    ) {
        $postcodeClass = new Postcode($postcode);

        if (!$postcodeClass->getPostcode()) {
            return [
                'postcode' => $postcode,
                'error' => 'Invalid Postcode',
                'source' => 'cache',
                'data' => null,
            ];
        }

        $url = self::getUrl(
            'RetrieveByParts',
            '1.00',
            [
                'Key' => $apiLicenceKey,
                'Postcode' => $postcodeClass->getPostcode(),
            ]
        );

        $browser = new Browser(self::getClient());

        try {
            $response = $browser->get($url);
        } catch (RequestException $e) {
            return [
                'postcode' => $postcode,
                'error' => 'Address lookup fail',
                'source' => 'api',
                'data' => null,
            ];
        }

        if (!$response || !($content = $response->getContent())) {
            return [
                'postcode' => $postcode,
                'error' => 'Address lookup fail',
                'source' => 'api',
                'data' => null,
            ];
        }

        $json = json_decode($content, true);

        if (!$json || (count($json) == 1 && isset($json[0]['Error']))) {
            $return = [
                'postcode' => $postcodeClass->getPostcode(),
                'error' => 'Problem fetching Postcode addresses',
                'source' => 'api',
                'data' => [],
            ];

            return $return;
        }

        $return = [
            'postcode' => $postcodeClass->getPostcode(),
            'error' => false,
            'source' => 'api',
            'data' => [],
        ];

        $return['data'] = self::convertApiDataToResponse($json);

        return $return;
    }

    /**
     * @param $age
     *
     * @throws \Exception
     */
    public static function setAge($age)
    {
        $exceptionMessage = 'Age must be a string in a \DateTime compatible format';
        if (!is_string($age)) {
            throw new \Exception($exceptionMessage);
        }

        try {
            static::$age = new \DateTime($age);
        } catch (\Exception $exception) {
            throw new \Exception($exceptionMessage);
        }
    }
}
