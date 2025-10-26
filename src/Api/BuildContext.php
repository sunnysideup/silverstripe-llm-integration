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
use SilverStripe\ORM\DB;
use Sunnnysideup\LLMIntegration\Model\ModelContextProvider;
use Throwable;

class BuildContext implements Flushable
{

    use Injectable;
    use Extensible;
    use Configurable;

    private const FIELDS = [
        'db',
        'belongs_to',
        'has_one',
        'has_many',
        'many_many',
        'belongs_many_many',
    ];

    private static string $context_instructions = 'Use the attached file as your reference context for answering any question.';
    private static string $standard_instructions = <<<TXT
When answering:
- You are an expert SilverStripe developer. Provide concise and accurate answers based on the provided context.
- Output PHP code only (no prose, no backticks, no ```php fences).
- Use PHP 8.3 syntax and SilverStripe ORM where possible.
- As part of the php code, you can ask yourself a question, if needed. You do it like this: \Sunnysideup\LLMIntegration\Api\ChatWithChatGPT::ask_question_outside_of_thread(\$question): string; This will be helpful if you want to review some content as part your PHP code so that you can give a better answer. You can specificy yourself how you want the answer to be and then check for this answer in PHP.
- The code must run without errors in a standard Silverstripe 5 project.
- Always check canView(), canCreate(), canEdit(), and canDelete() as appropriate.
- The final line MUST be a return statement with a human-readable answer.
- Return the human-readable answer as HTML.

TXT;

    private static $model_name = 'dd';
    private static $temperature = 'A model for handling dd tasks.';


    public static function flush(): void
    {
        $content = self::get_context();
        $hash = self::generate_hash($content);
        $filter = ['Hash' => $hash, 'Archived' => false, 'SentToLLM' => true, 'LLMFileID:Not' => '', 'AssistantID:Not' => ''];
        $existing = ModelContextProvider::get()->filter($filter)->first();
        if ($existing && $existing->SentToLLM) {
            return;
        }
        DB::alteration_message('Creating new ModelContextProvider by uploading file', 'created');
        $llmFileID = self::upload_file($content);
        $filter['SentToLLM'] = true;
        $filter['LLMFileID'] = $llmFileID;
        $objID = ModelContextProvider::create($filter)
            ->write();
        $obj = ModelContextProvider::get()->byID($objID);
        if (!$obj) {
            throw new RuntimeException('Could not find newly created ModelContextProvider with ID ' . $objID);
        }
        DB::alteration_message('Creating assistant for ModelContextProvider ID ' . $objID, 'created');
        $obj->AssistantID = self::create_assistant($llmFileID);
        $obj->write();
        if ($obj->LLMFileID || $obj->AssistantID) {
            $others = ModelContextProvider::get()->filter([
                'ID:LessThan' => $obj->ID,
                'SentToLLM' => true,
            ]);
            foreach ($others as $other) {
                DB::alteration_message('Deleting old file and assistant for ModelContextProvider ID ' . $other->ID, 'deleted');
                try {
                    self::delete_old_file_and_assistant($other->LLMFileID, $other->AssistantID);
                } catch (Throwable $e) {
                    DB::alteration_message('Failed to delete old assistant or file: ' . $e->getMessage(), 'error');
                }
                $other->Archived = true;
                $other->write();
            }
        }
        DB::alteration_message(sprintf(
            'LLM context sync complete. File: %s, Assistant: %s, Hash: %s',
            $obj->LLMFileID,
            $obj->AssistantID,
            $hash
        ), 'created');
    }

    protected static function generate_hash(string $context): string
    {
        return hash('sha256', $context);
    }

    protected static function get_context(): string
    {
        $classes = ClassInfo::subclassesFor(DataObject::class, false);
        $schema = DataObject::getSchema();
        $array = [];
        foreach ($classes as $className) {
            try {
                $reflection = new ReflectionClass($className);
            } catch (Throwable $e) {
                DB::alteration_message("Skipping $className: reflection failed ({$e->getMessage()})", 'error');
                continue;
            }

            if ($reflection->isAbstract()) {
                continue;
            }
            $config = Injector::inst()->get($className)->config();


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
        return json_encode($array, JSON_PRETTY_PRINT);
    }

    protected static function upload_file(string $context): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ctx_');
        file_put_contents($tmpFile, $context);

        $response = ChatWithChatGPT::talk_to_chatgpt(
            'files',
            'POST',
            [
                'purpose' => 'assistants',
                'file' => new CURLFile($tmpFile, 'application/json', 'context.json'),
            ],
            sendingJson: false
        );

        unlink($tmpFile);
        if (empty($response['id'])) {
            throw new RuntimeException('Could not create file: ' . json_encode($response));
        }
        return $response['id'];
    }

    protected static function create_assistant(string $fileId): string
    {
        $contextInstructions = Config::inst()->get(static::class, 'context_instructions');
        $phpRules = Config::inst()->get(static::class, 'standard_instructions');
        $modelName = Config::inst()->get(static::class, 'model_name');
        $temperature = Config::inst()->get(static::class, 'temperature');
        $response = ChatWithChatGPT::talk_to_chatgpt(
            'assistants',
            'POST',
            [
                'model' => $modelName,
                'temperature' => $temperature,
                'name' => 'Silverstripe Context Assistant',
                'instructions' => $contextInstructions . "\n\n" . $phpRules,
                'file_ids' => [$fileId],
            ]
        );

        if (empty($response['id'])) {
            throw new RuntimeException('Could not create assistant: ' . json_encode($response));
        }
        return $response['id'];
    }

    protected static function delete_old_file_and_assistant(?string $fileId, ?string $assistantId): void
    {
        if ($assistantId) {
            ChatWithChatGPT::talk_to_chatgpt("assistants/{$assistantId}", 'DELETE');
        }
        if ($fileId) {
            ChatWithChatGPT::talk_to_chatgpt("files/{$fileId}", 'DELETE');
        }
    }
}
