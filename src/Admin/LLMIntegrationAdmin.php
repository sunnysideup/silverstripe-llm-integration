<?php

namespace Sunnysideup\LLMIntegration\Admin;

use SilverStripe\Admin\ModelAdmin;

use Sunnnysideup\LLMIntegration\Model\LLMThreadProvider;
use Sunnnysideup\LLMIntegration\Model\LLMQuestionAndAnswer;
use Sunnnysideup\LLMIntegration\Model\ModelContextProvider;

class LLMIntegrationAdmin extends ModelAdmin
{
    private static string $menu_title = 'LLM Integration';

    private static string $url_segment = 'llm-integration';

    private static array $managed_models = [
        ModelContextProvider::class,
        LLMThreadProvider::class,
        LLMQuestionAndAnswer::class,
    ];
}
