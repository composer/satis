/**
 * All setting panel funtionality for package filtering
 * @class
 */
class PackageFilterSettings {
    /**
     * Bootstraps settings for package filtering
     * @param {string} defaultFilterBy - The default filter behavior to set on page load
     * @param {string} activeFilter - The last filter the user selected
     * @param {string} useThisField - For when the user wants to choose a specific field
     */
    constructor(defaultFilterBy, activeFilter, useThisField) {
        this.defaultFilterBy = Array.prototype.slice.call(
            document.querySelectorAll(defaultFilterBy),
        );
        this.activeFilter = document.querySelector(activeFilter);
        this.useThisField = document.querySelector(useThisField);
        if (
            this.activeFilter &&
            this.useThisField &&
            this.defaultFilterBy.length
        ) {
            this.initEventHandlers();
            this.initDefaultFields();
        }
    }

    /**
     * Set up event handlers for all form elements related to this component
     */
    initEventHandlers() {
        this.defaultFilterBy.forEach((elem) =>
            elem.addEventListener("change", (event) =>
                window.localStorage.setItem(
                    "satisDefaultFilterBy",
                    event.target.value,
                ),
            ),
        );
        this.useThisField.addEventListener("change", (event) =>
            window.localStorage.setItem(
                "satisUseThisField",
                event.target.value,
            ),
        );
        this.activeFilter.addEventListener("change", (event) =>
            window.localStorage.setItem(
                "satisActiveFilter",
                event.target.value,
            ),
        );
    }

    /**
     * Sets values of all form elements from storage
     */
    initDefaultFields() {
        const defaultFilterBy =
            window.localStorage.getItem("satisDefaultFilterBy") || "all";
        this.defaultFilterBy.forEach(
            (elem) => (elem.checked = defaultFilterBy === elem.value),
        );
        const useThisField = window.localStorage.getItem("satisUseThisField");
        if (useThisField) {
            this.useThisField.value = useThisField;
        }
        if ("recent" === defaultFilterBy) {
            this.activeFilter.value =
                window.localStorage.getItem("satisActiveFilter") || "all";
        }
        if ("custom" === defaultFilterBy && useThisField) {
            this.activeFilter.value = useThisField;
        }
    }
}

export default PackageFilterSettings;
