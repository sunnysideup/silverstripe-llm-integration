<?php

namespace Sunnysideup\LLMIntegration\Control;

use RuntimeException;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DB;
use SilverStripe\View\Requirements;
use Sunnnysideup\LLMIntegration\Model\LLMQuestionAndAnswer;
use Sunnysideup\LLMIntegration\Api\BuildContext;
use Sunnysideup\LLMIntegration\Api\BuildThread;
use Sunnysideup\LLMIntegration\Api\ChatWithChatGPT;
use Sunnnysideup\LLMIntegration\Model\ModelContextProvider;
use Sunnnysideup\LLMIntegration\Model\LLMThreadProvider;
use Throwable;

class AskQuestion extends Controller
{
    protected $debug = false;

    private static array $allowed_actions = [
        'index' => 'ADMIN',
        'QuestionForm' => 'ADMIN',
        'doAskQuestion' => 'ADMIN',
        'sendquestiontollm' => 'ADMIN',
        'getphpanswers' => 'ADMIN',
        'runphpcode' => 'ADMIN',
        'getfinalanswer' => 'ADMIN',
    ];

    private static string $reminder_text = <<<TXT
Reminder: Return only PHP 8.3 Silverstripe ORM code. Last line must be return with human explanation.
TXT;

    private static string $url_segment = 'ask-question';

    public function index(HTTPRequest $request)
    {
        $array = [
            'pollIntervals' => 10000, // 10 seconds
            'endpoints' => [
                'sendQuestion' => $this->Link('sendquestiontollm'),
                'phpAnswers' => $this->Link('getphpanswers'),
                'phpExecution' => $this->Link('runphpcode'),
                'finalAnswer' => $this->Link('getfinalanswer')
            ]
        ];
        Requirements::customScript(
            'window.askQuestionConfig = ' . json_encode($array) . ';',
            'AskQuestionConfig'
        );
        return [
            'Title' => 'Ask a Question',
            'QuestionForm' => $this->QuestionForm(),
        ];
    }

    public function QuestionForm(): Form
    {
        $fields = FieldList::create(
            TextareaField::create('Question', 'Your Question')
                ->setRows(6)
                ->setAttribute('placeholder', 'Ask me anything about the Silverstripe data model...')
        );

        $actions = FieldList::create(
            FormAction::create('doAskQuestion', 'Ask')
                ->addExtraClass('btn btn-primary')
        );

        $validator = RequiredFields::create('Question');

        return Form::create($this, 'QuestionForm', $fields, $actions, $validator)
            ->setFormMethod('POST')
            ->addExtraClass('ask-question-form');
    }

    public function doAskQuestion(array $data, Form $form, HTTPRequest $request)
    {
        $question = trim($data['Question'] ?? '');

        if ($question === '') {
            $form->sessionMessage('Please enter a question.', 'bad');
            return $this->redirectBack();
        }
        $filter = [
            'Question' => $question,
            'ThreadID' => $this->getThread()->ID,
            'Archived' => false,
        ];
        $quetion = LLMThreadProvider::get()->filter($filter)->first();
        if (!$quetion) {
            $question = LLMThreadProvider::create($filter);
            $question->write();
        }
        return $question->ID;
    }


    public function sendquestiontollm(HTTPRequest $request): HTTPResponse
    {
        // get question
        $question = $this->getQuestionFromRequest($request, ['PHPRetrievalStarted' => false]);
        if (!$question) {
            $question = $this->getQuestionFromRequest($request, ['PHPRetrievalStarted' => true]);
            if ($question) {
                return $this->jsonResponse('done', 'PHP retrieval already started.');
            } else {
                return $this->jsonResponse('error', 'No question found.');
            }
        }
        // mark as started
        $question->PHPRetrievalStarted = true;
        $question->write();

        // Create or reuse a thread
        $assistantId = $this->getAssistantID();
        $threadObject = $this->getThread();
        $threadId = $threadObject->ThreadID;

        // pose question
        $reminder = Config::inst()->get(static::class, 'reminder_text');
        ChatWithChatGPT::singleton()->talkToChatGPT("threads/{$threadId}/messages", 'POST', [
            'role' => 'user',
            'content' => $reminder . "\n\nQuestion: " . $question,
        ]);

        // get run id
        $run = ChatWithChatGPT::singleton()->talkToChatGPT('threads/runs', 'POST', [
            'assistant_id' => $assistantId,
            'thread_id' => $threadId,
        ]);

        // mark run id
        $runId = $run['id'] ?? null;
        if (!$runId) {
            throw new RuntimeException('Failed to start assistant run.');
        }
        $question->RunID = $runId;
        $question->write();

        return $this->jsonResponse('done', 'question sent to LLM.');
    }

