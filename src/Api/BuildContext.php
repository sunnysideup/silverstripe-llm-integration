<?php

namespace Sunnysideup\LLMIntegration\Api;

use CURLFile;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
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
use SilverStripe\Security\MemberPassword;
use Sunnnysideup\LLMIntegration\Model\ModelContextProvider;
use Throwable;

class BuildContext
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



    private static $classes_to_exclude = [
        DataObject::class,
        MemberPassword::class,
    ];


    public function run(): void
    {
        $content = $this->getContext();
        $hash = $this->generateHash($content);
        $filter = ['Hash' => $hash, 'Archived' => false, 'SentToLLM' => true, 'LLMFileID:Not' => '', 'AssistantID:Not' => ''];
        $existing = ModelContextProvider::get()->filter($filter)->first();
        if ($existing && $existing->SentToLLM) {
            return;
        }
        DB::alteration_message('Creating new ModelContextProvider by uploading file', 'created');
        $llmFileID = $this->uploadFile($content);
        $filter['SentToLLM'] = true;
        $filter['LLMFileID'] = $llmFileID;
        $objID = ModelContextProvider::create($filter)
            ->write();
        $obj = ModelContextProvider::get()->byID($objID);
        if (!$obj) {
            throw new RuntimeException('Could not find newly created ModelContextProvider with ID ' . $objID);
        }
        DB::alteration_message('Creating assistant for ModelContextProvider ID ' . $objID, 'created');
        $obj->AssistantID = $this->createAssistant($llmFileID);
        $obj->write();
        if ($obj->LLMFileID || $obj->AssistantID) {
            $others = ModelContextProvider::get()->filter([
                'ID:LessThan' => $obj->ID,
                'SentToLLM' => true,
            ]);
            foreach ($others as $other) {
                DB::alteration_message('Deleting old file and assistant for ModelContextProvider ID ' . $other->ID, 'deleted');
                try {
                    $this->deleteOldFileAndAssistant($other->LLMFileID, $other->AssistantID);
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

    protected function generateHash(string $context): string
    {
        return hash('sha256', $context);
    }

    protected function getContext(): string
    {
        $classes = ClassInfo::subclassesFor(DataObject::class, false);
        $schema = DataObject::getSchema();
        $array = [];
        $exclude = Config::inst()->get(static::class, 'classes_to_exclude') ?? [];
        foreach ($classes as $className) {
            if (in_array($className, $exclude, true)) {
                continue;
            }
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

            $fields = [];
            foreach (static::FIELDS as $field) {
                $fieldData = $config->get($field);
                if (!empty($fieldData) && is_array($fieldData)) {
                    $fields[$field] = $fieldData;
                }
            }

            $methods = $this->getMethodsFromClass($reflection, $className);

            $array[] = [
                'ClassName' => $className,
                'TableName' => $schema->tableName($className),
                // 'DBFields' => $fields,
                // 'ParentClass' => $parentClass ? $parentClass->getName() : null,
                // 'UninheritedPublicMethods' => $methods,
            ];
        }
        return json_encode($array, JSON_PRETTY_PRINT);
    }

    protected function uploadFile(string $context): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ctx_');
        file_put_contents($tmpFile, $context);

        $response = ChatWithChatGPT::singleton()->SendFileToChatGPT($context);

        if (empty($response['id'])) {
            throw new RuntimeException('Could not create file: ' . json_encode($response));
        }
        return $response['id'];
    }

    protected function createAssistant(string $fileId): string
    {
        $chat = ChatWithChatGPT::singleton();
        $config = $chat->config();
        $contextInstructions = $config->get('context_instructions');
        $phpRules = $config->get('standard_instructions');
        $modelName = $config->get('model_name');
        $temperature = $config->get('temperature');
        $response = $chat->talkToChatGPT(
            'assistants',
            'POST',
            [
                'model' => $modelName,
                'temperature' => $temperature,
                'name' => 'Silverstripe Context Assistant',
                'instructions' => $contextInstructions . "\n\n" . $phpRules,
                'tools' => [
                    ['type' => 'file_search']
                ],
                'tool_resources' => [
                    'file_search' => [
                        'vector_stores' => [
                            [
                                'file_ids' => [$fileId]
                            ]
                        ]
                    ]
                ],
            ]
        );

        if (empty($response['id'])) {
            throw new RuntimeException('Could not create assistant: ' . json_encode($response));
        }
        return $response['id'];
    }

    protected function deleteOldFileAndAssistant(?string $fileId, ?string $assistantId): void
    {
        if ($assistantId) {
            ChatWithChatGPT::singleton()->talkToChatGPT("assistants/{$assistantId}", 'DELETE');
        }
        if ($fileId) {
            ChatWithChatGPT::singleton()->talkToChatGPT("files/{$fileId}", 'DELETE');
        }
    }


    protected function getMethodsFromClass(ReflectionClass $reflection, string $className): array
    {
        $ownMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn(ReflectionMethod $m) =>
            $m->getDeclaringClass()->getName() === $className
                || in_array($m->getDeclaringClass()->getName(), array_keys($reflection->getTraits()), true)
        );

        return array_map(function (ReflectionMethod $m): string {
            $static = $m->isStatic() ? ' static' : '';

            // Build parameter list
            $params = [];
            foreach ($m->getParameters() as $p) {
                $param = '';
                if ($p->hasType()) {
                    $param .= $this->formatType($p->getType()) . ' ';
                }
                if ($p->isPassedByReference()) {
                    $param .= '&';
                }
                if ($p->isVariadic()) {
                    $param .= '...';
                }
                $param .= '$' . $p->getName();
                if ($p->isDefaultValueAvailable()) {
                    try {
                        $default = $p->getDefaultValue();
                        $param .= ' = ' . var_export($default, true);
                    } catch (Throwable) {
                        // ignore unexportable defaults
                    }
                }
                $params[] = $param;
            }

            $paramList = implode(', ', $params);
            $returnType = $m->hasReturnType() ? ': ' . $this->formatType($m->getReturnType()) : '';

            return sprintf(
                'public%s function %s(%s)%s',
                $static,
                $m->getName(),
                $paramList,
                $returnType
            );
        }, $ownMethods);
    }

    protected function formatType(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '') . $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn($t) => $this->formatType($t), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn($t) => $this->formatType($t), $type->getTypes()));
        }

        return '';
    }
}
