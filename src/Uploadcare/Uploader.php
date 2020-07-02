<?php

namespace Uploadcare;

use Uploadcare\Exceptions\RequestErrorException;
use Uploadcare\Signature\SignatureInterface;

/**
 * @method fromUrl($url, $options = array())
 */
class Uploader extends AbstractUploader
{
    /**
     * Constructor
     * @param Api $api
     * @param SignatureInterface|null $signature
     */
    public function __construct(Api $api, SignatureInterface $signature = null)
    {
        $this->api = $api;
        $this->secureSignature = $signature;
    }

    public function getApi()
    {
        return $this->api;
    }

    /**
     * Return secure signature for signed uploads.
     *
     * @return SignatureInterface|null
     */
    public function getSecureSignature()
    {
        return $this->secureSignature;
    }

    /**
     * Check file status.
     * Return array of json data
     *
     * @param string $token
     * @throws \Exception
     * @throws RequestErrorException
     * @return object
     */
    public function status($token)
    {
        $data = array(
            'token' => $token,
        );

        $this->requestData = $data;

        $ch = $this->initRequest('from_url/status', $data);
        $this->setHeaders($ch);
        $data = $this->runRequest($ch);
        return $data;
    }

    /**
     * @param $method
     * @param $arguments
     * @return File|string|null
     */
    public function __call($method, $arguments)
    {
        if ($method === 'fromUrl') {
            if (count($arguments) === 1) {
                return call_user_func_array(array($this,'fromUrlNew'), $arguments);
            }
            if (count($arguments) === 2) {
                if (is_array($arguments[1])) {
                    return call_user_func_array(array($this,'fromUrlNew'), $arguments);
                }

                return call_user_func_array(array($this,'fromUrlOld'), $arguments);
            }

            if (count($arguments) >= 3) {
                return call_user_func_array(array($this,'fromUrlOld'), $arguments);
            }
        }

        return null;
    }

    /**
     * Upload file from a URL and get File instance
     *
     * @deprecated 2.0.0 please use fromUrl($url, $options) instead
     * @param string $url A URL of file to be uploaded.
     * @param boolean $check_status Wait till upload is complete
     * @param int $timeout Wait $timeout seconds between status checks
     * @param int $max_attempts Check status no more than $max_attempts times
     * @throws \Exception
     * @throws RequestErrorException
     * @return File|string
     */
    private function fromUrlOld($url, $check_status = true, $timeout = 1, $max_attempts = 5)
    {
        Helper::deprecate('2.0.0', '3.0.0', 'This version of method `fromUrl($url, $check_status, $timeout, $max_attempts)` is deprecated please use `fromUrl($url, $options)` instead');
        return $this->fromUrlNew($url, array(
            'check_status' => $check_status,
            'timeout' => $timeout,
            'max_attempts' => $max_attempts,
        ));
    }

    /**
     * Upload file from a URL and get File instance
     *
     * @param string $url A URL of file to be uploaded.
     * @param array $options Optional dictionary with additional params. Available keys are following:
     *   'store' - can be true, false or 'auto'. This flag indicates should file be stored automatically after upload.
     *   'filename' - should be a string, Sets explicitly file name of uploaded file.
     *   'check_status' - Wait till upload is complete
     *   'timeout' - Wait number of seconds between status checks
     *   'max_attempts' - Check status no more than passed number of times
     * @throws \Exception
     * @throws RequestErrorException
     * @return File|string
     */
    private function fromUrlNew($url, $options = array())
    {
        $default_options = array(
            'store' => 'auto',
            'filename' => null,
            'check_status' => true,
            'timeout' => 1,
            'max_attempts' => 5,
        );
        $params = array_merge($default_options, $options);
        $check_status = $params['check_status'];
        $timeout = $params['timeout'];
        $max_attempts = $params['max_attempts'];

        $requestData = array(
            '_' => time(),
            'source_url' => $url,
            'pub_key' => $this->api->getPublicKey(),
            'store' => $params['store'],
        );
        if ($params['filename']) {
            $requestData['filename'] = $params['filename'];
        }

        $requestData = $this->getSignedUploadsData($requestData);
        $this->requestData = $requestData;

        $ch = $this->initRequest('from_url', $requestData);
        $this->setHeaders($ch);

        $data = $this->runRequest($ch);
        $token = $data->token;

        if ($check_status) {
            $success = false;
            $attempts = 0;
            while (!$success) {
                $data = $this->status($token);
                if ($data->status === 'success') {
                    $success = true;
                }
                if ($data->status === 'error') {
                    throw new \RuntimeException('Upload is not successful: ' . $data->error);
                }
                if ($attempts === $max_attempts && $data->status !== 'success') {
                    throw new \RuntimeException('Max attempts reached, upload is not successful');
                }
                sleep($timeout);
                $attempts++;
            }
        } else {
            return $token;
        }
        $uuid = $data->uuid;

        return new File($uuid, $this->api);
    }

    /**
     * Upload file from local path.
     *
     * @param string $path
     * @param string|bool $mime_type
     * @param string $filename
     * @param string|bool $store
     * @throws \Exception
     * @throws RequestErrorException
     * @return File
     */
    public function fromPath($path, $mime_type = null, $filename = null, $store = 'auto')
    {
        if (!\is_file($path)) {
            throw new \RuntimeException(\sprintf('Unable to read file from \'%s\'', $path));
        }

        $data = $this->getSignedUploadsData(array(
          self::UPLOADCARE_PUB_KEY_KEY => $this->api->getPublicKey(),
          self::UPLOADCARE_STORE_KEY => $store,
          'file' => curlFile($path, $mime_type, $filename),
        ));
        $this->requestData = $data;

        $ch = $this->initRequest('base');
        $this->setRequestType($ch);
        $this->setData($ch, $data);
        $this->setHeaders($ch);

        $data = $this->runRequest($ch);
        $uuid = $data->file;
        return new File($uuid, $this->api);
    }

    /**
     * Upload file from string using mime-type.
     *
     * @param string $content
     * @param string $mime_type
     * @param string $filename
     * @param string|bool $store
     * @throws \Exception
     * @throws RequestErrorException
     * @return File
     */
    public function fromContent($content, $mime_type, $filename = null, $store = 'auto')
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'ucr');
        $temp = fopen($tmpfile, 'wb');
        fwrite($temp, $content);
        fclose($temp);

        return $this->fromPath($tmpfile, $mime_type, $filename, $store);
    }

    /**
     * Create group from array of File objects
     *
     * @param array $files
     * @throws \Exception
     * @throws RequestErrorException
     * @return Group
     */
    public function createGroup($files)
    {
        $data = array(
            'pub_key' => $this->api->getPublicKey(),
        );
        /**
         * @var File $file
         */
        foreach ($files as $i => $file) {
            $data["files[$i]"] = $file->getUrl();
        }

        $data = $this->getSignedUploadsData($data);
        $this->requestData = $data;

        $ch = $this->initRequest('group');
        $this->setRequestType($ch);
        $this->setData($ch, $data);
        $this->setHeaders($ch);

        $resp = $this->runRequest($ch);

        return $this->api->getGroup($resp->id);
    }
}
