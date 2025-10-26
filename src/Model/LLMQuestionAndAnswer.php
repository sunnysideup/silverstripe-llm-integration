<?php

namespace Sunnnysideup\LLMIntegration\Model;

use ReflectionClass;
use ReflectionMethod;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

class LLMQuestionAndAnswer extends DataObject
{
    private static string $table_name = 'LLMIntegration_LLMQuestionAndAnswer';
    private static $singular_name = 'LLM Question and Answer';

    private static $plural_name = 'LLM Questions and Answers';
    private static array $db = [
        'Question' => 'Text',
        'PHPRetrievalStarted' => 'Boolean',
        'PHPRetrievalCompleted' => 'Boolean',
        'RunID' => 'Varchar(100)',
        'PHPCode' => 'Text',
        'PHPExecutionStarted' => 'Boolean',
        'PHPExecutionCompleted' => 'Boolean',
        'Answer' => 'Text',
        'Archived' => 'Boolean',
    ];

    private static array $has_one = [
        'Thread' => LLMThreadProvider::class,
    ];

    private static array $default_sort = [
        'ID' => 'DESC',
    ];

    private static $summary_fields = [
        'Question' => 'Question',
        'Answer' => 'Answer',
    ];

    private static array $indexes = [
        'Question' => true,
        'PHPRetrievalStarted' => true,
        'PHPRetrievalCompleted' => true,
        'PHPExecutionStarted' => true,
        'PHPExecutionCompleted' => true,
        'Archived' => true
    ];

    private static $cascade_deletes = [
        'Thread',
    ];

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        foreach (['Question', 'Answer', 'PHPCode'] as $fieldName) {
            $field = $fields->dataFieldByName($fieldName);
            if ($field) {
                $field->setReadonly(true);
            }
        }
        return $fields;
    }

    public function getReadonlyFields(): array
    {
        return [
            'Question',
            'PHPRetrievalStarted',
            'PHPRetrievalCompleted',
            'RunID',
            'PHPCode',
            'PHPExecutionStarted',
            'PHPExecutionCompleted',
            'ThreadID',
        ];
    }
}
