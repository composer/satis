/**
 * Provides code snippets for your composer.json file
 * for specific releases of packages
 * @class
 */
class CopySnippet {
    /**
     *
     * @param {string} widget - The main node to move around the DOM
     * @param {string} snippetTextField - The text field to display release snippet
     * @param {string} clipboardButton - Copies the snippet to the clipboard
     * @param {string} tooltip - Show after copy to provide user feedback
     * @param {string} releases - All releases for which to create snippets
     */
    constructor(widget, snippetTextField, clipboardButton, tooltip, releases) {
        // Elements
        this.widget = document.querySelector(widget);
        this.snippetTextField = document.querySelector(snippetTextField);
        this.clipboardButton = document.querySelector(clipboardButton);
        this.tooltip = document.querySelector(tooltip);
        this.releases = Array.prototype.slice.call(
            document.querySelectorAll(releases),
        );

        // Vars
        this.currentActiveRelease = null;
        this.tooltipTimeout = 2000;

        // Init
        if (
            this.widget &&
            this.snippetTextField &&
            this.clipboardButton &&
            this.tooltip &&
            this.releases.length
        ) {
            this.init();
        }
    }

    /**
     * Sets snippet to current release
     * @param {MouseEvent} event - User just clicked on a release
     * @listens
     */
    showCodeSnippetToCopy(event) {
        event.preventDefault();

        // Toggle the widget
        if (this.currentActiveRelease === event.target) {
            this.currentActiveRelease.classList.remove("bg-primary");
            this.currentActiveRelease.classList.add("bg-secondary");
            this.currentActiveRelease = null;
            this.widget.classList.add("d-none");
            return;
        }

        // Remove old active release
        if (this.currentActiveRelease) {
            this.currentActiveRelease.classList.remove("bg-primary");
            this.currentActiveRelease.classList.add("bg-secondary");
        }

        // Set current release as active
        this.currentActiveRelease = event.target;
        this.currentActiveRelease.classList.remove("bg-secondary");
        this.currentActiveRelease.classList.add("bg-primary");

        // Build release string to copy to composer.json
        const packageName = event.target.parentNode.dataset.packageName;
        const releaseVersion = event.target.textContent;
        this.snippetTextField.value = `"${packageName}": "${releaseVersion}"`;

        // Make sure the widget is visible and under these releases
        this.widget.classList.remove("d-none");
        event.target.parentNode.appendChild(this.widget);
        this.snippetTextField.focus();
    }

    /**
     * Copies snippet and provide user feedback
     */
    copyCodeSnippetToClipboard() {
        this.snippetTextField.focus();

        // Use Clipboard API to copy snippet
        let updatedClipboardViaAPI = false;
        if ("permissions" in navigator && "clipboard" in navigator) {
            navigator.permissions
                .query({ name: "clipboard-write" })
                .then((result) => {
                    if (result.state == "granted" || result.state == "prompt") {
                        navigator.clipboard.writeText(
                            this.snippetTextField.value,
                        );
                        updatedClipboardViaAPI = true;
                    }
                });
        }

        // Fallback for older browsers
        if (!updatedClipboardViaAPI) {
            if ("clipboardData" in window) {
                window.clipboardData.setData(
                    "Text",
                    this.snippetTextField.value,
                );
            } else {
                document.execCommand("copy");
            }
        }

        this.tooltip.classList.add("show");
    }

    /**
     * Fade the tooltip after a few seconds
     * @param {TransitionEvent} event - CSS property transition for tooltips
     * @listens
     */
    tooltipFadeout(event) {
        if (
            "transform" === event.propertyName &&
            !event.target.style.transform.length
        ) {
            window.setTimeout(() => {
                event.target.classList.remove("show");
            }, this.tooltipTimeout);
        }
    }

    /**
     * Sets up event handlers
     */
    init() {
        this.releases.forEach((elem) =>
            elem.addEventListener("click", (event) =>
                this.showCodeSnippetToCopy(event),
            ),
        );
        this.snippetTextField.addEventListener("focus", (event) =>
            event.target.select(),
        );
        this.clipboardButton.addEventListener("click", () =>
            this.copyCodeSnippetToClipboard(),
        );
        this.tooltip.addEventListener("transitionend", (event) =>
            this.tooltipFadeout(event),
        );
    }
}

export default CopySnippet;
