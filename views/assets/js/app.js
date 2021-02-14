import Collapse from "./App/Collapse";
import DateDistance from "./App/DateDistance";
import PackageFilter from "./App/PackageFilter";
import PackageFilterSettings from "./App/PackageFilterSettings";
import ToggleFields from "./App/ToggleFields";
import Settings from "./App/Settings";

function updateTimeElements() {
  DateDistance.calculate("time");
}

document.addEventListener("DOMContentLoaded", function () {
  new Collapse();

  // Filter
  new PackageFilterSettings(
    '[name="default-filter"]',
    ".filter-field",
    ".default-filter-field"
  );
  new PackageFilter("input#search", "#package-list", ".card", ".filter-field");

  new ToggleFields("#toggle-field-all", ".toggle-field");
  new Settings(".settings", ".toggle-settings");
  updateTimeElements();
  window.setInterval(updateTimeElements, 5000);
});
