<?php

namespace Sunnysideup\LLMIntegration\Api;

use CURLFile;
use ReflectionClass;
use ReflectionMethod;
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

class BuildThread
{

    use Configurable;
    use Injectable;
    use Extensible;

    private static int $age_limit_in_hours = 48;


    public function CreateThread(ModelContextProvider $context): static|LLMThreadProvider
    {
        $hours = Config::inst()->get(static::class, 'age_limit_in_hours');
        $filter = [
            'UserID' => Security::getCurrentUser()?->ID,
            'ContextID' => $context->ID,
            'Created:GreaterThan' => date('Y-m-d H:i:s', strtotime('-' . $hours . ' hours'))
        ];
        $existing = LLMThreadProvider::get()->filter($filter)->first();
        if ($existing && $existing->ThreadID) {
            return $existing;
        }
        $thread = ChatWithChatGPT::singleton()->talkToChatGPT('threads', 'POST');
        $threadId = $thread['id'] ?? null;

        if (empty($threadId)) {
            throw new \RuntimeException('Failed to create LLM thread: ' . json_encode($thread));
        }
        $filter['ThreadID'] = $threadId;
        $id = LLMThreadProvider::create($filter)->write();
        return LLMThreadProvider::get()->byID($id);
    }
}
