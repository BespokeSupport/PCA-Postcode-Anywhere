<?php
/**
 * PCA / Postcode Anywhere lookup
 *
 * PHP Version 5
 *
 * @author   Richard Seymour <web@bespoke.support>
 * @license  MIT
 * @link     https://github.com/BespokeSupport/PCA-Postcode-Anywhere
 */

namespace BespokeSupport\PostcodeAnywhere;

use BespokeSupport\DatabaseWrapper\DatabaseWrapperInterface;
use BespokeSupport\Location\Postcode;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Buzz\Exception\RequestException;

/**
 * Class PostcodeAddress
 * @package BespokeSupport\PostcodeAnywhere
 */
class PostcodeAddress
{
    /**
     * @var null
     */
    protected static $age = null;

    /**
     * @param $postcode
     * @param null $licence
     * @param null $account
     * @param DatabaseWrapperInterface|null $database
     * @param bool $overrideCache
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

        if ($database && $database instanceof DatabaseWrapperInterface) {
            $result = $database->find('paAddress', $postcodeClass->getPostcode(), 'postcode');
            if (!$overrideCache &&
                $result &&
                static::$age &&
                (new \DateTime($result->created)) > (new \DateTime(static::$age))
            ) {
                $return = [
                    'postcode' => $postcodeClass->getPostcode(),
                    'error' => false,
                    'source' => 'cache',
                    'data' => json_decode($result->content, true),
                ];
                return $return;
            }

            $result = self::lookup($postcodeClass->getPostcode(), $account, $licence);

            if (!$result) {
                return [
                    'postcode' => $postcode,
                    'error' => 'Invalid Lookup',
                    'source' => 'cache',
                    'data' => null,
                ];
            }

            if ($result['error']) {
                return $result;
            }

            $insertUpdateSql = <<<TAG
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

            return $result;
        }

        return self::lookup($postcode, $account, $licence);
    }

    /**
     * @param $postcode
     * @param null $licence
     * @param null $account
     * @param DatabaseWrapperInterface|null $database
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
     * @param null $licence
     * @return array|null
     * @throws \Exception
     */
    public static function lookup(
        $postcode,
        $account,
        $licence
    ) {
        if (!$account || !$licence) {
            throw new \Exception('Address API lookup licence not available');
        }

        $postcodeClass = new Postcode($postcode);

        if (!$postcodeClass->getPostcode()) {
            return [
                'postcode' => $postcode,
                'error' => 'Invalid Postcode',
                'source' => 'cache',
                'data' => null,
            ];
        }

        $url = "https://services.postcodeanywhere.co.uk/PostcodeAnywhere/Interactive/RetrieveByParts/v1.00/json.ws?";
        $url .= "&UserName=" . urlencode($account);
        $url .= "&Key=" . urlencode($licence);
        $url .= "&Postcode=" . urlencode($postcodeClass->getPostcode());

        $client = new FileGetContents();
        $client->setTimeout(5);
        $client->setVerifyPeer(false);
        $client->setVerifyHost(false);

        $browser = new Browser($client);

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

        foreach ($json as $address) {
            $isResidential = (!empty($address['Type']) && $address['Type'] == 'Residential') ? true : false;
            $return['data'][] = [
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

        return $return;
    }

    /**
     * @param $age
     * @throws \Exception
     */
    public static function setAge($age)
    {
        if (!is_string($age) || !(new \DateTime($age))) {
            throw new \Exception('Age must be a string in a \DateTime compatible format');
        }

        static::$age = $age;
    }
}
