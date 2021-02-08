import 'bootstrap/js/dist/collapse'
import DateDistance from './App/DateDistance'
import PackageFilter from './App/PackageFilter'

function updateTimeElements () {
  DateDistance.calculate('time')
};

document.addEventListener("DOMContentLoaded", function() {
  new PackageFilter('input#search', '#package-list', '.card')
  updateTimeElements()
  window.setInterval(updateTimeElements, 5000)
});
