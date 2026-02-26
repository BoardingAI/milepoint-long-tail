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

      // Build UI to match the carousel card structure
      card.innerHTML = `
                <a href="${sourceMatch.url}" target="_blank" rel="noopener noreferrer" style="text-decoration:none; color:inherit; display:block;">
                    <div class='mp-source-header'>
                        <img src='${sourceMatch.favicon}' class='mp-source-icon' alt=''>
                        <span class='mp-source-site-name'>${sourceMatch.source}</span>
                    </div>
                    <div class='mp-source-title'>${sourceMatch.title}</div>
                    <div class='mp-source-excerpt'>${sourceMatch.excerpt || ""}</div>
                </a>
            `;

      const rect = this.getBoundingClientRect();
      card.style.position = "fixed";
      card.style.left = rect.left + rect.width / 2 + "px";
      card.style.top = rect.top - 12 + "px";
    });

    pill.addEventListener("mouseleave", hideCard);
  });
});
