
# Possible Sequence

1. ask question
2. lock down asking questions - show loading spinner
3. send question to back-end
4. every 10 seconds, poll for getting php answers 'admin/ask-question/getphpanswers'
5. every 10 seconds, poll for running php answers 'admin/ask-question/runphpcode'
6. every 10 seconds, poll for the final answer 'admin/ask-question/getfinalanswer'

