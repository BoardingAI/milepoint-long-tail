console.log("waiting to load");
document.addEventListener("DOMContentLoaded", function () {
  console.log("loaded");
  const card = document.getElementById("mp-hover-card");
  const contentElement = document.getElementById("mp-qa-content");
  if (!contentElement) {
    console.log("Content element not found");
    return;
  }
  if (!card) {
    console.log("Card not found");
    return;
  }

  console.log("Content element foundm card found");
  // Move card to body for fixed positioning safety
  document.body.appendChild(card);

  // Load data exactly where you specified
  const contentJson = contentElement.textContent;
  console.log(contentJson);
  const qa_content = JSON.parse(contentJson);
  const sources = qa_content[0].sources;
  window.sources = sources; // Keeping for your console access

  let hideTimeout;

  const showCard = () => {
    clearTimeout(hideTimeout);
    card.style.display = "block";
  };

  const hideCard = () => {
    hideTimeout = setTimeout(() => {
      card.style.display = "none";
    }, 300);
  };

  card.addEventListener("mouseenter", showCard);
  card.addEventListener("mouseleave", hideCard);

  const citations = document.querySelectorAll(".gist-chat-citation");

  citations.forEach((pill) => {
    // MATCHING LOGIC: Find source object where source.source matches pill text
    const pillText = pill.textContent.trim();
    const sourceMatch = sources.find((s) => s.source === pillText);

    if (!sourceMatch) return;

    pill.addEventListener("mouseenter", function () {
      showCard();

      // Build UI to match the carousel card structure with DOM APIs (XSS protection)
      card.innerHTML = ""; // Clear previous content

      const link = document.createElement("a");
      // Validate URL protocol
      const safeUrl =
        sourceMatch.url &&
        (sourceMatch.url.startsWith("http://") ||
          sourceMatch.url.startsWith("https://"))
          ? sourceMatch.url
          : "#";
      link.href = safeUrl;
      link.className = "mp-hover-link";
      link.target = "_blank";
      link.rel = "noopener noreferrer";

      // Header
      const header = document.createElement("div");
      header.className = "mp-source-header";

      const icon = document.createElement("img");
      // Basic favicon protocol validation
      const safeFavicon =
        sourceMatch.favicon &&
        (sourceMatch.favicon.startsWith("http://") ||
          sourceMatch.favicon.startsWith("https://"))
          ? sourceMatch.favicon
          : "";
      icon.src = safeFavicon;
      icon.className = "mp-source-icon";
      icon.alt = "";

      const siteName = document.createElement("span");
      siteName.className = "mp-source-site-name";
      siteName.textContent = sourceMatch.source;

      header.appendChild(icon);
      header.appendChild(siteName);

      // Title
      const title = document.createElement("div");
      title.className = "mp-source-title";
      title.textContent = sourceMatch.title;

      // Excerpt
      const excerpt = document.createElement("div");
      excerpt.className = "mp-source-excerpt";
      excerpt.textContent = sourceMatch.excerpt || "";

      link.appendChild(header);
      link.appendChild(title);
      link.appendChild(excerpt);

      card.appendChild(link);

      const rect = this.getBoundingClientRect();
      card.style.position = "fixed";
      card.style.left = rect.left + rect.width / 2 + "px";
      card.style.top = rect.top - 12 + "px";
    });

    pill.addEventListener("mouseleave", hideCard);
  });
});
