document.addEventListener("DOMContentLoaded", function () {
  const input = document.getElementById("mp_openai_api_key");
  const toggleBtn = document.querySelector(".mp-toggle-visibility");
  const copyBtn = document.querySelector(".mp-copy-key");

  if (!input || !toggleBtn) return;

  // Toggle Visibility
  toggleBtn.addEventListener("click", function () {
    const icon = this.querySelector(".dashicons");
    if (input.type === "password") {
      input.type = "text";
      icon.classList.remove("dashicons-visibility");
      icon.classList.add("dashicons-hidden");
    } else {
      input.type = "password";
      icon.classList.remove("dashicons-hidden");
      icon.classList.add("dashicons-visibility");
    }
  });

  // Copy to Clipboard
  copyBtn.addEventListener("click", function () {
    const originalType = input.type;
    input.type = "text"; // Briefly change to text to ensure selection works
    input.select();
    document.execCommand("copy");
    input.type = originalType;

    // Visual feedback
    const icon = this.querySelector(".dashicons");
    icon.classList.remove("dashicons-copy");
    icon.classList.add("dashicons-yes");

    setTimeout(() => {
      icon.classList.remove("dashicons-yes");
      icon.classList.add("dashicons-copy");
    }, 2000);
  });
});
