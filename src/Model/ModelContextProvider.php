<?php

namespace Sunnnysideup\LLMIntegration\Model;

use ReflectionClass;
use ReflectionMethod;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

class ModelContextProvider extends DataObject
{
    private static string $table_name = 'LLMIntegration_ModelContextProvider';
    private static $singular_name = 'Model Context Provider';

    private static $plural_name = 'Model Context Providers';

    private static array $db = [
        'Hash' => 'Varchar(255)',
        'SentToLLM' => 'Boolean',
        'LLMFileID' => 'Varchar(50)',
        'AssistantID' => 'Varchar(255)',
        'Archived' => 'Boolean',
    ];


    private static array $has_many = [
        'LLMThreads' => LLMThreadProvider::class,
    ];

    private static array $default_sort = [
        'ID' => 'DESC',
    ];

    private static $summary_fields = [
        'Created' => 'Created',
        'Hash' => 'Hash',
        'SentToLLM.Nice' => 'Sent To LLM',
        'LLMFileID' => 'LLM File ID',
        'LLMThreads.Count' => 'Number of LLM Chats',
    ];

    private static array $field_labels = [
        'LLMThreads' => 'LLM Chats',
        'LLMThreads.Count' => 'Number of LLM Chats',
    ];

    private static array $indexes = [
        'Hash' => [
            'type' => 'unique',
            'columns' => ['Hash'],
        ],
        'SentToLLM' => true,
        'LLMFileID' => true,
    ];

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }


    public function onAfterWrite()
    {
        parent::onAfterWrite();
        if ($this->Archived) {
            $questionsAndAnswers = $this->QuestionsAndAnswers()->filter(['Archived' => false]);
            foreach ($questionsAndAnswers as $questionsAndAnswer) {
                $questionsAndAnswer->Archived = true;
                $questionsAndAnswer->write();
            }
        }
    }

    public function getReadonlyFields(): array
    {
        return [
            'Hash',
            'SentToLLM',
            'LLMFileID',
            'AssistantID',
            'LLMThreads',
        ];
    }
}
