class Collapse {
    constructor() {
        const controls = Array.prototype.slice.call(
            document.querySelectorAll("[aria-expanded][aria-controls]"),
        );
        this.collapsibleElements = controls.map((control) => [
            control,
            document.getElementById(control.getAttribute("aria-controls")),
        ]);
        this.handleClick = this.handleClick.bind(this);
        this.init();
    }

    handleClick({ target: control }) {
        const container = document.getElementById(
            control.getAttribute("aria-controls"),
        );
        if (!container) {
            return false;
        }

        // Collapse this element
        if (control.getAttribute("aria-expanded") === "true") {
            control.setAttribute("aria-expanded", "false");
            container.style.maxHeight = 0;
            return true;
        }

        // Expand this element
        const naturalHeight = parseInt(container.dataset.naturalHeight);
        if (isNaN(naturalHeight) || !naturalHeight) {
            return false;
        }
        control.setAttribute("aria-expanded", "true");
        container.hidden = false;

        // Need to use requestAnimationFrame to force the browser to repaint after
        // making the container visible, or it will skip the max-height transition
        window.requestAnimationFrame(function () {
            container.style.maxHeight = naturalHeight + "px";
        });

        return true;
    }

    handleTransitionEnd({ propertyName, target: container }) {
        if (propertyName === "max-height") {
            container.hidden = parseInt(container.style.maxHeight) === 0;
        }
    }

    init() {
        const instance = this;

        this.collapsibleElements.forEach(function ([control, container]) {
            // Handle click events on the elements that toggle
            control.addEventListener("click", function (event) {
                if (instance.handleClick(event)) {
                    event.preventDefault();
                }
            });

            // Keep track of the natural heights of the collapsed elements
            container.addEventListener(
                "transitionend",
                instance.handleTransitionEnd,
            );
            instance.resetNaturalHeight(control, container);
        });

        // Resets container natural heights on window resize
        let timer = null;
        window.addEventListener("resize", function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                instance.collapsibleElements.forEach(function ([
                    control,
                    container,
                ]) {
                    instance.resetNaturalHeight(control, container);
                });
            }, 50);
        });
    }

    resetNaturalHeight(control, container) {
        // Default to fully visible
        container.style.transition = "";
        container.style.maxHeight = "";
        container.style.overflow = "";
        container.hidden = false;

        const initiallyVisible =
            control.getAttribute("aria-expanded") === "true";
        const naturalHeight = container.getBoundingClientRect().height;
        container.dataset.naturalHeight = naturalHeight;

        // Set container dimensions
        container.style.overflow = "hidden";
        container.style.maxHeight = initiallyVisible ? naturalHeight + "px" : 0;
        container.style.transition = "max-height 0.25s";
        container.hidden = !initiallyVisible;
    }
}

export default Collapse;
