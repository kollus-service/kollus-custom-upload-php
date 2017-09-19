<?php

namespace Kollus\Component\Client;

use GuzzleHttp\Client as HttpClient;
use Kollus\Component\Container;

class ApiClient extends AbstractClient
{
    /**
     * @var HttpClient $client
     */
    protected $client;

    /**
     * @param string $method
     * @param string $uri
     * @param array $optParams
     * @param array $postParams
     * @param array $getParams
     * @param int $retry
     * @return mixed
     * @throws ClientException
     */
    private function getResponseJSON(
        $method,
        $uri,
        array $getParams = [],
        array $postParams = [],
        array $optParams = [],
        $retry = 3
    ) {
        if (count($getParams) > 0) {
            $optParams['query'] = $getParams;
        }

        if (count($postParams) > 0) {
            $optParams['form_params'] = $postParams;
        }

        if (!isset($optParams['timeout'])) {
            $optParams['timeout'] = $this->optParams['timeout'];
        }

        do {
            $response = $this->client->request($method, $uri, $optParams);

            $statusCode = $response->getStatusCode();
            $retry --;
        } while ($statusCode != 200 && $retry > 0);

        $jsonResponse = json_decode($response->getBody()->getContents());
        if ($jsonResponse === false || (isset($jsonResponse->error) && (int)$jsonResponse->error === 1)) {
            $message = isset($jsonResponse->message) ? $jsonResponse->message : $response->getBody()->getContents();
            throw new ClientException($message, $statusCode);
        }

        return $jsonResponse;
    }

    /**
     * @param mixed|null $client
     * @return self
     * @throws ClientException
     */
    public function connect($client = null)
    {
        if (is_subclass_of($this->serviceAccount, Container\ServiceAccount::class)) {
            throw new ClientException('Service account is required.');
        }

        $serviceAccountKey = $this->serviceAccount->getKey();
        if (empty($serviceAccountKey)) {
            throw new ClientException('Service account key is empty.');
        }

        $apiAccessToken = $this->serviceAccount->getApiAccessToken();
        if (empty($apiAccessToken)) {
            throw new ClientException('Access token is empty.');
        }

        if (is_null($client)) {
            $this->client = new HttpClient([
                'base_uri' => $this->schema . '://api.' . $this->domain . '/' . $this->version . '/',
                'defaults' => ['allow_redirects' => false],
                'verify' => false,
            ]);
        } else {
            $this->client = $client;
        }

        return $this;
    }

    /**
     * @return self
     * @throws ClientException
     */
    public function disconnect()
    {
        unset($this->client);
        return $this;
    }

    /**
     * @param array $getParams
     * @param bool|false $force
     * @return Container\ContainerArray
     * @throws ClientException
     */
    public function getCategories(array $getParams = [], $force = false)
    {
        $getParams['access_token'] = $this->serviceAccount->getApiAccessToken();
        $getParams['force'] = (int)$force;
        $response = $this->getResponseJSON('GET', 'media/category', $getParams);

        if (isset($response->error) && $response->error) {
            throw new ClientException($response->message);
        } elseif (isset($response->result->count) && isset($response->result->items)) {
            if (is_array($response->result->items)) {
                $items = $response->result->items;
            } elseif (isset($response->result->items->item) && is_array($response->result->items->item)) {
                $items = $response->result->items->item;
            } else {
                throw new ClientException('Response is invalid.');
            }
        } else {
            throw new ClientException('Response is invalid.');
        }

        $categories = new Container\ContainerArray();
        foreach ($items as $item) {
            $categories->appendElement(new Container\Category($item));
        }

        return $categories;
    }

    /**
     * @param string|null $categoryKey
     * @param bool|false $useEncryption
     * @param bool|false $isAudioUpload
     * @param string $title
     * @param int $expireTime
     * @return object
     * @throws ClientException
     */
    public function getUploadURLResponse(
        $categoryKey = null,
        $useEncryption = false,
        $isAudioUpload = false,
        $title = '',
        $expireTime = 600
    ) {
        $postParams = [
            'access_token' => $this->serviceAccount->getApiAccessToken(),
            'category_key' => $categoryKey,
            'expire_time' => $expireTime,
            'is_encryption_upload' => (bool)$useEncryption,
            'is_audio_upload' => (bool)$isAudioUpload,
            'title' => (empty($title) ? null : $title)
        ];

        $response = $this->getResponseJSON('POST', 'media_auth/upload/create_url.json', [], $postParams);
        if (!isset($response->result)) {
            throw new ClientException('Response is invalid.');
        }

        return (object)$response->result;
    }

    /**
     * @param int $page
     * @param array $getParams
     * @param bool|false $force
     * @return object
     * @throws ClientException
     */
    public function findUploadFilesByPage($page = 1, array $getParams = [], $force = false)
    {
        $getParams['access_token'] = $this->serviceAccount->getApiAccessToken();
        $getParams['page'] = $page;
        $getParams['force'] = (int)$force;
        $response = $this->getResponseJSON('GET', 'media/upload_file', $getParams);

        if (isset($response->result->count) && isset($response->result->items)) {
            if (is_array($response->result->items)) {
                $items = $response->result->items;
            } elseif (isset($response->result->items->item) && is_array($response->result->items->item)) {
                $items = $response->result->items->item;
            } else {
                throw new ClientException('Response is invalid.');
            }
        } else {
            throw new ClientException('Response is invalid.');
        }

        $uploadFiles = new Container\ContainerArray();
        foreach ($items as $item) {
            $uploadFiles->appendElement(new Container\UploadFile($item));
        }

        return (object)[
            'per_page' => $response->result->per_page,
            'count' => $response->result->count,
            'items' => $uploadFiles,
        ];
    }

    /**
     * @param array $getParams
     * @param bool|false $force
     * @return Container\ContainerArray
     */
    public function getUploadFiles(array $getParams = [], $force = false)
    {
        if (!isset($getParams['per_page'])) {
            $getParams['per_page'] = 100;
        }

        $page = 1;
        $result = $this->findUploadFilesByPage($page, $getParams, $force);
        $pages = (int)ceil($result->count / $result->per_page);

        /**
         * @var Container\ContainerArray $uploadFiles
         */
        $uploadFiles = $result->items;

        for ($page = 2; $page <= $pages; $page++) {
            $result = $this->findUploadFilesByPage($page, $getParams, $force);
            foreach ($result->items as $item) {
                $uploadFiles->appendElement($item);
            }
        }

        return $uploadFiles;
    }
}
