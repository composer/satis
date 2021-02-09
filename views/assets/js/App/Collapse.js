class Collapse {
  constructor() {
    this.toggleElements = Array.prototype.slice.call(
      document.querySelectorAll('[data-toggle="collapse"]')
    );
    this.collapsibleElements = Array.prototype.slice.call(
      document.querySelectorAll(".collapse")
    );
    this.handleClick = this.handleClick.bind(this);
    this.init();
  }
  handleClick(event) {
    const targetId =
      event.target.dataset.target || event.target.getAttribute("href");
    const targetElement = document.querySelector(targetId);
    if (!targetElement) {
      return false;
    }

    // Collapse this element
    if (targetElement.getAttribute("aria-expanded") === "true") {
      targetElement.setAttribute("aria-expanded", "false");
      targetElement.style.maxHeight = 0;
      return true;
    }

    // Expand this element
    const naturalHeight = parseInt(targetElement.dataset.naturalHeight);
    if (isNaN(naturalHeight) || !naturalHeight) {
      return false;
    }
    targetElement.setAttribute("aria-expanded", "true");
    targetElement.style.maxHeight = naturalHeight + "px";

    return true;
  }
  init() {
    var instance = this;

    // Handle click events on the elements that toggle
    this.toggleElements.forEach(function (element) {
      element.addEventListener("click", function (event) {
        if (instance.handleClick(event)) {
          event.preventDefault();
        }
      });
    });

    // Keep track of the natural heights of the collapsed elements
    this.collapsibleElements.forEach(function (element) {
      const initiallyVisible = element.classList.contains("show");
      element.classList.add("show");

      const naturalHeight = element.getBoundingClientRect().height;
      element.dataset.naturalHeight = naturalHeight;
      element.style.overflow = "hidden";
      element.style.maxHeight = initiallyVisible ? naturalHeight + "px" : 0;
      element.style.transition = "max-height 0.25s";
      element.setAttribute(
        "aria-expanded",
        initiallyVisible ? "true" : "false"
      );
    });
  }
}

export default Collapse;
