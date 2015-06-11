<?php

namespace MaResidence\Component\ApiClient;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Subscriber\Cache\CacheStorage;
use GuzzleHttp\Subscriber\Cache\CacheStorageInterface;
use GuzzleHttp\Subscriber\Cache\CacheSubscriber;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use MaResidence\Component\ApiClient\Exception\BadRequestException;
use MaResidence\Component\ApiClient\Exception\InvalidClientException;
use MaResidence\Component\ApiClient\Exception\UnauthorizedClientException;
use MaResidence\Component\ApiClient\Storage\InMemoryStorage;

class Client
{
    const GRANT_TYPE_CLIENT_CREDENTIALS = 'client_credentials';
    const GRANT_TYPE_RESOURCE_OWNER_CREDENTIALS = 'password';

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;
    /**
     * @var CacheStorageInterface
     */
    private $cacheStorage;

    /**
     * @var GuzzleClient
     */
    private $client;

    /**
     * @var string Your ClientId provided by ma-residence.fr
     */
    private $clientId;

    /**
     * @var string Your ClientSecret provided by ma-residence.fr
     */
    private $clientSecret;

    /**
     * @var string The OAuth grant type
     */
    private $grantType;

    /**
     * @var string Your username provided by ma-residence.fr
     */
    private $username;

    /**
     * @var string Your password provided by ma-residence.fr
     */
    private $password;


    /**
     * @var string
     */
    private $endpoint;

    /**
     * @var string
     */
    private $tokenUrl;


    /**
     * @param array            $options
     * @param ClientInterface       $httpClient
     * @param TokenStorageInterface $tokenStorage
     * @param CacheStorageInterface $cacheStorage
     */
    public function __construct(array $options, ClientInterface $httpClient = null, TokenStorageInterface $tokenStorage = null, CacheStorageInterface $cacheStorage = null)
    {
        $this->validateOptions($options);

        $this->clientId = $options['client_id'];
        $this->clientSecret = $options['client_secret'];
        $this->grantType = array_key_exists('grant_type', $options) ? $options['grant_type'] : self::GRANT_TYPE_CLIENT_CREDENTIALS;
        $this->username = $options['username'];
        $this->password = $options['password'];
        $this->endpoint = $options['endpoint'];
        $this->tokenUrl = $options['token_url'];

        $this->client = $httpClient ?: new GuzzleClient(['base_url' => $this->endpoint]);

        $cacheDriver = new ArrayCache();
        if (isset($options['cache_driver']) && $options['cache_driver'] instanceof Cache) {
            $cacheDriver = $options['cache_driver'];
        }
        $ttl = array_key_exists('cache_ttl', $options) ? (int) $options['cache_ttl'] : 300;

        $this->cacheStorage = $cacheStorage ?: new CacheStorage($cacheDriver, sprintf('api_client_%', $this->clientId), $ttl);

        // enable cache proxy
        CacheSubscriber::attach($this->client, [
            'storage' => $this->cacheStorage ,
            'validate' => false
        ]);

        $this->tokenStorage = $tokenStorage ?: new InMemoryStorage();
    }
    /**
     * Authenticate user through the API
     */
    public function authenticate()
    {
        // do not update if token is still valid
        if ($this->isAuthenticated()) {
            return;
        }

        $token = $this->doAuthenticate();
        $token['created_at'] = time();

        $this->tokenStorage->setAccessToken($token);
    }

    public function isAuthenticated()
    {
        return false === $this->tokenStorage->isAccessTokenExpired();
    }

