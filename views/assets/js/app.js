import Collapse from "./App/Collapse";
import DateDistance from "./App/DateDistance";
import PackageFilter from "./App/PackageFilter";
import ToggleFields from "./App/ToggleFields";

function updateTimeElements() {
  DateDistance.calculate("time");
}

document.addEventListener("DOMContentLoaded", function () {
  new Collapse();
  new PackageFilter("input#search", "#package-list", ".card");
  new ToggleFields("#toggle-fields", "#toggle-fields-form", "#toggle-field-all", ".toggle-field");
  updateTimeElements();
  window.setInterval(updateTimeElements, 5000);
});
