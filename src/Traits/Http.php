<?php

namespace Telegram\Bot\Traits;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Telegram\Bot\Exceptions\CouldNotUploadInputFile;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\HttpClients\HttpClientInterface;
use Telegram\Bot\Objects\BaseObject;
use Telegram\Bot\Objects\File;
use Telegram\Bot\TelegramClient;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;

/**
 * Http.
 */
trait Http
{
    use Validator;

    /** @var string Telegram Bot API Access Token. */
    protected $accessToken = null;

    /** @var TelegramClient The Telegram client service. */
    protected $client = null;

    /** @var HttpClientInterface|null Http Client Handler */
    protected $httpClientHandler = null;

    /** @var bool Indicates if the request to Telegram will be asynchronous (non-blocking). */
    protected $isAsyncRequest = false;

    /** @var int Timeout of the request in seconds. */
    protected $timeOut = 60;

    /** @var int Connection timeout of the request in seconds. */
    protected $connectTimeOut = 10;

    /** @var TelegramResponse|null Stores the last request made to Telegram Bot API. */
    protected $lastResponse;

    /**
     * Set Http Client Handler.
     *
     * @param  HttpClientInterface  $httpClientHandler
     * @return $this
     */
    public function setHttpClientHandler(HttpClientInterface $httpClientHandler)
    {
        $this->httpClientHandler = $httpClientHandler;

        return $this;
    }

    /**
     * Returns the TelegramClient service.
     *
     * @return TelegramClient
     */
    protected function getClient(): TelegramClient
    {
        if ($this->client === null) {
            $this->client = new TelegramClient($this->httpClientHandler);
        }

        return $this->client;
    }

    /**
     * Returns the last response returned from API request.
     *
     * @return TelegramResponse|null
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * Download a file from Telegram server by file ID.
     *
     * @param  File|BaseObject|string  $file     Telegram File Instance / File Response Object or File ID.
     * @param  string  $filename Absolute path to dir or filename to save as.
     * @return string
     *
     * @throws TelegramSDKException
     */
    public function downloadFile($file, string $filename): string
    {
        $originalFilename = null;
        if (! $file instanceof File) {
            if ($file instanceof BaseObject) {
                $originalFilename = $file->get('file_name');

                // Try to get file_id from the object or default to the original param.
                $file = $file->get('file_id');
            }

            if (! is_string($file)) {
                throw new InvalidArgumentException(
                    'Invalid $file param provided. Please provide one of file_id, File or Response object containing file_id'
                );
            }

            $file = $this->getFile(['file_id' => $file]);
        }

        // No filename provided.
        if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
            // Attempt to use the original file name if there is one or fallback to the file_path filename.
            $filename .= DIRECTORY_SEPARATOR.($originalFilename ?: basename($file->file_path));
        }

