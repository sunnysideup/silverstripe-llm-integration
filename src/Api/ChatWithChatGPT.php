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
    private static string $model_name = 'gpt-4o';

    private static float $temperature = 0.3;

    private static string $context_instructions = 'Use the attached file as your reference context for answering any question.';
    private static string $standard_instructions = <<<TXT
When answering:
- You are an expert SilverStripe developer. Provide concise and accurate answers based on the provided context and file.
- Output PHP code only (no prose, no backticks, no ```php fences).
- Use PHP 8.3 syntax and SilverStripe ORM where possible.
- The code must run without errors in a standard Silverstripe 5 project.
- As part of the php code, you can ask yourself a question. You do it with the following method: \Sunnysideup\LLMIntegration\Api\ChatWithChatGPT::singleton()->AskQuestionOutsideOfThread(fooBar): string; This will be helpful if you want to review some content as part your PHP code so that you can give a better answer.
- Always check canView(), canCreate(), canEdit(), and canDelete() as appropriate.
- The final line MUST be a return statement with a human-readable answer.
- Return the human-readable answer as HTML.

TXT;

    private static string $reminder_text = <<<TXT
Reminder: Return only PHP 8.3 Silverstripe ORM code. Last line must be return with human explanation.
TXT;

    public function AskQuestionOutsideOfThread(
        string $question,
        ?array $additionalData = null
    ): array {
        $data = array_merge(
            [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $question,
                    ],
                ],
            ],
            $additionalData ?? []
        );

        return $this->talkToChatGPT('chat/completions', 'POST', $data);
    }

    public function talkToChatGPT(
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

        // Always start with Authorization header
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'OpenAI-Beta: assistants=v2'
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        // Handle body
        if ($data !== null) {
            if ($sendingJson) {
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR);
            } else {
                // ❗ Remove any Content-Type headers completely
                $headers = array_filter($headers, fn($h) => stripos($h, 'Content-Type:') === false);
                $options[CURLOPT_POSTFIELDS] = $data;
            }

            if (strtoupper($method) === 'POST') {
                $options[CURLOPT_POST] = true;
            } else {
                $options[CURLOPT_CUSTOMREQUEST] = $method;
            }
        }

        $options[CURLOPT_HTTPHEADER] = array_values($headers);

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("OpenAI API cURL error: $error");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new RuntimeException(
                "Invalid JSON response from OpenAI: $response\n\n" .
                    "Request info:\n" . json_encode($info, JSON_PRETTY_PRINT)
            );
        }

        return $decoded;
    }

    public function SendFileToChatGPT($content)
    {
        $apiKey = Environment::getEnv('SS_LLM_CLIENT_API_KEY');
        $tmp = tempnam(sys_get_temp_dir(), 'ctx_');
        file_put_contents($tmp, $content);

        $ch = curl_init('https://api.openai.com/v1/files');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => [
                'purpose' => 'assistants',
                'file' => new CURLFile($tmp, 'application/json', 'context.json'),
            ],
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $decoded = json_decode($response, true);
        return $decoded;
    }
}
