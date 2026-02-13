(function () {
  "use strict";

  function triggerSelector(selector) {
    if (!selector) return false;

    var parts = selector.split(",");
    for (var i = 0; i < parts.length; i++) {
      var query = parts[i].trim();
      if (!query) continue;
      var node = document.querySelector(query);
      if (!node) continue;
      node.click();
      return true;
    }

    return false;
  }

  document.addEventListener("click", function (event) {
    var trigger = event.target.closest(".fvd-mbb__item--trigger");
    if (!trigger) return;

    event.preventDefault();

    var selector = trigger.getAttribute("data-fvd-selector") || "";
    var fallback = trigger.getAttribute("data-fvd-fallback") || "";
    var action = trigger.getAttribute("data-fvd-action") || "custom_trigger";
    var provider = trigger.getAttribute("data-fvd-provider") || "custom";

    var handled = triggerSelector(selector);

    if (!handled) {
      document.dispatchEvent(
        new CustomEvent("fvdMbbTrigger", {
          detail: {
            action: action,
            provider: provider,
            selector: selector
          }
        })
      );
    }

    if (!handled && fallback && fallback !== "#") {
      window.location.href = fallback;
    }
  });
})();
