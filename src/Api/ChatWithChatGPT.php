<?php

namespace Sunnysideup\LLMIntegration\Api;

use CURLFile;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use Sunnnysideup\LLMIntegration\Model\LLMThreadProvider;
use Sunnnysideup\LLMIntegration\Model\ModelContextProvider;

class ChatWithChatGPT
{

    use Configurable;
    use Injectable;
    use Extensible;

    public static function talk_to_chatgpt(
        string $endpoint,
        string $method = 'GET',
        ?array $data = null,
        bool $sendingJson = true
    ): array {
        $apiKey = Environment::getEnv('SS_LLM_CLIENT_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('Missing SS_LLM_CLIENT_API_KEY environment variable.');
        }

        $url = str_starts_with($endpoint, 'https://')
            ? $endpoint
            : 'https://api.openai.com/v1/' . ltrim($endpoint, '/');

        $ch = curl_init($url);

        $headers = ['Authorization: Bearer ' . $apiKey];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        // JSON or multipart body
        if ($data !== null) {
            if ($sendingJson) {
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR);
            } else {
                $options[CURLOPT_POSTFIELDS] = $data; // already prepared (e.g., with CURLFile)
            }
            $options[CURLOPT_HTTPHEADER] = $headers;
            if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
                $options[CURLOPT_POST] = true;
            }
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("OpenAI API cURL error: $error");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new RuntimeException("Invalid JSON response from OpenAI: $response");
        }

        return $decoded;
    }
}
