import Collapse from "./App/Collapse.js";
import DateDistance from "./App/DateDistance.js";
import PackageFilter from "./App/PackageFilter.js";
import PackageFilterSettings from "./App/PackageFilterSettings.js";
import ToggleFields from "./App/ToggleFields.js";
import Settings from "./App/Settings.js";
import CopySnippet from "./App/CopySnippet.js";

function updateTimeElements() {
    DateDistance.calculate("time");
}

document.addEventListener("DOMContentLoaded", function () {
    new Collapse();

    // Filter
    new PackageFilterSettings(
        '[name="default-filter"]',
        ".filter-field",
        ".default-filter-field",
    );
    new PackageFilter(
        "input#search",
        "#package-list",
        ".card",
        ".filter-field",
    );

    // Copy code snippet from a release for a project's composer.json
    new CopySnippet(
        ".copy-snippet",
        ".copy-snippet__input",
        ".copy-snippet__button",
        ".copy-snippet__tooltip",
        ".field-releases .badge",
    );

    new ToggleFields("#toggle-field-all", ".toggle-field");
    new Settings(".settings", ".toggle-settings");
    updateTimeElements();
    window.setInterval(updateTimeElements, 5000);
});
