/**
 * MilePoint Long-Tail Bridge - Clean Polling Version
 */
// assets/js/bridge-listener.js
// Safe extraction that avoids assumptions about shadow vs light DOM hierarchy
const findElementForBlock = (block, selector) => {
  if (!block) return null;

  // 1. Try light DOM inside block
  let el = block.querySelector(selector);
  if (el) return el;

  // 2. Try inside gist-chat-response shadow DOM
  const responseWidget = block.querySelector("gist-chat-response");
  if (responseWidget && responseWidget.shadowRoot) {
    el = responseWidget.shadowRoot.querySelector(selector);
    if (el) return el;
  }

  // 3. Try adjacent siblings (e.g. if it renders after the .qa-block)
  let next = block.nextElementSibling;
  while (next && (!next.classList || !next.classList.contains("qa-block"))) {
    if (next.matches && next.matches(selector)) return next;
    if (next.querySelector) {
      el = next.querySelector(selector);
      if (el) return el;
    }
    if (next.shadowRoot) {
      el = next.shadowRoot.querySelector(selector);
      if (el) return el;
    }
    next = next.nextElementSibling;
  }

  return null;
};

function getBreakdownFromElement(containerElement) {
  if (!containerElement) return [];

  // Use the robust finder to locate the attribution bar relative to the container
  const attributionBar = containerElement.classList?.contains("qa-block")
      ? findElementForBlock(containerElement, "gist-attribution-bar")
      : containerElement.querySelector("gist-attribution-bar");

  let breakdown = [];
  if (attributionBar && attributionBar.shadowRoot) {
    const columns = attributionBar.shadowRoot.querySelectorAll(".col");

    breakdown = Array.from(columns).map((col) => {
      const labelSpan = col.querySelector(".label");
      if (!labelSpan) return null;

      const percentage = labelSpan.querySelector("b")?.innerText.trim() || "";

      const labelClone = labelSpan.cloneNode(true);
      const bTag = labelClone.querySelector("b");
      if (bTag) bTag.remove();
      const sourceName = labelClone.innerText.trim();

      return {
        source: sourceName,
        percentage: Number(percentage.replace("%", "") || 0),
      };
    }).filter(Boolean);
  }
  return breakdown;
}

// Keep the global fallback for legacy top-level payload structure if needed
function getBreakdown() {
  const widget = document.querySelector("gist-chat-widget");
  return getBreakdownFromElement(widget?.shadowRoot);
}

function getDeepFlattenedClone(node) {
  // 1. Handle text directly
  if (node.nodeType === Node.TEXT_NODE) {
    return node.cloneNode();
  }

  // 1b. Skip comments entirely to prevent non-content junk
  if (node.nodeType === Node.COMMENT_NODE) {
    return document.createTextNode("");
  }

  // 2. If it's a pill, return its shadow content (dissolving the pill tag)
  if (node.tagName === "GIST-CITATION-PILL" && node.shadowRoot) {
    const fragment = document.createDocumentFragment();
    node.shadowRoot.childNodes.forEach((child) => {
      fragment.appendChild(getDeepFlattenedClone(child));
    });
    return fragment;
  }

  // 3. For all other elements, clone the tag and recursively clone its children
  const clone = node.cloneNode(false);
  node.childNodes.forEach((child) => {
    clone.appendChild(getDeepFlattenedClone(child));
  });
  return clone;
}

