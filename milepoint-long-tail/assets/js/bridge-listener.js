/**
 * MilePoint Long-Tail Bridge - Clean Polling Version
 */
(function () {
  let lastContentHash = "";
  const pollInterval = 1000; // 1 second

  const getCleanHTML = (node) => {
    if (!node) return "";
    const clone = node.cloneNode(true);

    // Remove the inline grey bubbles (pills)
    clone.querySelectorAll('gist-citation-pill').forEach(p => p.remove());

    // Get the HTML, strip all <!-- comments -->
    return clone.innerHTML.replace(/<!--(.*?)-->/sg, '').trim();
  };


  const getTranscript = (thread) => {
    return Array.from(thread.querySelectorAll('.qa-block')).map(block => {
      // 1. Clean Question
      const question = getCleanHTML(block.querySelector('.question'));

      // 2. Clean Answer (from Shadow DOM)
      const responseWidget = block.querySelector('gist-chat-response');
      let answer = "";
      if (responseWidget?.shadowRoot) {
        const content = responseWidget.shadowRoot.querySelector('.response-content');
        answer = getCleanHTML(content);
      }

      // 3. Capture Citations/Sources (from Carousel Shadow DOM)
      const carousel = block.querySelector('gist-citation-carousel');
      let sources = [];
      if (carousel?.shadowRoot) {
        const cards = carousel.shadowRoot.querySelectorAll('a.gist-citation-card');
        sources = Array.from(cards).map(card => ({
          url: card.href,
          title: card.querySelector('.gist-citation-card-title')?.innerText.trim() || "View Source",
          excerpt: card.querySelector('.gist-citation-card-first-words')?.innerText.trim() || ""
        }));
      }

      return { question, answer, sources };
    });
  };

  const getRelated = (thread) => {
    const relatedWidget = thread.querySelector('gist-related-questions');
    if (!relatedWidget?.shadowRoot) return [];
    const items = relatedWidget.shadowRoot.querySelectorAll('.related-question-item span');
    return Array.from(items).map(el => el.innerHTML.trim());
  };



  const runPoll = async () => {
    const widget = document.querySelector('gist-chat-widget');
    const shadow = widget?.shadowRoot;
    if (!shadow) return;

    const thread = shadow.querySelector('.chat-thread');
    if (!thread) return;

    const isCompleted = thread.getAttribute('data-completed') === 'true';
    const transcript = getTranscript(thread);
    const related = getRelated(thread);

    // hash to determine if we update
    const currentContentHash = JSON.stringify({ t: transcript, r: related, isCompleted });

    if (currentContentHash === lastContentHash) return;

    console.log("-----------------------------------------");
    console.log("MilePoint Bridge: [CHANGE DETECTED]");
    lastContentHash = currentContentHash;

    const hasContent = transcript.length > 0 && transcript[0].answer.length > 0;

    if (isCompleted && hasContent) {
      const threadId = new URLSearchParams(window.location.search).get('gist-thread');

      if (!threadId) {
        console.log("MilePoint Bridge: Waiting for Thread ID in URL...");
        return;
      }

      const payload = {
        thread_id: threadId,
        full_transcript: transcript, // Now contains .sources for each entry
        related_suggestions: related
      };

      console.log("MilePoint Bridge: [PAYLOAD PREPARED]", payload);

      try {
        const response = await fetch(mpData.rest_url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': mpData.nonce
          },
          body: JSON.stringify(payload)
        });

        console.log(`MilePoint Bridge: [SERVER RESPONSE] Status: ${response.status}`);
        const result = await response.json();
        console.log("MilePoint Bridge: [SERVER MESSAGE]", result.message);
      } catch (err) {
        console.error("MilePoint Bridge: [NETWORK ERROR]", err);
      }
    }
  };

  console.log("MilePoint Bridge: Polling started (1s)");
  setInterval(runPoll, pollInterval);

  /**
   * Pre-fill the chat input if ?q= parameter is present
   */
  const prefillChat = () => {
    const params = new URLSearchParams(window.location.search);
    const query = params.get('q');
    if (!query) return;

    let attempts = 0;
    const maxAttempts = 20; // 10 seconds

    const interval = setInterval(() => {
      attempts++;
      let textarea = null;

      // Try locating textarea in Light DOM or Shadow DOM
      const host = document.querySelector('gist-chat-prompt');
      if (host) {
        if (host.shadowRoot) {
            textarea = host.shadowRoot.querySelector('textarea');
            if (!textarea) {
                // Try specific path provided: div > div > div > textarea
                textarea = host.shadowRoot.querySelector('div > div > div > textarea');
            }
        } else {
            textarea = host.querySelector('textarea');
        }
      }

      // Fallback: direct global search if component name differs or structure is flat
      if (!textarea) {
          textarea = document.querySelector('#prompt-host textarea');
      }


      if (textarea) {
        console.log("MilePoint Bridge: Found chat input, pre-filling...");

        // Set value
        textarea.value = query;

        // Dispatch events to trigger framework bindings
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.dispatchEvent(new Event('change', { bubbles: true }));

        // Auto-resize
        if (textarea.scrollHeight > textarea.clientHeight) {
            textarea.style.height = textarea.scrollHeight + 'px';
        }

        clearInterval(interval);
      } else if (attempts >= maxAttempts) {
        console.log("MilePoint Bridge: Could not find chat input after 10s.");
        clearInterval(interval);
      }
    }, 500);
  };

  prefillChat();
})();