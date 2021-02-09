/**
 * Determine which package fields get displayed
 * @class
 */
class ToggleFields {
  /**
   * Bootstraps the class
   * @param {string} toggleFieldsForm - The collapsible form itself
   * @param {string} toggleFieldsFormButton - Button that toggles the form
   * @param {string} showAllButton - Button that checks all checkboxes to show all fields
   * @param {string} checkboxesToToggle - Checkbox inputs that map to fields
   */
  constructor(
    toggleFieldsForm,
    toggleFieldsFormButton,
    showAllButton,
    checkboxesToToggle
  ) {
    this.packageFieldsByFieldName = {};
    this.toggleFieldsForm = document.querySelector(toggleFieldsForm);
    this.showAllButton = document.querySelector(showAllButton);
    this.toggleFieldsFormButton = document.querySelector(
      toggleFieldsFormButton
    );
    this.checkboxes = Array.prototype.slice.call(
      document.querySelectorAll(checkboxesToToggle)
    );
    if (
      this.toggleFieldsForm &&
      this.showAllButton &&
      this.toggleFieldsFormButton &&
      this.checkboxes.length
    ) {
      this.init();
    }
  }

  /**
   * Prepopulate the checkboxes from storage
   */
  populateCheckboxesFromStorage() {
    let showAllButtonIsDisabled = true;
    const fieldStatus = JSON.parse(
      window.localStorage.getItem("fieldStatus") || "{}"
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
    window.localStorage.setItem("fieldStatus", JSON.stringify(fieldStatus));
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
   * Resets all checkboxes and saves changes to storage
   */
  handleFormReset() {
    this.checkboxes.forEach((elem) => {
      elem.checked = true;
    });
    this.saveCheckboxesToStorage();
  }

  /**
   * Sets up event handlers and sets initial values from storage
   */
  init() {
    this.toggleFieldsFormButton.classList.remove("d-none");
    this.toggleFieldsFormButton.addEventListener(
      "click",
      this.handleButtonActiveState
    );
    this.toggleFieldsForm.addEventListener("reset", () =>
      this.handleFormReset()
    );
    this.checkboxes.forEach((elem) => {
      elem.addEventListener("change", () => this.saveCheckboxesToStorage());
      this.packageFieldsByFieldName[elem.value] = Array.prototype.slice.call(
        document.querySelectorAll(".field-" + elem.value)
      );
    });
    this.populateCheckboxesFromStorage();
  }
}

export default ToggleFields;
