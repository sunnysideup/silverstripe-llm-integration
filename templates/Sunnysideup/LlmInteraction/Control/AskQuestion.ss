<article class="ask-question-page">
    <h1>$Title</h1>
    $QuestionForm
    <% if $Message %>
        <p class="$MessageType">$Message</p>
    <% end_if %>
</article>

window.askQuestionConfig = {
    pollIntervals: 10000, // 10 seconds
    endpoints: {
        phpAnswers: 'admin/ask-question/getphpanswers',
        phpExecution: 'admin/ask-question/runphpcode',
        finalAnswer: 'admin/ask-question/getfinalanswer'
    }
};

<script>
document.addEventListener('DOMContentLoaded', () => {
  const cfg = window.askQuestionConfig;
  if (!cfg) return;

  const form = document.querySelector('.ask-question-form');
  if (!form) return;

  const spinner = document.createElement('div');
  spinner.classList.add('spinner');
  spinner.innerHTML = '<div></div><div></div><div></div>';
  form.appendChild(spinner);

  const statusBox = document.createElement('pre');
  statusBox.classList.add('ask-question-status');
  form.parentElement.appendChild(statusBox);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const question = form.querySelector('textarea[name="Question"]').value.trim();
    if (!question) return;

    lockForm();
    updateStatus('Submitting question…');

    // Step 1: Create question record
    let res = await fetch(form.action, { method: 'POST', body: new FormData(form) });
    const questionId = (await res.text()).trim();

    if (!/^\d+$/.test(questionId)) {
      updateStatus('❌ Could not create question record.');
      unlockForm();
      return;
    }
    updateStatus('✅ Question saved (ID ' + questionId + '). Sending to LLM…');

    // Step 2–5: Execute the LLM flow
    const steps = [
      { key: 'sendquestiontollm', label: 'Sending question to LLM…' },
      { key: 'getphpanswers', label: 'Retrieving PHP code from LLM…' },
      { key: 'runphpcode', label: 'Running generated PHP code…' },
      { key: 'getfinalanswer', label: 'Getting final answer…' },
    ];

    for (const step of steps) {
      const endpoint = cfg.endpoints[step.key === 'sendquestiontollm' ? 'sendQuestion' : step.key];
      const result = await pollStep(endpoint, questionId, step.label);

      if (result.status === 'error') {
        updateStatus(`❌ ${step.label} failed: ${result.message}`);
        unlockForm();
        return;
      }

      updateStatus(`✅ ${step.label} complete`);
      if (step.key === 'getfinalanswer' && result.data?.answer) {
        updateStatus('💡 Final Answer:\n\n' + result.data.answer);
      }
    }

    unlockForm();
  });

  // Poll a single endpoint until "done" or timeout
  async function pollStep(endpoint, questionId, label) {
    let tries = 0;
    while (tries < 12) { // 12 * 10s = 2 minutes max
      updateStatus(`${label} (attempt ${tries + 1})`);
      const res = await fetch(`${endpoint}?questionid=${questionId}`, { cache: 'no-store' });
      const json = await safeJson(res);

      if (json.status === 'done') return json;
      if (json.status === 'error') return json;

      await sleep(cfg.pollIntervals);
      tries++;
    }
    return { status: 'error', message: 'Timeout waiting for ' + label };
  }

  function lockForm() {
    form.querySelector('textarea').disabled = true;
    form.querySelector('button').disabled = true;
    spinner.style.display = 'flex';
  }

  function unlockForm() {
    form.querySelector('textarea').disabled = false;
    form.querySelector('button').disabled = false;
    spinner.style.display = 'none';
  }

  function updateStatus(text) {
    statusBox.textContent = text;
  }

  function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  async function safeJson(res) {
    try {
      return await res.json();
    } catch {
      return { status: 'error', message: 'Invalid JSON response' };
    }
  }
});
</script>



<style>
.ask-question-form {
  position: relative;
}
.spinner {
  display: none;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}
.spinner div {
  width: 1rem;
  height: 1rem;
  margin: 0.2rem;
  background: #0074d9;
  border-radius: 50%;
  animation: bounce 1.4s infinite ease-in-out;
}
.spinner div:nth-child(1) { animation-delay: -0.32s; }
.spinner div:nth-child(2) { animation-delay: -0.16s; }

@keyframes bounce {
  0%, 80%, 100% { transform: scale(0); }
  40% { transform: scale(1.0); }
}

.ask-question-status {
  margin-top: 1em;
  background: #f6f6f6;
  padding: 1em;
  border-radius: 6px;
  white-space: pre-wrap;
}
</style>
