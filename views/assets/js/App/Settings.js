/**
 * Toggles display of the settings panel
 * @class
 */
class Settings {
    /**
     * Bootstraps the class
     * @param {string} settingsForm - The collapsible form itself
     * @param {string} settingsFormButton - Button that toggles the form
     */
    constructor(settingsForm, settingsFormButton) {
        this.settingsForm = document.querySelector(settingsForm);
        this.settingsFormButton = document.querySelector(settingsFormButton);
        if (this.settingsForm && this.settingsFormButton) {
            this.init();
        }
    }

    /**
     * Sets the active state of the form toggle button
     * @param {MouseEvent} event - Click event on the button
     * @listens MouseEvent
     */
    handleButtonActiveState(event) {
        if (event.target.classList.contains("active")) {
            event.target.classList.remove("active");
            event.target.setAttribute("aria-pressed", "false");
        } else {
            event.target.classList.add("active");
            event.target.setAttribute("aria-pressed", "true");
        }
    }

    /**
     * Sets up event handlers and initial state
     */
    init() {
        this.settingsFormButton.classList.remove("d-none");
        this.settingsFormButton.addEventListener(
            "click",
            this.handleButtonActiveState,
        );
    }
}

export default Settings;