    /**
     * @return array
     */
    public function getAccessToken()
    {
        return $this->tokenStorage->getAccessToken();
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getNews(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('news', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getNewsById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('news', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getAdverts(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('adverts', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getAdvertById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('adverts', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getAdvertCategories(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('advertcategories', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getAdvertCategoryById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('advertcategories', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getEvents(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('events', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getEventById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('events', $id, $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getHabitationById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('habitations', $id, $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getHabitationGroupById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('habitationgroups', $id, $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getRecommendationById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('recommendations', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     *
     * @return mixed
     */

    /**
     * @param array $userData
     * @param       $version
     *
     * @return array
     */
    public function postUser(array $userData, $version)
    {
        $data['user'] = $userData;
        $body = null;

        try {
            $response = $this->post('/api/users', $version, $data);
            $body = $response->json();
        } catch (RequestException $e) {
            // If user already exists
            if ($e->getCode() == 409 && null !== $e->getResponse()) {
                $body = $e->getResponse()->json();
            }
        }

        if (! is_array($body) || ! array_key_exists('user', $body)) {
            throw new \LogicException(
                'The User was successfully created but an unexpected response was return from the MR API'
            );
        }

        $user = $body['user'];

        if (! is_array($user) || ! array_key_exists('id', $user) || ! array_key_exists('self', $user)) {
            throw new \LogicException(
                'The User was successfully created but an unexpected response was return from the MR API. Expected key id and self.'
            );
        }

        return $body['user'];
    }

    /**
     * @param array $advertData
     * @param       $version
     *
     * @return mixed
     */
    public function postAdvert(array $advertData, $version)
    {
        $data['advert'] = $advertData;
        $response = $this->post('/api/adverts', $version, $data);

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('advert', $body)) {
            throw new \LogicException(
                'The Advert was successfully created but an unexpected response was return from the MR API'
            );
        }

        $advert = $body['advert'];

        if (! is_array($advert) || ! array_key_exists('id', $advert) || ! array_key_exists('self', $advert)) {
            throw new \LogicException(
                'The Advert was successfully created but an unexpected response was return from the MR API. Expected key id and self.'
            );
        }

        return $body['advert'];
    }

    /**
     * @param array $recommendationData
     * @param       $version
     *
     * @return mixed
     */
    public function postRecommendation(array $recommendationData, $version)
    {
        $data['recommendation'] = $recommendationData;
        $response = $this->post('/api/recommendations', $version, $data);

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('recommendation', $body)) {
            throw new \LogicException(
                'The Recommendation was successfully created but an unexpected response was return from the MR API'
            );
        }

        $recommendation = $body['recommendation'];

        if (! is_array($recommendation) || ! array_key_exists('id', $recommendation) || ! array_key_exists('self', $recommendation)) {
            throw new \LogicException(
                'The Recommendation was successfully created but an unexpected response was return from the MR API. Expected key id and self.'
            );
        }

        return $body['recommendation'];
    }

    /**
     * @param string $id ID of the advert to share
     * @param array $shareData
     * @param       $version
     *
     * @return mixed
     */
    public function postAdvertShare($id, array $shareData, $version)
    {
        $data['share'] = $shareData;
        $url = sprintf('/api/adverts/%s/shares', $id);

        $response = $this->post($url, $version, $data);

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('share', $body)) {
            throw new \LogicException(
                'The Share was successfully created but an unexpected response was return from the MR API'
            );
        }

        $share = $body['share'];

        if (! is_array($share) || ! array_key_exists('email', $share)) {
            throw new \LogicException(
                'The Share was successfully created but an unexpected response was return from the MR API. Expected key email.'
            );
        }

        return $body['share'];
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getUserById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('users', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getAssociations(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('associations', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getAssociationById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('associations', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getShops(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('shops', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    public function getShopById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('shops', $id, $options, $forceReValidation);
    }

    /**
     * @param $resource
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    private function getResources($resource, array $options = [], $forceReValidation = false)
    {
        $url = sprintf('/api/%s', $resource);

        return $this->get($url, $options, $forceReValidation);
    }

    /**
     * @param $resource
     * @param $id
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return mixed
     */
    private function getResourceById($resource, $id, array $options = [], $forceReValidation = false)
    {
        $url = sprintf('/api/%s/%s', $resource, $id);

        return $this->get($url, $options, $forceReValidation);
    }

    /**
     * @param $url
     * @param array $options
     * @param bool $forceReValidation
     *
     * @return array
     */
    private function get($url, array $options = [], $forceReValidation = false)
    {
        $token = $this->tokenStorage->getAccessToken();

        $requestOptions = [];

        foreach ($options as $key => $value) {
            if ($key != 'version' && $key != 'access_token') {
                $requestOptions['query'][$key] = $value;
            }
        }

        if (array_key_exists('version', $options)) {
            $requestOptions['headers']['Accept'] = sprintf('application/ma-residence.v%d', $options['version']);
        }

        $requestOptions['query']['access_token'] = $token['access_token'];

        $requestOptions['config']['cache.disable'] = $forceReValidation;

        $response = $this->client->get($url, $requestOptions);

        if (200 !== $response->getStatusCode()) {
            throw new \LogicException('An error occurred when trying to GET data from MR API');
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new \LogicException('The response providing from MR API is not valid');
        }

        return $data;
    }

    /**
     * @param $url
     * @param $version
     * @param array $data
     * @param string $bodyEncoding
     *
     * @return \GuzzleHttp\Message\FutureResponse|\GuzzleHttp\Message\ResponseInterface|\GuzzleHttp\Ring\Future\FutureInterface|null
     */
    private function post($url, $version, array $data = [], $bodyEncoding = 'json')
    {
        $token = $this->tokenStorage->getAccessToken();

        $requestOptions = [];

        foreach ($data as $key => $value) {
            $requestOptions['body'][$key] = $value;
        }

        // Encode the body to be fully compatible with REST
        if ('json' == $bodyEncoding) {
            $requestOptions['headers']['Content-type'] = 'application/json';
            $requestOptions['body'] = json_encode($requestOptions['body']);
        }

        $requestOptions['headers']['Accept'] = sprintf('application/ma-residence.v%d', $version);

        $requestOptions['query']['access_token'] = $token['access_token'];

        $response = $this->client->post($url, $requestOptions);

        if (201 !== $response->getStatusCode()) {
            throw new \LogicException('An error occurred when trying to POST data to MR API');
        }

        return $response;
    }

    private function validateOptions(array $options)
    {
        foreach (['client_id', 'client_secret', 'username', 'password', 'endpoint', 'token_url'] as $optionName) {
            if (!array_key_exists($optionName, $options)) {
                throw new \InvalidArgumentException(sprintf('Missing mandatory "%s" option', $optionName));
            }
        }

        if (array_key_exists('grant_type', $options) && !in_array($options['grant_type'], $this->getAllowedGrantTypes())) {
            throw new \InvalidArgumentException(sprintf('Grant type "%s" is not managed by the Client', $options['grant_type']));
        }
    }

    /**
     * @return array
     */
    private function buildAuthenticationQuery()
    {
        $query = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => $this->grantType,
        ];

        if (self::GRANT_TYPE_RESOURCE_OWNER_CREDENTIALS === $this->grantType) {
            $query['username'] = $this->username;
            $query['password'] = $this->password;
        }

        return $query;
    }

    /**
     * @return array
     */
    private function getAllowedGrantTypes()
    {
        return array(
            self::GRANT_TYPE_CLIENT_CREDENTIALS,
            self::GRANT_TYPE_RESOURCE_OWNER_CREDENTIALS,
        );
    }

    /**
     * @return mixed
     * @throws BadRequestException
     * @throws InvalidClientException
     * @throws UnauthorizedClientException
     */
    private function doAuthenticate()
    {
        $options = [
            'query' => $this->buildAuthenticationQuery(),
        ];

        try {
            $response = $this->client->get($this->tokenUrl, $options);
        } catch (BadRequestException $e) {
            $response = $e->getResponse();
            $body = $response->json();

            if (array_key_exists('error', $body) && $body['error'] == 'invalid_client') {
                $message = array_key_exists('error_description', $body) ? $body['error_description'] : 'Error description not available';

                throw new InvalidClientException($message, $response->getReasonPhrase(), $response->getStatusCode(), $response->getEffectiveUrl(), $body);
            }

            if (array_key_exists('error', $body) && $body['error'] == 'unauthorized_client') {
                $message = array_key_exists('error_description', $body) ? $body['error_description'] : 'Error description not available';

                throw new UnauthorizedClientException($message, $response->getReasonPhrase(), $response->getStatusCode(), $response->getEffectiveUrl(), $body);
            }

            throw new BadRequestException($e->getMessage(), $response->getReasonPhrase(), $response->getStatusCode(), $response->getEffectiveUrl(), $body);
        }

        if (200 !== $response->getStatusCode()) {
            throw new BadRequestException('An error occurred when trying to GET token data from MR API', $response->getReasonPhrase(), $response->getStatusCode(), $response->getEffectiveUrl());
        }

        return $response->json();
    }
}