        return $this->getClient()->download($file->file_path, $filename);
    }

    /**
     * Returns Telegram Bot API Access Token.
     *
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Sets the bot access token to use with API requests.
     *
     * @param  string  $accessToken The bot access token to save.
     * @return $this
     */
    public function setAccessToken(string $accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Check if this is an asynchronous request (non-blocking).
     *
     * @return bool
     */
    public function isAsyncRequest(): bool
    {
        return $this->isAsyncRequest;
    }

    /**
     * Make this request asynchronous (non-blocking).
     *
     * @param  bool  $isAsyncRequest
     * @return $this
     */
    public function setAsyncRequest(bool $isAsyncRequest)
    {
        $this->isAsyncRequest = $isAsyncRequest;

        return $this;
    }

    /**
     * @return int
     */
    public function getTimeOut(): int
    {
        return $this->timeOut;
    }

    /**
     * @param  int  $timeOut
     * @return $this
     */
    public function setTimeOut(int $timeOut)
    {
        $this->timeOut = $timeOut;

        return $this;
    }

    /**
     * @return int
     */
    public function getConnectTimeOut(): int
    {
        return $this->connectTimeOut;
    }

    /**
     * @param  int  $connectTimeOut
     * @return $this
     */
    public function setConnectTimeOut(int $connectTimeOut)
    {
        $this->connectTimeOut = $connectTimeOut;

        return $this;
    }

    /**
     * Sends a GET request to Telegram Bot API and returns the result.
     *
     * @param  string  $endpoint
     * @param  array  $params
     * @return TelegramResponse
     *
     * @throws TelegramSDKException
     */
    protected function get(string $endpoint, array $params = []): TelegramResponse
    {
        $params = $this->replyMarkupToString($params);

        return $this->sendRequest('GET', $endpoint, $params);
    }

    /**
     * Sends a POST request to Telegram Bot API and returns the result.
     *
     * @param  string  $endpoint
     * @param  array  $params
     * @param  bool  $fileUpload Set true if a file is being uploaded.
     * @return TelegramResponse
     *
     * @throws TelegramSDKException
     */
    protected function post(string $endpoint, array $params = [], $fileUpload = false): TelegramResponse
    {
        $params = $this->normalizeParams($params, $fileUpload);

        return $this->sendRequest('POST', $endpoint, $params);
    }

    /**
     * Converts a reply_markup field in the $params to a string.
     *
     * @param  array  $params
     * @return array
     */
    protected function replyMarkupToString(array $params): array
    {
        if (isset($params['reply_markup'])) {
            $params['reply_markup'] = (string) $params['reply_markup'];
        }

        return $params;
    }

    /**
     * Sends a multipart/form-data request to Telegram Bot API and returns the result.
     * Used primarily for file uploads.
     *
     * @param  string  $endpoint
     * @param  array  $params
     * @param  string  $inputFileField
     * @return TelegramResponse
     *
     * @throws CouldNotUploadInputFile|TelegramSDKException
     */
    protected function uploadFile(string $endpoint, array $params, string $inputFileField): TelegramResponse
    {
        //Check if the field in the $params array (that is being used to send the relative file), is a file id.
        if (! isset($params[$inputFileField])) {
            throw CouldNotUploadInputFile::missingParam($inputFileField);
        }

        if ($this->hasFileId($inputFileField, $params)) {
            return $this->post($endpoint, $params);
        }

        //Sending an actual file requires it to be sent using multipart/form-data
        return $this->post($endpoint, $this->prepareMultipartParams($params, $inputFileField), true);
    }

    /**
     * Prepare Multipart Params for File Upload.
     *
     * @param  array  $params
     * @param  string  $inputFileField
     * @return array
     *
     * @throws CouldNotUploadInputFile
     */
    protected function prepareMultipartParams(array $params, string $inputFileField): array
    {
        $this->validateInputFileField($params, $inputFileField);

        $inputFiles = Arr::wrap($params[$inputFileField]);
        $this->is_list($inputFiles) || $inputFiles = [$inputFiles];
        $multipart = collect($inputFiles)
            ->map(function ($inputFile) use ($inputFileField): ?array {
                // get input file if key media
                if (is_array($inputFile) && $inputFileField === $this->mediaKey()) {
                    $inputFile = $inputFile[$inputFileField];
                }

                if (! $inputFile instanceof InputFile) {
                    return null;
                }

                return $inputFile->toMultipart();
            })
            ->filter();

        //Iterate through all param options and convert to multipart/form-data.
        return collect($params)
            ->reject(function ($value) {
                return null === $value;
            })
            ->map(function ($contents, $name) {
                return $this->generateMultipartData($contents, $name);
            })
            ->concat($multipart)
            ->values()
            ->all();
    }

    /**
     * Generates the multipart data required when sending files to telegram.
     *
     * @param  mixed  $contents
     * @param  string  $name
     * @return array
     */
    protected function generateMultipartData($contents, string $name): array
    {
        if ($name === $this->mediaKey()) {
            $media = Arr::wrap($contents);
            $wasList = $this->is_list($media);
            $wasList || $media = [$media];
            $media = collect($media)->map(function (array $mediaItem): array {
                $inputFile = $mediaItem[$this->mediaKey()];
                if ($inputFile instanceof InputFile) {
                    return array_merge($mediaItem, [
                        'media' => $inputFile->getAttachString(),
                    ]);
                }

                return $mediaItem;
            })
                ->all();

            // if media was single media, unwrap that
            $wasList || $media = reset($media);

            $contents = json_encode($media);

            return compact('name', 'contents');
        }

        if ($contents instanceof InputFile) {
            $contents = $contents->getAttachString();
        }

        return compact('name', 'contents');
    }

    /**
     * Sends a request to Telegram Bot API and returns the result.
     *
     * @param  string  $method
     * @param  string  $endpoint
     * @param  array  $params
     * @return TelegramResponse
     *
     * @throws TelegramSDKException
     */
    protected function sendRequest($method, $endpoint, array $params = []): TelegramResponse
    {
        $telegramRequest = $this->resolveTelegramRequest($method, $endpoint, $params);

        return $this->lastResponse = $this->getClient()->sendRequest($telegramRequest);
    }

    /**
     * Instantiates a new TelegramRequest entity.
     *
     * @param  string  $method
     * @param  string  $endpoint
     * @param  array  $params
     * @return TelegramRequest
     */
    protected function resolveTelegramRequest($method, $endpoint, array $params = []): TelegramRequest
    {
        return (new TelegramRequest(
            $this->getAccessToken(),
            $method,
            $endpoint,
            $params,
            $this->isAsyncRequest()
        ))
            ->setTimeOut($this->getTimeOut())
            ->setConnectTimeOut($this->getConnectTimeOut());
    }

    /**
     * @param  array  $params
     * @param  string  $inputFileField
     *
     * @throws CouldNotUploadInputFile
     */
    protected function validateInputFileField(array $params, string $inputFileField): void
    {
        if (! isset($params[$inputFileField])) {
            throw CouldNotUploadInputFile::missingParam($inputFileField);
        }

        $inputFiles = Arr::wrap($params[$inputFileField]);
        $this->is_list($inputFiles) || $inputFiles = [$inputFiles];

        collect($inputFiles)->each(function ($inputFile, $key) use ($inputFileField) {
            $failParameter = $inputFileField;
            if (is_array($inputFile) && $inputFileField === $this->mediaKey()) {
                $inputFile = $inputFile[$inputFileField];
                $failParameter = sprintf('%s #%s', $inputFileField, $key);
            }

            if (is_string($inputFile) && $this->isFileId($inputFile)) {
                return true;
            }
            // All file-paths, urls, or file resources should be provided by using the InputFile object
            if (! $inputFile instanceof InputFile) {
                throw CouldNotUploadInputFile::inputFileParameterShouldBeInputFileEntity($failParameter);
            }

            return true;
        });
    }

    /**
     * @param  array  $params
     * @param $fileUpload
     * @return array
     */
    private function normalizeParams(array $params, $fileUpload)
    {
        if ($fileUpload) {
            return ['multipart' => $params];
        }

        return ['form_params' => $this->replyMarkupToString($params)];
    }

    private function mediaKey(): string
    {
        return 'media';
    }
}
