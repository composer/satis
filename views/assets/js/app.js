import Collapse from './App/Collapse'
import DateDistance from './App/DateDistance'
import PackageFilter from './App/PackageFilter'

function updateTimeElements () {
  DateDistance.calculate('time')
};

document.addEventListener("DOMContentLoaded", function() {
  new Collapse()
  new PackageFilter('input#search', '#package-list', '.card')
  updateTimeElements()
  window.setInterval(updateTimeElements, 5000)
});
