(function () {
  const urlParams = new URLSearchParams(window.location.search);
  const question = urlParams.get('q');

  if (!question) return;

  const MAX_RETRIES = 20; // Try for 10 seconds
  const RETRY_INTERVAL = 500;
  let attempts = 0;

  function findTextarea() {
    // Attempt to find the prompt element
    let prompt = document.querySelector('gist-chat-prompt');

    // If not found directly, check inside the main widget shadow DOM (if it exists)
    if (!prompt) {
      const widget = document.querySelector('gist-chat-widget');
      if (widget && widget.shadowRoot) {
        prompt = widget.shadowRoot.querySelector('gist-chat-prompt');
      }
    }

    // If still not found, check specific container ID (redundant if querySelector works globally, but specific)
    if (!prompt) {
      const host = document.getElementById('prompt-host');
      if (host) {
        prompt = host.querySelector('gist-chat-prompt');
      }
    }

    // If we have the prompt, check its shadow DOM for the textarea
    if (prompt && prompt.shadowRoot) {
      return prompt.shadowRoot.querySelector('textarea');
    }

    return null;
  }

  function prefill() {
    const textarea = findTextarea();
    if (textarea) {
      textarea.value = question;
      // Dispatch input event so frameworks (React/Lit/Vue/etc) detect the change
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      // Optional: Focus the textarea
      textarea.focus();
      return true;
    }
    return false;
  }

  // Poll until element is found or timeout
  const interval = setInterval(() => {
    if (prefill()) {
      clearInterval(interval);
    } else {
      attempts++;
      if (attempts >= MAX_RETRIES) {
        clearInterval(interval);
        console.log('MilePoint Pre-fill: Could not find chat textarea after ' + attempts + ' attempts.');
      }
    }
  }, RETRY_INTERVAL);

})();
