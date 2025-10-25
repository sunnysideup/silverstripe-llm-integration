<?php

namespace Sunnysideup\LlmIntegration\Control;

use CURLFile;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\RequiredFields;

use ReflectionClass;
use ReflectionMethod;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;

class AskQuestion extends Controller
{
    private static array $allowed_actions = [
        'index',
        'QuestionForm',
        'doAskQuestion',
    ];

    private static $url_segment = 'ask-question';

    protected function init(): void
    {
        parent::init();
        // add CSS/JS if needed
    }

    public function index(HTTPRequest $request)
    {
        return [
            'Title' => 'Ask a Question',
            'QuestionForm' => $this->QuestionForm(),
        ];
    }

    public function QuestionForm(): Form
    {
        $fields = FieldList::create(
            TextareaField::create('Question', 'Your Question')
                ->setAttribute('placeholder', 'Ask me anything...')
        );

        $actions = FieldList::create(
            FormAction::create('doAskQuestion', 'Ask')
                ->addExtraClass('btn btn-primary')
        );

        $validator = RequiredFields::create('Question');

        return Form::create($this, 'QuestionForm', $fields, $actions, $validator)
            ->setFormMethod('POST')
            ->addExtraClass('ask-question-form');
    }

    public function doAskQuestion(array $data, Form $form, HTTPRequest $request)
    {
        $question = trim($data['Question'] ?? '');

        if ($question === '') {
            $form->sessionMessage('Please enter a question.', 'bad');
            return $this->redirectBack();
        }

        // You can replace this with your ChatGPT processing logic
        $answer = $this->runQuestion($question);

        $form->sessionMessage('Answer: ' . $answer, 'good');
        return $this->redirectBack();
    }

    private const FIELDS = [
        'db',
        'belongs_to',
        'has_one',
        'has_many',
        'many_many',
        'belongs_many_many',
    ];

    public function runQuestion(string $question)
    {
        $classes = ClassInfo::subclassesFor(DataObject::class, false);
        $schema = DataObject::getSchema();
        $array = [];
        foreach ($classes as $className) {
            $config = Injector::inst()->get($className)->config();

            $reflection = new ReflectionClass($className);

            $parentClass = $reflection->getParentClass();
            $ownMethods = array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                fn(ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $className
            );
            $publicMethods = array_map(fn(ReflectionMethod $m) => $m->getName(), $ownMethods);
            $fields = [];
            foreach (self::FIELDS as $field) {
                $fieldData = $config->get($field);
                if (!empty($fieldData) && is_array($fieldData)) {
                    $fields[$field] = $fieldData;
                }
            }

            $array[] = [
                'ClassName' => $className,
                'TableName' => $schema->tableName($className),
                'DBFields' => $fields,
                'ParentClass' => $parentClass ? $parentClass->getName() : null,
                'UninheritedPublicMethods' => $publicMethods,
            ];
        }
        $context = json_encode($array, JSON_PRETTY_PRINT);
        echo strlen($context);

        $apiKey = Environment::getEnv('OPENAI_API_KEY');

        // Step 1: send large data (context)
        $messages = [
            ['role' => 'system', 'content' => 'You are a data-analysis assistant.'],
            ['role' => 'user', 'content' => 'Here is the dataset: ' . $context],
        ];

        // Step 2: ask a question about it
        $messages[] = ['role' => 'user', 'content' => '
            Use the above data to write some Silverstripe PHP that I can run to get the answer.
            Please return the PHP only and on the last line, it should return the value. We are using php 8.3.
            It can be multiple lines of code. But the last line must be a return statement.
            Dont include anything like ```php. I want to be able to run it directly.
            The answer must use SilverStripe ORM methods to get the data.
            Write the PHP code very carefully so it runs without errors.
            However, the php must return an answer for a human with a bit of explanation.
            Can you give me the PHP code to get the answer to the following question:
        '];
        $messages[] = ['role' => 'user', 'content' => $question];

        $response = $this->chatWithGPT($apiKey, $messages);
        $php = $response['choices'][0]['message']['content'] ?? 'No response';
        // Step 3: print the reply
        try {
            $fx = 'return (function() {' . $php . '})();';

            $result = eval($fx);
            if ($result === null) {
                return 'No result returned. We tried to run the following PHP code but it did not return anything. Please ensure the last line of the PHP code is a return statement: ' . $php;
            }
            return $result;
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }


    protected function chatWithGPT(string $apiKey, array $messages): array
    {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');

        $payload = [
            'model' => 'gpt-4o-mini', // or 'gpt-5' if available
            'messages' => $messages,
            'temperature' => 0.7,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function uploadContextFile($context)
    {

        $apiKey = getenv('OPENAI_API_KEY');
        $tmpFile = tempnam(sys_get_temp_dir(), 'ctx_');
        file_put_contents($tmpFile, $context);
        $ch = curl_init('https://api.openai.com/v1/files');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'purpose' => 'assistants',
                'file' => new CURLFile($tmpFile),
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $fileData = json_decode($response, true);
        $fileId = $fileData['id'] ?? null;

        return $fileId;
    }

    protected function CreateAssistantWithFile(string $fileId)
    {
        $apiKey = getenv('OPENAI_API_KEY');
        $ch = curl_init('https://api.openai.com/v1/assistants');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o',
                'name' => 'Data Context Assistant',
                'instructions' => 'Use the attached file as your reference context for answering any question.',
                'file_ids' => [$fileId], // attach the uploaded context file
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $assistant = json_decode($response, true);
        $assistantId = $assistant['id'] ?? null;

        return $assistantId;
    }

    protected function createThread(string $assistantId, string $question)
    {
        // Create thread
        $apiKey = getenv('OPENAI_API_KEY');
        $ch = curl_init('https://api.openai.com/v1/threads');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POST => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $thread = json_decode($response, true);
        $threadId = $thread['id'];

        return $threadId;
    }

    protected function askQuestion($question, $assistantId, $threadId)
    {
        $apiKey = getenv('OPENAI_API_KEY');
        // Add message to the thread
        $question = 'List the top 5 entries with the highest value.';

        $ch = curl_init("https://api.openai.com/v1/threads/$threadId/messages");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'role' => 'user',
                'content' => $question,
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Run the assistant on the thread
        $ch = curl_init('https://api.openai.com/v1/threads/runs');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'assistant_id' => $assistantId,
                'thread_id' => $threadId,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $run = json_decode($response, true);
        $runId = $run['id'];

        return $runId;
    }

    protected function getAnswer($threadId, $runId)
    {
        $apiKey = getenv('OPENAI_API_KEY');
        // Poll until the run completes
        do {
            sleep(2);
            $ch = curl_init("https://api.openai.com/v1/threads/$threadId/runs/$runId");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            ]);
            $statusResponse = curl_exec($ch);
            curl_close($ch);
            $status = json_decode($statusResponse, true);
            $state = $status['status'] ?? '';
            echo "Status: $state\n";
        } while ($state !== 'completed' && $state !== 'failed');

        // Retrieve messages
        $ch = curl_init("https://api.openai.com/v1/threads/$threadId/messages");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $messages = json_decode($response, true);

        $last = $messages['data'][0]['content'][0]['text']['value'] ?? 'No answer';
        echo "Assistant answer:\n$last\n";
    }
}
