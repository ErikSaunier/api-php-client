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
     * @param array                 $options
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

        $this->cacheStorage = $cacheStorage ?: new CacheStorage($cacheDriver, sprintf('api_client_%', $this->clientId), 300);

        // enable cache proxy
        CacheSubscriber::attach($this->client, [
            'storage' => $this->cacheStorage,
            'validate' => false,
        ]);

        $this->tokenStorage = $tokenStorage ?: new InMemoryStorage();
    }
    /**
     * Authenticate user through the API.
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
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getTimelineNeighborhood(array $options = [], $forceReValidation = false)
    {
        return $this->get('/api/timeline/dans-mon-quartier', $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getNewsById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('news', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getAdvertById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('adverts', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getAdvertCategoryById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('advertcategories', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getRecommendationById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('recommendations', $id, $options, $forceReValidation);
    }

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
            } else {
                throw $e;
            }
        }

        if (!is_array($body)) {
            throw new \LogicException(
                'The User was successfully created but an unexpected response was return from the MR API'
            );
        }

        return array_key_exists('user', $body) ? $body['user'] : $body;
    }

    /**
     * Change the user information.
     *
     * @param string $id
     * @param array  $data
     * @param int    $version
     *
     * @return mixed
     */
    public function putUser($id, array $data, $version)
    {
        $url = sprintf('/api/users/%s', $id);
        $response = $this->put($url, $version, $data);

        return $response->json();
    }

    /**
     * Change the user habitation.
     *
     * @param string $id
     * @param array  $data
     * @param int    $version
     *
     * @return mixed
     */
    public function putUserHabitation($id, array $data, $version)
    {
        $url = sprintf('/api/users/%s/habitation', $id);
        $response = $this->put($url, $version, $data);

        return $response->json();
    }

    /**
     * Change the user password.
     *
     * @param string $id
     * @param array  $data
     * @param int    $version
     *
     * @return mixed
     */
    public function putUserPassword($id, array $data, $version)
    {
        $url = sprintf('/api/users/%s/password', $id);
        $response = $this->put($url, $version, $data);

        return $response->json();
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

        if (!is_array($body) || !array_key_exists('advert', $body)) {
            throw new \LogicException(
                'The Advert was successfully created but an unexpected response was return from the MR API'
            );
        }

        $advert = $body['advert'];

        if (!is_array($advert) || !array_key_exists('id', $advert) || !array_key_exists('self', $advert)) {
            throw new \LogicException(
                'The Advert was successfully created but an unexpected response was return from the MR API. Expected key id and self.'
            );
        }

        return $body['advert'];
    }

    /**
     * @param array $eventData
     * @param       $version
     *
     * @return mixed
     */
    public function postEvent(array $eventData, $version)
    {
        $data['event'] = $eventData;
        $response = $this->post('/api/events', $version, $data);

        $body = $response->json();

        if (!is_array($body)) {
            throw new \LogicException(
                'The Event was successfully created but an unexpected response was return from the MR API'
            );
        }

        return $body;
    }

    /**
     * @param array $newsData
     * @param       $version
     *
     * @return mixed
     */
    public function postNews(array $newsData, $version)
    {
        $data['news'] = $newsData;
        $response = $this->post('/api/news', $version, $data);

        $body = $response->json();

        if (!is_array($body)) {
            throw new \LogicException(
                'The News was successfully created but an unexpected response was return from the MR API'
            );
        }

        return $body;
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

        if (!is_array($body) || !array_key_exists('recommendation', $body)) {
            throw new \LogicException(
                'The Recommendation was successfully created but an unexpected response was return from the MR API'
            );
        }

        $recommendation = $body['recommendation'];

        if (!is_array($recommendation) || !array_key_exists('id', $recommendation) || !array_key_exists('self', $recommendation)) {
            throw new \LogicException(
                'The Recommendation was successfully created but an unexpected response was return from the MR API. Expected key id and self.'
            );
        }

        return $body['recommendation'];
    }

    /**
     * @param string $id        ID of the advert to share
     * @param array  $shareData
     * @param        $version
     *
     * @return mixed
     */
    public function postAdvertShare($id, array $shareData, $version)
    {
        $data['share'] = $shareData;
        $url = sprintf('/api/adverts/%s/shares', $id);

        $response = $this->post($url, $version, $data);

        $body = $response->json();

        if (!is_array($body) || !array_key_exists('share', $body)) {
            throw new \LogicException(
                'The Share was successfully created but an unexpected response was return from the MR API'
            );
        }

        $share = $body['share'];

        if (!is_array($share) || !array_key_exists('email', $share)) {
            throw new \LogicException(
                'The Share was successfully created but an unexpected response was return from the MR API. Expected key email.'
            );
        }

        return $body['share'];
    }

    /**
     * @param $id
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getUserById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('users', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getAssociationById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('associations', $id, $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getLocalGovernmentById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('localgovernments', $id, $options, $forceReValidation);
    }

    /**
     * @param $entity
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return array
     */
    public function getCategories($entity, array $options = [], $forceReValidation = false)
    {
        $url = sprintf('/api/%s/categories', $entity);

        return $this->get($url, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getAssociationCategories(array $options = [], $forceReValidation = false)
    {
        return $this->getCategories('association', $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getShopCategories(array $options = [], $forceReValidation = false)
    {
        return $this->getCategories('shop', $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getShopById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('shops', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getTrustees(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('trustees', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getTrusteeById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('trustees', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getEstates(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('estates', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getEstateById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('estates', $id, $options, $forceReValidation);
    }

    /**
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getLessors(array $options = [], $forceReValidation = false)
    {
        return $this->getResources('lessors', $options, $forceReValidation);
    }

    /**
     * @param $id
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return mixed
     */
    public function getLessorById($id, array $options = [], $forceReValidation = false)
    {
        return $this->getResourceById('lessors', $id, $options, $forceReValidation);
    }

    /**
     * @param int   $userId
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return array
     */
    public function getMessages($userId,  array $options = [], $forceReValidation = false)
    {
        $url = sprintf('/api/users/%s/messages', $userId);

        return $this->get($url, $options, $forceReValidation);
    }

    /**
     * @param int   $userId
     * @param int   $messageId
     * @param array $options
     * @param bool  $forceReValidation
     *
     * @return array
     */
    public function getMessageId($messageId, array $options = [], $forceReValidation = false)
    {
        $url = sprintf('/api/messages/%s', $messageId);

        return $this->get($url, $options, $forceReValidation);
    }

    /**
     * @param array $messageData
     * @param       $userId
     * @param       $version
     *
     * @return array
     */
    public function postMessage(array $messageData, $userId, $version)
    {
        $data = array(
            'message' => $messageData,
        );
        $url = sprintf('/api/users/%s/messages', $userId);
        $response = $this->post($url, $version, $data);

        $body = $response->json();

        if (!is_array($body)) {
            throw new \LogicException(
                'The News was successfully created but an unexpected response was return from the MR API'
            );
        }

        return $body;
    }

    /**
     * Set Message as read.
     *
     * @param $messageId
     * @param $read
     * @param $version
     *
     * @return mixed
     */
    public function putMessageRead($messageId, $read, $version)
    {
        $data = array(
            'message' => array(
                'read' => $read,
            ),
        );
        $url = sprintf('/api/messages/%s', $messageId);
        $response = $this->put($url, $version, $data);

        $body = $response->json();

        if (!is_array($body)) {
            throw new \LogicException(
                'The News was successfully created but an unexpected response was return from the MR API'
            );
        }

        return $body;
    }

    /**
     * Delete a Message.
     *
     * @param $messageId
     * @param $version
     */
    public function deleteMessage($messageId, $version)
    {
        $url = sprintf('/api/messages/%s', $messageId);
        $this->delete($url, $version);
    }

    /**
     * Autocomplete postal code and city name.
     *
     * @param string $query
     * @param int    $limit
     *
     * @return array
     */
    public function getAutocompleteTown($query, $limit = 10)
    {
        $url = '/api/towns/autocomplete';

        return $this->get($url, array(
            'q' => $query,
            'limit' => $limit,
        ));
    }

    /**
     * Autocomplete street name.
     *
     * @param string $postalCode
     * @param string $query
     * @param int    $limit
     *
     * @return array
     */
    public function getAutocompleteStreet($postalCode, $query, $limit = 10)
    {
        $url = sprintf('/api/towns/%s/street/autocomplete', $postalCode);

        return $this->get($url, array(
            'q' => $query,
            'limit' => $limit,
        ));
    }

    /**
     * Get the skills sorted by categories.
     *
     * @param array $options
     *
     * @return array
     */
    public function getSkills($options, $forceReValidation = false)
    {
        return $this->getResources('skills', $options, $forceReValidation);
    }

    /**
     * Get the favorites of a user.
     *
     * @param string $entity            Name of favorite resource to get ('users', 'events', 'community', ...)
     * @param array  $options
     * @param bool   $forceReValidation
     *
     * @return array
     */
    public function getFavorites($entity, array $options, $forceReValidation = false)
    {
        $url = sprintf('/api/favorites/%s', $entity);

        return $this->get($url, $options, $forceReValidation);
    }

    /**
     * @param $resource
     * @param array $options
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
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
     * @param bool  $forceReValidation
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
            throw new \LogicException('An error occurred when trying to GET data from MR API.');
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
     * @param array  $data
     * @param string $bodyEncoding
     *
     * @return \GuzzleHttp\Message\FutureResponse|\GuzzleHttp\Message\ResponseInterface|\GuzzleHttp\Ring\Future\FutureInterface|null
     */
    private function post($url, $version, array $data = [], $bodyEncoding = 'json')
    {
        $requestOptions = $this->getPostRequestOptions($version, $data, $bodyEncoding);

        $response = $this->client->post($url, $requestOptions);

        if (201 !== $response->getStatusCode()) {
            throw new \LogicException('An error occurred when trying to POST data to MR API');
        }

        return $response;
    }

    /**
     * @param $url
     * @param $version
     * @param array  $data
     * @param string $bodyEncoding
     *
     * @return \GuzzleHttp\Message\FutureResponse|\GuzzleHttp\Message\ResponseInterface|\GuzzleHttp\Ring\Future\FutureInterface|null
     */
    private function patch($url, $version, array $data = [], $bodyEncoding = 'json')
    {
        $requestOptions = $this->getPostRequestOptions($version, $data, $bodyEncoding);

        $response = $this->client->patch($url, $requestOptions);

        if (200 !== $response->getStatusCode()) {
            throw new \LogicException('An error occurred when trying to POST data to MR API');
        }

        return $response;
    }

    /**
     * @param $url
     * @param $version
     * @param array  $data
     * @param string $bodyEncoding
     *
     * @return \GuzzleHttp\Message\FutureResponse|\GuzzleHttp\Message\ResponseInterface|\GuzzleHttp\Ring\Future\FutureInterface|null
     */
    private function put($url, $version, array $data = [], $bodyEncoding = 'json')
    {
        $requestOptions = $this->getPostRequestOptions($version, $data, $bodyEncoding);

        $response = $this->client->put($url, $requestOptions);

        if (200 !== $response->getStatusCode()) {
            throw new \LogicException('An error occurred when trying to POST data to MR API');
        }

        return $response;
    }

    /**
     * @param $url
     * @param $version
     * @param array  $data
     * @param string $bodyEncoding
     *
     * @return \GuzzleHttp\Message\FutureResponse|\GuzzleHttp\Message\ResponseInterface|\GuzzleHttp\Ring\Future\FutureInterface|null
     */
    private function delete($url, $version, array $data = [], $bodyEncoding = 'json')
    {
        $requestOptions = $this->getPostRequestOptions($version, $data, $bodyEncoding);

        $response = $this->client->delete($url, $requestOptions);

        if (204 !== $response->getStatusCode()) {
            throw new \LogicException('An error occurred when trying to POST data to MR API');
        }

        return $response;
    }

    /**
     * @param $version
     * @param array  $data
     * @param string $bodyEncoding
     *
     * @return array
     */
    private function getPostRequestOptions($version, array $data = [], $bodyEncoding = 'json')
    {
        $token = $this->tokenStorage->getAccessToken();

        $requestOptions = [];

        foreach ($data as $key => $value) {
            $requestOptions['body'][$key] = $value;
        }

        // Encode the body to be fully compatible with REST
        if ('json' == $bodyEncoding && array_key_exists('body', $requestOptions)) {
            $requestOptions['headers']['Content-type'] = 'application/json';
            $requestOptions['body'] = json_encode($requestOptions['body']);
        }

        $requestOptions['headers']['Accept'] = sprintf('application/ma-residence.v%d', $version);

        $requestOptions['query']['access_token'] = $token['access_token'];

        return $requestOptions;
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
     *
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
