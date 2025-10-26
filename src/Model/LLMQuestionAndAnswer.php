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
