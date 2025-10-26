<?php

namespace Sunnnysideup\LLMIntegration\Model;

use ReflectionClass;
use ReflectionMethod;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

class LLMThreadProvider extends DataObject
{
    private static string $table_name = 'LLMIntegration_LLMThreadProvider';
    private static $singular_name = 'LLM Chat';

    private static $plural_name = 'LLM Chats';
    private static array $db = [
        'ThreadID' => 'Varchar(255)',
        'Archived' => 'Boolean',
    ];

    private static array $has_one = [
        'Context' => ModelContextProvider::class,
        'User' => Member::class,
    ];

    private static array $has_many = [
        'QuestionsAndAnswers' => LLMQuestionAndAnswer::class,
    ];

    private static array $default_sort = [
        'ID' => 'DESC',
    ];

    private static $summary_fields = [
        'Created' => 'Created',
        'ThreadID' => 'Thread ID',
        'User.Email' => 'User Email',
    ];

    private static $cascade_deletes = [
        'QuestionsAndAnswers',
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
            $threads = $this->Thread()->filter(['Archived' => false]);
            foreach ($threads as $thread) {
                $thread->Archived = true;
                $thread->write();
            }
        }
    }
    public function getReadonlyFields(): array
    {
        return [
            'ThreadID',
            'ContextID',
            'UserID',
            'QuestionsAndAnswers',
        ];
    }
}
