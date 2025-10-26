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

    private static array $db = [
        'Hash' => 'Varchar(255)',
        'SentToLLM' => 'Boolean',
        'LLMFileID' => 'Varchar(50)',
        'AssistantID' => 'Varchar(255)',
        'Archived' => 'Boolean',
    ];

    private static $singular_name = 'Model Context Provider';

    private static $plural_name = 'Model Context Providers';

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
        'LLMThreads.Count' => 'Number of LLM Threads',
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

    public function canEdit($member = null): bool
    {
        return false;
    }
}