    public function getphpanswers(HTTPRequest $request): HTTPResponse
    {
        // get question
        $question = $this->getQuestionFromRequest($request, ['PHPRetrievalStarted' => true, 'PHPRetrievalCompleted' => false]);
        if (!$question) {
            $question = $this->getQuestionFromRequest($request, ['PHPRetrievalStarted' => true, 'PHPRetrievalCompleted' => true]);
            if ($question) {
                return $this->jsonResponse('done', 'PHP retrieval already completed.');
            } else {
                return $this->jsonResponse('error', 'No question found.');
            }
        }

        // get thread and run id
        $threadObject = $this->getThread();
        $threadId = $threadObject->ThreadID;
        $runId = $question->RunID;

        // poll for completion
        $count = 0;
        do {
            sleep(2);
            $status = ChatWithChatGPT::singleton()->talkToChatGPT("threads/{$threadId}/runs/{$runId}");
            $state = $status['status'] ?? '';
            $count++;
        } while ($state !== 'completed' && $state !== 'failed' && $count < 10);

        if ($state === 'failed') {
            return $this->jsonResponse('error', 'Assistant run failed.');
        }

        // treat incomplete
        if ($state !== 'completed') {
            return $this->jsonResponse('pending', 'Assistant run not completed yet.');
        }

        // get PHP answer
        $messages = ChatWithChatGPT::singleton()->talkToChatGPT("threads/{$threadId}/messages");
        $latest = $messages['data'][0] ?? end($messages['data']) ?? null;
        $answer = $latest['content'][0]['text']['value'] ?? 'echo "<p>No answer received from assistant.</p>";';

        // save answer
        $question->PHPCode = $answer;
        $question->PHPRetrievalCompleted = true;
        $question->write();
        return $this->jsonResponse('done', 'PHP retrieval completed.');
    }


    public function runphpcode(HTTPRequest $request): HTTPResponse
    {
        // get question
        $question = $this->getQuestionFromRequest($request, ['PHPRetrievalCompleted' => true, 'PHPExecutionStarted' => false]);
        if (!$question) {
            $question = $this->getQuestionFromRequest($request, ['PHPRetrievalCompleted' => true, 'PHPExecutionStarted' => true]);
            if ($question) {
                return $this->jsonResponse('done', 'PHP execution already started.');
            } else {
                return $this->jsonResponse('error', 'No question found.');
            }
        }

        // mark as started
        $question->PHPExecutionStarted = true;
        $question->write();

        // get PHP Code
        $php = $question->PHPCode;

        // execute PHP code
        try {
            $fx = 'return (function() {' . $php . '})();';

            $answer = eval($fx);
            if ($answer === null) {
                if ($this->debug) {
                    $answer = 'No result returned. We tried to run the following PHP code but it did not return anything. Please ensure the last line of the PHP code is a return statement: ' . $php;
                } else {
                    $answer = 'No result returned.';
                }
            }
        } catch (Throwable $e) {
            $answer = 'Error: ' . $e->getMessage();
        }

        // save answer
        $question->Answer = $answer;
        $question->PHPExecutionCompleted = true;
        $question->write();

        // return answer
        return $this->jsonResponse('done', 'PHP execution completed.');
    }


    public function getfinalanswer(HTTPRequest $request): HTTPResponse
    {
        $question = $this->getQuestionFromRequest($request, ['PHPExecutionCompleted' => true]);
        if (!$question) {
            return $this->jsonResponse('pending', 'Question is still being processed.');
        }
        return $this->jsonResponse('done', 'PHP execution completed.', ['answer' => $question->Answer]);
    }

    protected $contextCache = null;

    protected function getContext(): ?ModelContextProvider
    {
        if ($this->contextCache) {
            return $this->contextCache;
        }

        // 1. Get latest assistant (created by BuildContext)
        $this->contextCache = ModelContextProvider::get()
            ->filter(['SentToLLM' => true, 'Archived' => false, 'AssistantID:Not' => ''])
            ->sort('ID', 'DESC')
            ->first();

        if (!$this->contextCache || !$this->contextCache->AssistantID) {
            throw new RuntimeException('No assistant found. Run dev/build first.');
        }
        return $this->contextCache;
    }

    protected function getAssistantID(): string
    {
        $context = $this->getContext();
        return $context->AssistantID;
    }

    protected $threadCache = null;
    protected function getThread(): ?LLMThreadProvider
    {
        if ($this->threadCache) {
            return $this->threadCache;
        }
        $context = $this->getContext();
        $this->threadCache = BuildThread::singleton()->CreateThread($context);
        return $this->threadCache;
    }

    protected function getQuestionFromRequest(HTTPRequest $request, $filter): ?LLMQuestionAndAnswer
    {
        $questionID = (int) $request->getVar('questionid');
        $thread = $this->getThread();
        $filter = array_merge(
            [
                'ID' => $questionID,
                'ThreadID' => $thread?->ID,
            ],
            $filter
        );
        $question = LLMQuestionAndAnswer::get()->filter($filter)->first();
        return $question;
    }

    protected function jsonResponse(string $status, string $message = '', array $data = []): HTTPResponse
    {
        return HTTPResponse::create(json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ]))
            ->addHeader('Content-Type', 'application/json');
    }
}