(function () {
  let lastContentHash = "";
  const pollInterval = 1000; // 1 second

  const getCleanHTML = (node) => {
    if (!node) return "";

    const clone = getDeepFlattenedClone(node);

    // Create a temporary container to hold the cloned structure's CHILDREN
    // If we append the clone itself, container.innerHTML includes the outer tag
    // (e.g., <div class="question">). We only want the inner HTML.
    const container = document.createElement("div");
    if (clone.nodeType === Node.DOCUMENT_FRAGMENT_NODE || clone.nodeType === Node.TEXT_NODE) {
      container.appendChild(clone);
    } else {
      // It's an element, append its children
      while (clone.firstChild) {
        container.appendChild(clone.firstChild);
      }
    }

    // Strip non-content / risky nodes
    const riskySelectors = "style, script, noscript, template, iframe, object, embed, svg, canvas, meta, link";
    container.querySelectorAll(riskySelectors).forEach((el) => { el.remove(); });

    let html = container.innerHTML;

    // Narrow selector-dump cleanup: look for long comma-separated runs of IDs/classes (e.g., #Ads_BA_BS, div.ad_160...)
    // Requires a comma separator and at least one `#` or `.` per item to prevent matching normal prose.
    const junkSelectorPattern = /(?:[a-zA-Z0-9_-]*[#\.][a-zA-Z0-9_-]+,\s*){10,}[a-zA-Z0-9_-]*[#\.][a-zA-Z0-9_-]+(?:\s*\{[^{}]*\})?/g;
    html = html.replace(junkSelectorPattern, "");

    return html.trim();
  };

  const getTranscript = (thread) => {
    return Array.from(thread.querySelectorAll(".qa-block")).map((block) => {
      // 1. Clean Question
      const question = getCleanHTML(block.querySelector(".question"));

      // 2. Clean Answer (from Shadow DOM)
      const responseWidget = block.querySelector("gist-chat-response");
      let answer = "";
      if (responseWidget?.shadowRoot) {
        const content =
          responseWidget.shadowRoot.querySelector(".response-content");
        answer = getCleanHTML(content);
      }

      // 3. Capture Citations/Sources (from Carousel Shadow DOM)
      // Use robust finder in case carousel moved to sibling or shadow DOM
      const carousel = findElementForBlock(block, "gist-citation-carousel");
      let sources = [];
      if (carousel?.shadowRoot) {
        const cards = carousel.shadowRoot.querySelectorAll(
          "a.gist-citation-card",
        );
        sources = Array.from(cards).map((card) => ({
          url: card.href,
          source:
            card.querySelector(".gist-citation-source")?.innerText.trim() ||
            "Unknown",
          favicon: card.querySelector(".gist-citation-favicon img")?.src,
          title:
            card.querySelector(".gist-citation-card-title")?.innerText.trim() ||
            "View Source",
          excerpt:
            card
              .querySelector(".gist-citation-card-first-words")
              ?.innerText.trim() || "",
        }));
      }

      // 4. Capture turn-specific attribution breakdown
      const breakdown = getBreakdownFromElement(block);

      return { question, answer, sources, breakdown };
    });
  };

  const getRelated = (thread) => {
    const relatedWidget = thread.querySelector("gist-related-questions");
    if (!relatedWidget?.shadowRoot) return [];
    const items = relatedWidget.shadowRoot.querySelectorAll(
      ".related-question-item span",
    );
    return Array.from(items)
      .map((el) => (el.textContent || "").trim())
      .filter(Boolean);
  };

  const runPoll = async () => {
    const widget = document.querySelector("gist-chat-widget");
    const shadow = widget?.shadowRoot;
    if (!shadow) return;

    const thread = shadow.querySelector(".chat-thread");
    if (!thread) return;

    const isCompleted = thread.getAttribute("data-completed") === "true";
    const transcript = getTranscript(thread);
    const related = getRelated(thread);

    // hash to determine if we update
    const currentContentHash = JSON.stringify({
      t: transcript,
      r: related,
      isCompleted,
    });

    if (currentContentHash === lastContentHash) return;

    console.log("-----------------------------------------");
    console.log("MilePoint Bridge: [CHANGE DETECTED]");
    lastContentHash = currentContentHash;

    const hasContent = transcript.length > 0 && transcript[0].answer.length > 0;

    if (isCompleted && hasContent) {
      const threadId = new URLSearchParams(window.location.search).get(
        "gist-thread",
      );

      if (!threadId) {
        console.log("MilePoint Bridge: Waiting for Thread ID in URL...");
        return;
      }

      const payload = {
        thread_id: threadId,
        full_transcript: transcript, // Now contains .sources for each entry
        breakdown: getBreakdown(),
        related_suggestions: related,
      };

      console.log("MilePoint Bridge: [PAYLOAD PREPARED]", payload);

      try {
        const response = await fetch(mpData.rest_url, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": mpData.nonce,
            "X-MilePoint-Nonce": mpData.milepoint_nonce,
          },
          body: JSON.stringify(payload),
        });

        console.log(
          `MilePoint Bridge: [SERVER RESPONSE] Status: ${response.status}`,
        );
        const result = await response.json();
        console.log("MilePoint Bridge: [SERVER MESSAGE]", result.message);
      } catch (err) {
        console.error("MilePoint Bridge: [NETWORK ERROR]", err);
      }
    }
  };

  console.log("MilePoint Bridge: Polling started (1s)");
  setInterval(runPoll, pollInterval);
})();
