<?php


namespace Sunnnysideup\LLMIntegration\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use Sunnysideup\LLMIntegration\Api\BuildContext;

class LLMBuildContextTask extends BuildTask
{
    protected $title = 'LLM Build Context Task';

    protected $description = 'Builds the LLM Context for the current models.';

    public function run($request)
    {
        $obj = BuildContext::singleton();
        $obj->run();
    }
}
