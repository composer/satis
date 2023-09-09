/**
 * Determine which package fields get displayed
 * @class
 */
class ToggleFields {
    /**
     * Bootstraps the class
     * @param {string} showAllButton - Button that checks all checkboxes to show all fields
     * @param {string} checkboxesToToggle - Checkbox inputs that map to fields
     */
    constructor(showAllButton, checkboxesToToggle) {
        this.packageFieldsByFieldName = {};
        this.showAllButton = document.querySelector(showAllButton);
        this.checkboxes = Array.prototype.slice.call(
            document.querySelectorAll(checkboxesToToggle),
        );
        if (this.showAllButton && this.checkboxes.length) {
            this.init();
        }
    }

    /**
     * Prepopulate the checkboxes from storage
     */
    populateCheckboxesFromStorage() {
        let showAllButtonIsDisabled = true;
        const fieldStatus = JSON.parse(
            window.localStorage.getItem("satisFieldStatus") || "{}",
        );
        this.checkboxes.forEach((elem) => {
            if (elem.value in fieldStatus) {
                elem.checked = fieldStatus[elem.value];
                if (false === elem.checked) {
                    showAllButtonIsDisabled = false;
                }
            }
            this.toggleField(elem.value, elem.checked);
        });
        this.showAllButton.disabled = showAllButtonIsDisabled;
    }

    /**
     * Save current status of checkboxes
     */
    saveCheckboxesToStorage() {
        let showAllButtonIsDisabled = true;
        const fieldStatus = {};
        this.checkboxes.forEach((elem) => {
            fieldStatus[elem.value] = elem.checked;
            if (false === elem.checked) {
                showAllButtonIsDisabled = false;
            }
            this.toggleField(elem.value, elem.checked);
        });
        window.localStorage.setItem(
            "satisFieldStatus",
            JSON.stringify(fieldStatus),
        );
        this.showAllButton.disabled = showAllButtonIsDisabled;
    }

    /**
     * Show or hide specific field on all packages
     * @param {string} fieldName - Name of the field to toggle
     * @param {boolean} status - Whether or not to show the field
     */
    toggleField(fieldName, status) {
        this.packageFieldsByFieldName[fieldName].forEach((elem) => {
            if (status) {
                elem.classList.remove("d-none");
            } else {
                elem.classList.add("d-none");
            }
        });
    }

    /**
     * Resets all checkboxes and saves changes to storage
     */
    showAllFields() {
        this.checkboxes.forEach((elem) => {
            elem.checked = true;
        });
        this.saveCheckboxesToStorage();
    }

    /**
     * Sets up event handlers and sets initial values from storage
     */
    init() {
        this.showAllButton.addEventListener("click", () =>
            this.showAllFields(),
        );
        this.checkboxes.forEach((elem) => {
            elem.addEventListener("change", () =>
                this.saveCheckboxesToStorage(),
            );
            this.packageFieldsByFieldName[elem.value] =
                Array.prototype.slice.call(
                    document.querySelectorAll(".field-" + elem.value),
                );
        });
        this.populateCheckboxesFromStorage();
    }
}

export default ToggleFields;
